<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/helpers.php';

/**
 * Test dasar untuk fungsi TOTP & password policy.
 * Run: vendor/bin/phpunit tests/Unit/SecurityTest.php
 */
class SecurityTest extends TestCase
{
    public function testValidatePasswordRejectsShort(): void
    {
        $result = validatePassword('ab1');
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidatePasswordRejectsNoDigit(): void
    {
        $result = validatePassword('abcdefgh');
        $this->assertFalse($result['valid']);
    }

    public function testValidatePasswordRejectsNoLetter(): void
    {
        $result = validatePassword('12345678');
        $this->assertFalse($result['valid']);
    }

    public function testValidatePasswordAcceptsValid(): void
    {
        $result = validatePassword('Password123');
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testTotpCodeIs6Digits(): void
    {
        $secret = generateTotpSecret();
        $code = totpCode($secret, time());
        $this->assertSame(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testTotpVerifyAcceptsValidCode(): void
    {
        $secret = generateTotpSecret();
        $code = totpCode($secret, time());
        $this->assertTrue(verifyTotp($secret, $code));
    }

    public function testTotpVerifyRejectsInvalidCode(): void
    {
        $secret = generateTotpSecret();
        $this->assertFalse(verifyTotp($secret, '000000'));
    }

    public function testTotpQrUriContainsSecret(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $uri = totpQrUri($secret, 'Test Issuer', 'user@example.com');
        $this->assertStringContainsString('otpauth://totp/', $uri);
        $this->assertStringContainsString($secret, $uri);
    }

    public function testGenerateTotpSecretIsBase32(): void
    {
        $secret = generateTotpSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function testCsrfTokenGeneration(): void
    {
        // csrf_token() memerlukan session aktif.
        @session_start();
        $token1 = csrf_token();
        $token2 = csrf_token();
        $this->assertSame($token1, $token2);
        $this->assertSame(64, strlen($token1));
        $this->assertTrue(verify_csrf_token($token1));
        $this->assertFalse(verify_csrf_token('invalid'));
    }

    public function testEnvHelperReturnsDefault(): void
    {
        $this->assertSame('default', env('NONEXISTENT_KEY_' . uniqid(), 'default'));
    }

    public function testEnvBoolParsesCorrectly(): void
    {
        putenv('TEST_BOOL_TRUE=1');
        $this->assertTrue(env_bool('TEST_BOOL_TRUE'));
        putenv('TEST_BOOL_FALSE=0');
        $this->assertFalse(env_bool('TEST_BOOL_FALSE'));
    }
}
