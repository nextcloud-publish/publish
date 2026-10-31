<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Logs a bearer token that did not match, with the client address.
 * A request without a token the header parser accepts never reaches an authenticator and is not logged.
 *
 * @param LoggerInterface $logger The app logger.
 */
#[AsEventListener]
final class RejectedTokenLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Logs the rejected token's request at notice level.
     *
     * @param LoginFailureEvent $event The failure the firewall dispatched.
     * @return void
     */
    public function __invoke(LoginFailureEvent $event): void
    {
        // Behind a reverse proxy this is the proxy's address unless framework.trusted_proxies is set.
        $this->logger->notice('Rejected API token', ['ip' => $event->getRequest()->getClientIp()]);
    }
}
