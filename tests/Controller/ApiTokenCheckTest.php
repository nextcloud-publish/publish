<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ApiTokenCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for the bearer-token check.
 */
final class ApiTokenCheckTest extends TestCase
{
    private const TOKEN = 'test-api-token-0123456789';

    private static function requestWithHeader(?string $authorization): Request
    {
        $server = $authorization === null ? [] : ['HTTP_AUTHORIZATION' => $authorization];

        return Request::create('/build', 'POST', server: $server);
    }

    private static function check(): ApiTokenCheck
    {
        return new ApiTokenCheck(self::TOKEN);
    }

    public function testAcceptsMatchingBearerToken(): void
    {
        self::assertTrue(
            self::check()->authenticates(self::requestWithHeader('Bearer ' . self::TOKEN)),
        );

        // Accept lower-case
        self::assertTrue(
            self::check()->authenticates(self::requestWithHeader('bearer ' . self::TOKEN)),
        );
    }

    public function testRejectsAWrongToken(): void
    {
        self::assertFalse(
            self::check()->authenticates(self::requestWithHeader('Bearer not-the-configured-token')),
        );
    }

    public function testRejectsMissingAuthorizationHeader(): void
    {
        self::assertFalse(self::check()->authenticates(self::requestWithHeader(null)));
    }

    public function testRejectsABareTokenWithoutTheScheme(): void
    {
        self::assertFalse(self::check()->authenticates(self::requestWithHeader(self::TOKEN)));
    }

    public function testABlankConfiguredTokenAuthenticatesNobody(): void
    {
        // Take care here to REJECT each incoming request as unauthenticated
        // if API token is blank

        $check = new ApiTokenCheck('');

        self::assertFalse($check->authenticates(self::requestWithHeader('Bearer ')));

        // And a blank secret still matches no actual token.
        self::assertFalse($check->authenticates(self::requestWithHeader('Bearer x')));
    }

    public function testAnUnsetConfiguredTokenIsNotEvenConstructible(): void
    {
        $this->expectException(\TypeError::class);

        new ApiTokenCheck(null);
    }
}
