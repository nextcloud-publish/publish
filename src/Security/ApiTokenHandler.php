<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Turns the shared secret into the API's single identity.
 *
 * Symfony's HeaderAccessTokenExtractor has already pulled the token out of the
 * `Authorization: Bearer <token>` header by the time this runs, so all that is
 * left here is the comparison. The token comes from PUBLISH_API_TOKEN, wired in
 * config/services.yaml.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    /**
     * Must match a user in the `api_clients` provider in
     * config/packages/security.yaml -- that is where ROLE_API comes from.
     */
    private const USER_IDENTIFIER = 'publish-api';

    public function __construct(private readonly string $apiToken)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        // hash_equals('', '') is true, so a blank configured secret needs its
        // own guard: blank has to authenticate nobody, not everybody.
        if ('' === $this->apiToken || !hash_equals($this->apiToken, $accessToken)) {
            throw new BadCredentialsException('Invalid API token.');
        }

        // No user loader on the badge: AccessTokenAuthenticator fills one in
        // from the firewall's provider, which is what attaches the role.
        return new UserBadge(self::USER_IDENTIFIER);
    }
}
