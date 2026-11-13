# docker/dev

```text
compose.yaml             dev stack: publish, RabbitMQ, two ssg-workers, collectives-api-mock
.env.example             sample for .env: host ids, TZ
collectives-api-mock/    nginx: content archives and status callbacks on :8082, published/ on :8081
rabbitmq/                broker config and definitions for dev
published/               published sites, written by ssg-worker
build_temp/              running builds
```

- [Develop publish](../../docs/wiki/Develop-publish.md) runs the stack with `collectives-api-mock`.
- [Develop publish with nextcloud-docker-dev](../../docs/wiki/Develop-publish-with-nextcloud-docker-dev.md) connects it to Collectives in a local Nextcloud from nextcloud-docker-dev.
