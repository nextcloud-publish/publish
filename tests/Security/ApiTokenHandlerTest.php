<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ApiTokenHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Covers App\Security\ApiTokenHandler.
 * Only the token comparison is tested here; HeaderAccessTokenExtractor parses the header, so those cases are in BuildControllerTest.
 */
final class ApiTokenHandlerTest extends TestCase
{
    private const TOKEN = 'test-api-token-0123456789';

    public function testMatchingTokenYieldsTheApiUserBadge(): void
    {
        $badge = (new ApiTokenHandler(self::TOKEN))->getUserBadgeFrom(self::TOKEN);

        // The identifier must exist in the `api_clients` provider in config/packages/security.yaml.
        self::assertSame('publish-api', $badge->getUserIdentifier());
    }

    public function testWrongTokenIsRejected(): void
    {
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler(self::TOKEN))->getUserBadgeFrom('not-the-token');
    }

    public function testATokenThatIsOnlyAPrefixOfTheSecretIsRejected(): void
    {
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler(self::TOKEN))->getUserBadgeFrom(substr(self::TOKEN, 0, -1));
    }

    /** hash_equals('', '') is true, so this fails without the blank-token check in ApiTokenHandler. */
    public function testABlankConfiguredTokenRejectsAnEmptyToken(): void
    {
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler(''))->getUserBadgeFrom('');
    }

    public function testABlankConfiguredTokenRejectsAnyToken(): void
    {
        $this->expectException(BadCredentialsException::class);

        (new ApiTokenHandler(''))->getUserBadgeFrom('anything at all');
    }

    /** PUBLISH_API_TOKEN has no default, so an unset value fails at construction instead of falling back to one. */
    public function testAnUnsetConfiguredTokenIsNotEvenConstructible(): void
    {
        $this->expectException(\TypeError::class);

        // @phpstan-ignore-next-line -- the point of the test is the type error
        new ApiTokenHandler(null);
    }
}
