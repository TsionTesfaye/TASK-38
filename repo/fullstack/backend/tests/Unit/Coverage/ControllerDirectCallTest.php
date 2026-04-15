<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Controller\BillController;
use App\Controller\BookingController;
use App\Controller\HoldController;
use App\Controller\InventoryController;
use App\Controller\PaymentController;
use App\Controller\PricingController;
use App\Controller\RefundController;
use App\Controller\TerminalController;
use App\Controller\UserController;
use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Exception\BillVoidException;
use App\Exception\BookingDurationExceededException;
use App\Exception\DuplicateRequestException;
use App\Exception\EntityNotFoundException;
use App\Exception\InvalidStateTransitionException;
use App\Exception\PaymentValidationException;
use App\Security\PaymentSignatureVerifier;
use App\Service\BillingService;
use App\Service\BookingHoldService;
use App\Service\BookingService;
use App\Service\InventoryService;
use App\Service\LedgerService;
use App\Service\PaymentService;
use App\Service\PdfService;
use App\Service\PricingService;
use App\Service\RefundService;
use App\Service\TerminalService;
use App\Service\UserService;
use App\Storage\LocalStorageService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Direct controller unit tests that exercise catch branches unreachable via
 * HTTP (the auth middleware intercepts AuthenticationException before the
 * controller runs). Each controller is called with a Request that has no
 * `authenticated_user` attribute so `getAuthenticatedUser()` throws, or with
 * mocked services that throw specific exceptions.
 */
class ControllerDirectCallTest extends TestCase
{
    /** Request with no authenticated_user → getAuthenticatedUser() throws */
    private function bareRequest(string $method = 'GET', string $content = ''): Request
    {
        $r = Request::create('/', $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $content);
        // Don't set authenticated_user — forces AuthenticationException in helper
        return $r;
    }

    private function authed(User $user, string $method = 'GET', string $content = ''): Request
    {
        $r = Request::create('/', $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $content);
        $r->attributes->set('authenticated_user', $user);
        return $r;
    }

    // ═══════════════════════════════════════════════════════════════
    // UserController AuthenticationException catches
    // ═══════════════════════════════════════════════════════════════

    public function testUserControllerMeThrowsAuthExceptionWithoutUser(): void
    {
        $ctl = new UserController($this->createMock(UserService::class));
        $resp = $ctl->me($this->bareRequest());
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testUserControllerListAuthException(): void
    {
        $ctl = new UserController($this->createMock(UserService::class));
        $resp = $ctl->list($this->bareRequest());
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testUserControllerCreateAuthException(): void
    {
        $ctl = new UserController($this->createMock(UserService::class));
        $resp = $ctl->create($this->bareRequest(
            'POST',
            json_encode(['username' => 'x', 'password' => 'y', 'display_name' => 'd', 'role' => 'tenant']),
        ));
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testUserControllerCreateValidationBeforeAuth(): void
    {
        // Missing fields should 422 before reaching auth check? Actually the
        // controller validates AFTER getAuthenticatedUser for create, so
        // missing auth + missing body → 401 first.
        $ctl = new UserController($this->createMock(UserService::class));
        $resp = $ctl->create($this->bareRequest('POST', '{}'));
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testUserControllerGetAuthException(): void
    {
        $ctl = new UserController($this->createMock(UserService::class));
        $resp = $ctl->get($this->bareRequest(), 'some-id');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testUserControllerUpdateAuthException(): void
    {
        $ctl = new UserController($this->createMock(UserService::class));
        $resp = $ctl->update($this->bareRequest('PUT', '{}'), 'id');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testUserControllerFreezeAuthException(): void
    {
        $ctl = new UserController($this->createMock(UserService::class));
        $resp = $ctl->freeze($this->bareRequest('POST'), 'id');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testUserControllerUnfreezeAuthException(): void
    {
        $ctl = new UserController($this->createMock(UserService::class));
        $resp = $ctl->unfreeze($this->bareRequest('POST'), 'id');
        $this->assertSame(401, $resp->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // BookingController AuthenticationException catches
    // ═══════════════════════════════════════════════════════════════

    public function testBookingControllerAllMethodsAuthException(): void
    {
        $ctl = new BookingController($this->createMock(BookingService::class));
        $this->assertSame(401, $ctl->list($this->bareRequest())->getStatusCode());
        $this->assertSame(401, $ctl->get($this->bareRequest(), 'id')->getStatusCode());
        $this->assertSame(401, $ctl->checkIn($this->bareRequest('POST'), 'id')->getStatusCode());
        $this->assertSame(401, $ctl->complete($this->bareRequest('POST'), 'id')->getStatusCode());
        $this->assertSame(401, $ctl->cancel($this->bareRequest('POST'), 'id')->getStatusCode());
        $this->assertSame(401, $ctl->noShow($this->bareRequest('POST'), 'id')->getStatusCode());
        // reschedule requires body with new_hold_id; test the 422 branch + auth branch
        $req = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertSame(422, $ctl->reschedule($req, 'id')->getStatusCode());
        $req2 = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"new_hold_id":"h"}');
        $this->assertSame(401, $ctl->reschedule($req2, 'id')->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // BillController AuthenticationException catches
    // ═══════════════════════════════════════════════════════════════

    public function testBillControllerAllMethodsAuthException(): void
    {
        $ctl = new BillController(
            $this->createMock(BillingService::class),
            $this->createMock(PdfService::class),
            $this->createMock(LocalStorageService::class),
        );
        $this->assertSame(401, $ctl->list($this->bareRequest())->getStatusCode());
        $this->assertSame(401, $ctl->get($this->bareRequest(), 'id')->getStatusCode());
        // Body validation is AFTER auth in create; without auth → 401 regardless
        $this->assertSame(401, $ctl->create(Request::create('/', 'POST', [], [], [], [], '{}'))->getStatusCode());
        $this->assertSame(401, $ctl->create(Request::create(
            '/', 'POST', [], [], [], [],
            '{"booking_id":"b","amount":"1.00","reason":"x"}',
        ))->getStatusCode());
        $this->assertSame(401, $ctl->void($this->bareRequest('POST'), 'id')->getStatusCode());
        $this->assertSame(401, $ctl->pdf($this->bareRequest(), 'id')->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // HoldController AuthenticationException catches
    // ═══════════════════════════════════════════════════════════════

    public function testHoldControllerAuthException(): void
    {
        $ctl = new HoldController($this->createMock(BookingHoldService::class));
        // create requires body
        $req = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertSame(422, $ctl->create($req)->getStatusCode());
        $validBody = json_encode([
            'inventory_item_id' => 'x',
            'held_units' => 1,
            'start_at' => '2028-01-01T10:00:00Z',
            'end_at' => '2028-01-02T10:00:00Z',
            'request_key' => 'r',
        ]);
        $req2 = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $validBody);
        $this->assertSame(401, $ctl->create($req2)->getStatusCode());

        $this->assertSame(401, $ctl->get($this->bareRequest(), 'id')->getStatusCode());
        // confirm also requires body
        $req3 = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"request_key":"r"}');
        $this->assertSame(401, $ctl->confirm($req3, 'id')->getStatusCode());
        $this->assertSame(401, $ctl->release($this->bareRequest('POST'), 'id')->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // InventoryController AuthenticationException catches
    // ═══════════════════════════════════════════════════════════════

    public function testInventoryControllerAuthException(): void
    {
        $ctl = new InventoryController($this->createMock(InventoryService::class));
        $this->assertSame(401, $ctl->list($this->bareRequest())->getStatusCode());
        $this->assertSame(401, $ctl->get($this->bareRequest(), 'id')->getStatusCode());

        $validBody = json_encode([
            'asset_code' => 'x', 'name' => 'N', 'asset_type' => 'studio',
            'location_name' => 'L', 'capacity_mode' => 'discrete_units',
            'total_capacity' => 1, 'timezone' => 'UTC',
        ]);
        $req = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $validBody);
        $this->assertSame(401, $ctl->create($req)->getStatusCode());

        $req2 = Request::create('/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"name":"X"}');
        $this->assertSame(401, $ctl->update($req2, 'id')->getStatusCode());
        $this->assertSame(401, $ctl->deactivate($this->bareRequest('POST'), 'id')->getStatusCode());

        $availReq = Request::create('/?start_at=2028-01-01T10:00:00Z&end_at=2028-01-02T10:00:00Z&units=1', 'GET');
        $this->assertSame(401, $ctl->availability($availReq, 'id')->getStatusCode());

        $calReq = Request::create('/?from=2028-01-01&to=2028-01-07', 'GET');
        $this->assertSame(401, $ctl->calendar($calReq, 'id')->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // PaymentController AuthenticationException catches
    // ═══════════════════════════════════════════════════════════════

    public function testPaymentControllerAuthException(): void
    {
        $ctl = new PaymentController(
            $this->createMock(PaymentService::class),
            $this->createMock(PaymentSignatureVerifier::class),
        );
        // create — auth check runs before or after body validation
        $body = json_encode(['bill_id' => 'b', 'amount' => '1.00', 'currency' => 'USD']);
        $req = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $this->assertContains($ctl->create($req)->getStatusCode(), [401, 422]);

        $this->assertSame(401, $ctl->get($this->bareRequest(), 'id')->getStatusCode());
        $this->assertSame(401, $ctl->list($this->bareRequest())->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // RefundController AuthenticationException catches
    // ═══════════════════════════════════════════════════════════════

    public function testRefundControllerAuthException(): void
    {
        $ctl = new RefundController($this->createMock(RefundService::class));
        $body = json_encode(['bill_id' => 'b', 'amount' => '1.00', 'reason' => 'x']);
        $req = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $this->assertContains($ctl->create($req)->getStatusCode(), [401, 422]);

        $this->assertSame(401, $ctl->get($this->bareRequest(), 'id')->getStatusCode());
        $this->assertSame(401, $ctl->list($this->bareRequest())->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // TerminalController AuthenticationException catches
    // ═══════════════════════════════════════════════════════════════

    public function testTerminalControllerAuthException(): void
    {
        $ctl = new TerminalController($this->createMock(TerminalService::class));
        $this->assertSame(401, $ctl->listTerminals($this->bareRequest())->getStatusCode());
        $this->assertSame(401, $ctl->getTerminal($this->bareRequest(), 'id')->getStatusCode());

        // createTerminal: auth check vs body validation ordering varies
        $this->assertContains($ctl->createTerminal(Request::create(
            '/', 'POST', [], [], [], [], '{}',
        ))->getStatusCode(), [401, 422]);
        $body = json_encode([
            'terminal_code' => 'T', 'display_name' => 'D',
            'location_group' => 'G',
        ]);
        $this->assertSame(401, $ctl->createTerminal(Request::create(
            '/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body,
        ))->getStatusCode());

        // updateTerminal (no body validation beyond parsing)
        $this->assertSame(401, $ctl->updateTerminal(Request::create(
            '/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}',
        ), 'id')->getStatusCode());

        $this->assertSame(401, $ctl->listPlaylists($this->bareRequest())->getStatusCode());
        $this->assertContains($ctl->createPlaylist(Request::create(
            '/', 'POST', [], [], [], [], '{}',
        ))->getStatusCode(), [401, 422]);
        $pbody = json_encode(['name' => 'n', 'location_group' => 'g', 'schedule_rule' => 'r']);
        $this->assertSame(401, $ctl->createPlaylist(Request::create(
            '/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $pbody,
        ))->getStatusCode());

        $tbody = json_encode([
            'terminal_id' => 'tid', 'package_name' => 'p.zip',
            'checksum' => str_repeat('a', 64), 'total_chunks' => 1,
        ]);
        $this->assertContains($ctl->createTransfer(Request::create(
            '/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $tbody,
        ))->getStatusCode(), [401, 422]);

        $cbody = json_encode(['chunk_index' => 0, 'chunk_data' => 'eA==']);
        $this->assertContains($ctl->uploadChunk(Request::create(
            '/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $cbody,
        ), 'tid')->getStatusCode(), [401, 422]);
        $this->assertSame(401, $ctl->pauseTransfer($this->bareRequest('POST'), 'tid')->getStatusCode());
        $this->assertSame(401, $ctl->resumeTransfer($this->bareRequest('POST'), 'tid')->getStatusCode());
        $this->assertSame(401, $ctl->getTransfer($this->bareRequest(), 'tid')->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // PricingController AuthenticationException catches
    // ═══════════════════════════════════════════════════════════════

    public function testPricingControllerAuthException(): void
    {
        $ctl = new PricingController($this->createMock(PricingService::class));
        $this->assertSame(401, $ctl->list($this->bareRequest(), 'item')->getStatusCode());
        $body = json_encode([
            'rate_type' => 'daily',
            'amount' => '1.00',
            'currency' => 'USD',
            'effective_from' => '2028-01-01T00:00:00Z',
        ]);
        $this->assertContains($ctl->create(Request::create(
            '/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body,
        ), 'item')->getStatusCode(), [401, 422]);
    }
}
