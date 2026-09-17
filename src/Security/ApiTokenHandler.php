<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Checks a bearer token against the shared API token.
 *
 * @param string $apiToken The shared secret from PUBLISH_API_TOKEN, bound in config/services.yaml.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    /** Must match a user in the `api_clients` provider in config/packages/security.yaml, which grants ROLE_API. */
    private const USER_IDENTIFIER = 'publish-api';

    public function __construct(private readonly string $apiToken)
    {
    }

    /**
     * Returns the badge of the API user when $accessToken matches the configured token.
     * A blank configured token matches nothing.
     *
     * @param string $accessToken The token taken from the Authorization header via HeaderAccessTokenExtractor.
     * @return UserBadge the badge for USER_IDENTIFIER, without a user loader
     * @throws BadCredentialsException if the configured token is blank or $accessToken does not match it
     */
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        // hash_equals('', '') is true. AccessTokenAuthenticator never passes an empty token,
        // but the blank check keeps this class correct without relying on that.
        if ('' === $this->apiToken || !hash_equals($this->apiToken, $accessToken)) {
            throw new BadCredentialsException('Invalid API token.');
        }

        return new UserBadge(self::USER_IDENTIFIER);
    }
}
