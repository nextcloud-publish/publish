# docker

```text
compose.yaml   production sample: nginx, publish (PHP-FPM), RabbitMQ, ssg-worker
.env.example   settings for compose.yaml
Dockerfile     the publish image, built from the repo root
nginx/         nginx config, shared with the dev stack
php/           PHP and PHP-FPM config for the image
rabbitmq/      broker config and definitions sample
dev/           dev stack
```

- [Deploy publish](../docs/wiki/Deploy-publish.md) sets up `compose.yaml` on a server.
- [Develop publish](../docs/wiki/Develop-publish.md) runs the dev stack in `dev/`.
