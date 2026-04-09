<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use App\Security\JwtTokenManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Single-path framework-level authenticator for the API firewall.
 *
 * Public routes are defined in services.yaml (single source of truth) and
 * mirrored in security.yaml access_control. This authenticator receives
 * the list via constructor injection — no hardcoded route arrays.
 */
class ApiTokenAuthenticator extends AbstractAuthenticator
{
    /** @var string[] */
    private readonly array $publicRoutes;

    /**
     * @param string[] $publicRoutes Injected from services.yaml
     */
    public function __construct(
        private readonly JwtTokenManager $jwtTokenManager,
        private readonly UserRepository $userRepository,
        array $publicRoutes = [],
    ) {
        $this->publicRoutes = $publicRoutes;
    }

    /**
     * @return string[] The configured public routes (for test introspection).
     */
    public function getPublicRoutes(): array
    {
        return $this->publicRoutes;
    }

    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();

        // Public routes: explicitly skip authentication
        foreach ($this->publicRoutes as $publicRoute) {
            if ($path === $publicRoute) {
                return false;
            }
        }

        // Only authenticate /api/ routes
        return str_starts_with($path, '/api/');
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = $request->headers->get('Authorization', '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            throw new AuthenticationException('Missing or invalid Authorization header');
        }

        $jwt = substr($authHeader, 7);

        try {
            $claims = $this->jwtTokenManager->parseAccessToken($jwt);
        } catch (\Throwable) {
            throw new AuthenticationException('Invalid or expired token');
        }

        $userId = $claims['user_id'] ?? null;
        if ($userId === null) {
            throw new AuthenticationException('Invalid token claims');
        }

        return new SelfValidatingPassport(
            new UserBadge($userId, function (string $id) use ($claims) {
                $user = $this->userRepository->find($id);

                if ($user === null || !$user->isActive() || $user->isFrozen()) {
                    throw new AuthenticationException(
                        $user !== null && $user->isFrozen()
                            ? 'Account is frozen'
                            : 'User not found or inactive'
                    );
                }

                // Reject tokens issued before password change
                $tokenIssuedAt = $claims['issued_at'] ?? null;
                $passwordChangedAt = $user->getPasswordChangedAt();
                if ($tokenIssuedAt instanceof \DateTimeImmutable && $passwordChangedAt !== null) {
                    if ($tokenIssuedAt < $passwordChangedAt) {
                        throw new AuthenticationException('Token invalidated by password change');
                    }
                }

                return $user;
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Set the authenticated user on the request for controller consumption.
        // This is the single source of truth — no supplemental listener needed.
        $user = $token->getUser();
        if ($user instanceof \App\Entity\User) {
            $request->attributes->set('authenticated_user', $user);
        }
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'code' => 401,
            'message' => $exception->getMessage(),
            'details' => null,
        ], 401);
    }
}
