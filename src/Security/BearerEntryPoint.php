<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The 401 for a request that offered no usable credentials.
 *
 * AccessTokenAuthenticator answers the other 401 -- the one for a token that
 * was extracted but did not match -- and adds `error="invalid_token"` to the
 * challenge. Nothing carries that code here, because there was no token to
 * call invalid: per RFC 6750 §3 a request without credentials gets the bare
 * challenge. Without this class the request would reach
 * ExceptionListener::throwUnauthorizedException() instead and be rendered as
 * an HTML error page.
 */
final class BearerEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // Deliberately no body and no exception message: the challenge header
        // carries everything a caller is entitled to know.
        return new Response(
            null,
            Response::HTTP_UNAUTHORIZED,
            // RFC 7235 requires the challenge on a 401.
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
