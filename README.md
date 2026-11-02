<p align="center">
  <img src="https://avatars.githubusercontent.com/u/291029189?s=300" width="150" alt="publish logo">
</p>

A Symfony 8.1 REST API service building html pages for Nextcloud Collectives.
It receives build requests and puts them on RabbitMQ for `ssg-worker` to pick up.

## Documentation

The [wiki](https://github.com/nextcloud-publish/publish/wiki) covers both services,
publish and ssg-worker. Its pages live in `docs/wiki/`; CI pushes them to the wiki on every push
to `main`.

- [Home](https://github.com/nextcloud-publish/publish/wiki): what publish is, the technology and the layout of this repository.
- [How it works](https://github.com/nextcloud-publish/publish/wiki/How-it-works): the components, authentication, validation, the build message, retries and callbacks.
- [Deploy publish](https://github.com/nextcloud-publish/publish/wiki/Deploy-publish): run it with Docker Compose, and update it.
- [Troubleshooting](https://github.com/nextcloud-publish/publish/wiki/Troubleshooting): the logs, tracing a build, Sentry.
- [Development](https://github.com/nextcloud-publish/publish/wiki/Development): the dev stack, the tests, PHPStan and releases.
- [API reference](https://nextcloud-publish.github.io/publish/): the endpoints and the status callback, rendered from [`openapi.yaml`](openapi.yaml) on every push to `main`.
