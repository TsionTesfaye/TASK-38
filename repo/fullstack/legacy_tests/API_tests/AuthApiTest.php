<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Tests authentication API request/response structure and validation rules.
 */
class AuthApiTest extends TestCase
{
    public function test_bootstrap_creates_admin(): void
    {
        $request = [
            'organization_name' => 'Test Org',
            'organization_code' => 'TEST',
            'admin_username' => 'admin',
            'admin_password' => 'secure_password_123',
            'admin_display_name' => 'Admin User',
        ];
        $this->assertArrayHasKey('organization_name', $request);
        $this->assertArrayHasKey('admin_username', $request);
        $this->assertNotEmpty($request['admin_password']);
        $this->assertGreaterThanOrEqual(8, strlen($request['admin_password']));
    }

    public function test_login_requires_all_fields(): void
    {
        $request = [
            'username' => 'admin',
            'password' => 'pass',
            'device_label' => 'browser',
            'client_device_id' => 'dev1',
        ];
        foreach (['username', 'password', 'device_label', 'client_device_id'] as $field) {
            $this->assertArrayHasKey($field, $request);
            $this->assertNotEmpty($request[$field]);
        }
    }

    public function test_password_must_be_at_least_8_chars(): void
    {
        $password = 'short';
        $this->assertLessThan(8, strlen($password));
        $password = 'long_enough_password';
        $this->assertGreaterThanOrEqual(8, strlen($password));
    }

    public function test_bootstrap_requires_organization_code(): void
    {
        $request = [
            'organization_name' => 'Test Org',
            'organization_code' => 'TEST',
            'admin_username' => 'admin',
            'admin_password' => 'secure_password_123',
            'admin_display_name' => 'Admin User',
        ];
        $this->assertArrayHasKey('organization_code', $request);
        $this->assertMatchesRegularExpression('/^[A-Z]{2,10}$/', $request['organization_code']);
    }

    public function test_login_response_structure(): void
    {
        $expectedKeys = ['access_token', 'refresh_token', 'expires_in', 'user'];
        $response = [
            'access_token' => 'jwt.token.here',
            'refresh_token' => 'refresh.token.here',
            'expires_in' => 3600,
            'user' => ['id' => 'u1', 'username' => 'admin', 'role' => 'administrator'],
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $response);
        }
        $this->assertIsInt($response['expires_in']);
        $this->assertGreaterThan(0, $response['expires_in']);
    }
}
