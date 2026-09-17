<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ApiTokenHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Unit tests for the token comparison.
 *
 * Only the comparison lives here. Pulling the token out of the Authorization
 * header is Symfony's HeaderAccessTokenExtractor's job now, so the cases that
 * used to cover header shapes (missing header, bare token, wrong scheme) are
 * functional tests in BuildControllerTest instead -- there is no seam for them
 * at this level.
 */
final class ApiTokenHandlerTest extends TestCase
{
    private const TOKEN = 'test-api-token-0123456789';

    public function testMatchingTokenYieldsTheApiUserBadge(): void
    {
        $badge = (new ApiTokenHandler(self::TOKEN))->getUserBadgeFrom(self::TOKEN);

        // The identifier has to resolve in the `api_clients` provider in
        // config/packages/security.yaml, which is what carries ROLE_API.
        self::assertSame('publish-api', $badge->getUserIdentifier());
    }

    public function testWrongTokenIsRejected(): void
    {
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler(self::TOKEN))->getUserBadgeFrom('not-the-token');
    }

    public function testATokenThatIsOnlyAPrefixOfTheSecretIsRejected(): void
    {
        // hash_equals compares the whole string, not a prefix.
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler(self::TOKEN))->getUserBadgeFrom(substr(self::TOKEN, 0, -1));
    }

    public function testABlankConfiguredTokenRejectsAnEmptyToken(): void
    {
        // hash_equals('', '') is true, so without an explicit guard a blank
        // PUBLISH_API_TOKEN would authenticate everybody rather than nobody.
        // This is the regression test for that guard.
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler(''))->getUserBadgeFrom('');
    }

    public function testABlankConfiguredTokenRejectsAnyToken(): void
    {
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler(''))->getUserBadgeFrom('anything at all');
    }

    public function testAnUnsetConfiguredTokenIsNotEvenConstructible(): void
    {
        // PUBLISH_API_TOKEN has no default anywhere, so an unset deployment
        // cannot quietly fall back to something -- it fails loudly.
        $this->expectException(\TypeError::class);

        // @phpstan-ignore-next-line -- the point of the test is the type error
        new ApiTokenHandler(null);
    }
}
