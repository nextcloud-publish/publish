<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Enqueues build jobs on RabbitMQ with publisher confirms.
 *
 * One connection + channel per enqueue (fine at this scale). The connection is
 * opened lazily inside enqueue(), so constructing this service -- and booting the
 * kernel in a WebTestCase -- never touches a broker.
 */
final class AmqpBuildQueue implements BuildQueue
{
    // The broker imports its topology from docker/rabbitmq-config/definitions.json
    // at boot, so the application never declares anything. There are no exchanges:
    // publishing to the default exchange ('') with the routing key set to the queue
    // name delivers straight to that queue. This is just the queue's name.
    private const QUEUE_BUILDS = 'q.builds';

    public function enqueue(array $build): void
    {
        // One connection + channel per enqueue. No topology declaration here --
        // the broker already imported it at boot.
        $conn = $this->amqpConnect();
        $ch = $conn->channel();

        // Publisher confirms: without them, a broker crash between accepting the
        // publish and flushing it silently loses a job we already 202'd.
        $ch->confirm_select();

        // Publish to the default exchange with the queue name as the routing key.
        // delivery_mode = persistent (2) asks the broker to write it to disk,
        // which only actually survives a restart because q.builds is durable too.
        $ch->basic_publish(
            new AMQPMessage(json_encode($build, JSON_UNESCAPED_SLASHES), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]),
            '',
            self::QUEUE_BUILDS,
        );

        // Block (up to 5s) until the broker confirms the publish. Only after this
        // returns is it safe for the caller to answer 202 -- the point of confirms.
        $ch->wait_for_pending_acks(5.0);

        $ch->close();
        $conn->close();
    }

    /**
     * Open a connection to RabbitMQ from the AMQP_DSN environment variable.
     *
     * php-amqplib's constructor takes discrete host/port/user/pass/vhost rather
     * than a URL, so we parse the DSN ourselves. The vhost is the URL path with its
     * leading slash stripped and percent-decoding applied -- the default DSN encodes
     * the "/" vhost as "%2f", which rawurldecode turns back into "/".
     *
     * There is no fallback DSN: an unset variable is a broken deployment, and
     * inventing a default only turns it into a confusing connection error against a
     * host nobody configured.
     */
    private function amqpConnect(): AMQPStreamConnection
    {
        $dsn = getenv('AMQP_DSN');
        if ($dsn === false || $dsn === '') {
            throw new \RuntimeException('AMQP_DSN is not set.');
        }

        $p = parse_url($dsn);
        if ($p === false) {
            throw new \RuntimeException('AMQP_DSN is not a valid URL.');
        }
        $host = $p['host'] ?? 'rabbitmq';
        $port = (int) ($p['port'] ?? 5672);
        $user = $p['user'] ?? 'app';
        $pass = $p['pass'] ?? 'secret';
        $vhost = isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/'
            ? rawurldecode(ltrim($p['path'], '/'))
            : '/';

        // Client heartbeat must match the broker's (10s in rabbitmq.conf) so
        // php-amqplib knows to expect and answer them. Read via === false so that
        // an explicit "0" (disable) is honored rather than treated as falsy.
        $hb = getenv('AMQP_HEARTBEAT');
        $heartbeat = $hb === false ? 10 : (int) $hb;

        // read/write timeout must sit above 2x heartbeat or a quiet socket looks dead.
        $rw = $heartbeat > 0 ? $heartbeat * 2 + 2 : 30;

        // Positional args are opaque, so for reference:
        //   host, port, user, password, vhost,
        //   insist=false, login_method='AMQPLAIN', login_response=null, locale,
        //   connection_timeout=3.0, read_write_timeout=$rw, context=null,
        //   keepalive=true, heartbeat=$heartbeat
        return new AMQPStreamConnection(
            $host, $port, $user, $pass, $vhost,
            false, 'AMQPLAIN', null, 'en_US',
            3.0, $rw, null, true, $heartbeat
        );
    }
}
