# RabbitMQ configuration

| File | Mounted at | Contains |
| --- | --- | --- |
| `rabbitmq.conf` | `/etc/rabbitmq/conf.d/20-custom.conf` | broker settings: timeouts, alarms, definitions import |
| `definitions.json` | `/etc/rabbitmq/definitions.json` | topology: vhost, user, permissions, exchanges, queues |

## Topology

RabbitMQ imports `definitions.json` at boot (`load_definitions` in `rabbitmq.conf`).
The application does not declare topology itself, with one exception: [delay queues](#delay-queues).

| Object | Type | Purpose |
| --- | --- | --- |
| `q.builds` | quorum queue | pending builds, published by the API and consumed by ssg-worker |
| `delays` | direct exchange | used by Symfony Messenger for delayed retries |

`q.builds` has no exchange or binding of its own.
Publishers use the default exchange with the queue name as routing key.

## Changing definitions.json

A re-import keeps existing objects unchanged.

- **Adding** a queue or exchange: `docker compose -f docker/dev/compose.yaml restart rabbitmq`
- **Changing** an argument of an existing one: recreate the broker without its data with `docker compose -f docker/dev/compose.yaml down -v`

The import is all-or-nothing: one error in the file and the node starts without any topology.
Check the management UI (http://127.0.0.1:15672) after a change.

## The delays exchange

Symfony Messenger needs `delays` when a transport sets `retry_strategy.delay > 0`, as ssg-worker's `builds` transport does.

ssg-worker runs with `auto_setup: false`, so `Connection::setupDelay()` does not declare the exchange, but it still declares its delay queues and binds them to it.
Without `delays` the bind fails with a 404 inside `Worker::ack()` and the consumer stops.

Its type and flags must match what `getDelayExchange()` would declare: `direct` and `durable`.

## Delay queues

Messenger declares its delay queues at runtime, which the `app` user's `configure` permission allows.
They cannot be listed in `definitions.json`, because the delay is part of the name:

- name `delay__q.builds_<ms>_retry` (double underscore, because the transport's exchange name is empty)
- classic queue with `x-message-ttl` and `x-expires`
- dead-letters to the default exchange with `q.builds` as routing key, so an expired message returns to `q.builds`

Do not set `default_queue_type=quorum` on the vhost.
The delay queues would become quorum queues, and a quorum queue cannot be redeclared to renew its `x-expires`.

## The app user

`definitions.json` defines the `app` user, so `RABBITMQ_DEFAULT_USER`/`RABBITMQ_DEFAULT_PASS` are ignored.
Its `password_hash` is a salted SHA-256 of `secret`. Generate a new one with:

```sh
php -r '$s=random_bytes(4);echo base64_encode($s.hash("sha256",$s."<pw>",true));'
```
