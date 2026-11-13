[nextcloud-docker-dev](https://github.com/nextcloud/nextcloud-docker-dev) runs a full Nextcloud
development setup in Docker, with Collectives from your own checkout and branch.

With the publish [dev stack](https://github.com/nextcloud-publish/publish/wiki/Develop-publish)
connected to it over a shared Docker network, you can run a full integration test: a build started in Collectives
goes through publish and ssg-worker, and ssg-worker downloads the content from Nextcloud and reports
the build status back to it.

`collectives-api-mock` still serves the published sites on `http://127.0.0.1:8081/`, as the host
proxy does at `/pages/` in a deployment.

Both stacks join the external network `collectives-dev`, created once with
`docker network create collectives-dev`. Either stack can start first:

| Container | Name on `collectives-dev` | Calls |
| --- | --- | --- |
| nextcloud-docker-dev `nextcloud` | `nextcloud` | `http://publish.local:8080` for "Test connection" and builds |
| nextcloud-docker-dev `proxy` | `nextcloud.local` | -- |
| publish `nginx-fpm-gateway` | `publish.local` | -- |
| publish `ssg-worker` | -- | `http://nextcloud.local` for the content archive and the status callback |

## 1. Setup nextcloud-docker-dev with collectives

Set up nextcloud-docker-dev with Text and Collectives:

```bash
git clone https://github.com/nextcloud/nextcloud-docker-dev.git
cd nextcloud-docker-dev
./bootstrap.sh text collectives
```

Switch Collectives to your branch and build it.

```bash
cd workspace/server/apps-extra/collectives
git switch <branch>
composer install && npm ci && npm run build
cd ../../../..
```

Add a `docker-compose.override.yml` and keep it out of `git status`. It joins `nextcloud` and
`proxy` to `collectives-dev`, and lets the proxy also listen on `::1`: `nextcloud.local` resolves to
`::1` first, and browsers don't fall back to `127.0.0.1`.

```bash
cat > docker-compose.override.yml <<'YAML'
services:
  proxy:
    ports:
      - "[::1]:${PROXY_PORT_HTTP:-80}:80"
    networks:
      collectives-dev:
        aliases:
          - nextcloud${DOMAIN_SUFFIX}
  nextcloud:
    networks:
      default:
      collectives-dev:

networks:
  collectives-dev:
    external: true
YAML
echo /docker-compose.override.yml >> .git/info/exclude
```

Compose reads the override only when no `-f` is given, so run nextcloud-docker-dev commands from its
directory without `-f`. Start it; `docker network create` answers "already exists" when the publish
setup created the network first:

```bash
docker network create collectives-dev
docker compose up -d nextcloud
```

Nextcloud answers on `http://nextcloud.local/`, login `admin` / `admin`.

Log in, then set the publish API in admin settings → Collectives → "Publish feature":

| Field | Value |
| --- | --- |
| Static site generator URL | `http://publish.local:8080` |
| Static site generator token | `dev-api-token-0123456789`, `PUBLISH_API_TOKEN` from `docker/dev/compose.yaml` |

## 2. Setup publish and ssg-worker

Set up and start the dev stack as in
[Develop publish](https://github.com/nextcloud-publish/publish/wiki/Develop-publish#setup). It joins
`collectives-dev` on its own.

## 3. Publish

From nextcloud-docker-dev, this answers `{"status":"ok"}`:

```bash
docker compose exec nextcloud curl -s http://publish.local:8080/health
```

Use "Test connection" in the admin settings, then publish a collective from its page. Inside the
publish project, follow the build:

```bash
docker compose -f docker/dev/compose.yaml logs -f publish ssg-worker
```

A successful build ends with `Published site` and `Reported build status {"status":"published"}`
in the ssg-worker log.
