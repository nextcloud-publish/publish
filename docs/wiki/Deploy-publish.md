Runs publish, RabbitMQ and publish-worker with Docker Compose on one host, behind a reverse proxy on
that host that terminates TLS.

## 0. Check the requirements

- Docker with [Compose v2](https://docs.docker.com/compose/install/).
- A reverse proxy on the host, such as nginx, with a TLS certificate for one host name. It serves
  the API at `/` and the published sites at `/pages/`; step 4 has an
  [nginx example](#4-configure-the-host-proxy).
- Optional: a GlitchTip or Sentry project per service, and outbound access to it.

## 1. Get the files

The images come from GHCR. The host needs five files from the repo's `docker/` folder:

```bash
mkdir -p publish/nginx publish/rabbitmq && cd publish
```

```bash
base=https://raw.githubusercontent.com/nextcloud-publish/publish/main/docker
wget $base/compose.yaml $base/.env.example
wget -P nginx $base/nginx/default.conf
wget -P rabbitmq $base/rabbitmq/rabbitmq.conf $base/rabbitmq/definitions.example.json
```

All later commands run in this folder.

`pages/` holds the published sites, `logs/` the log files. Both app containers run as uid `10001`:

```bash
mkdir -p pages logs/publish logs/publish-worker
```

```bash
sudo chown -R 10001:10001 pages logs
```

## 2. Fill in `.env`

```bash
cp .env.example .env
```

Set the three required values at the top. The comments in the file say how to generate them;
the optional values below them have their defaults.

## 3. Add the RabbitMQ user

RabbitMQ creates its user, queue and exchange from `rabbitmq/definitions.json` at every start.
The file holds a hash of `RABBITMQ_PASSWORD`, not the password. Create it from the sample:

```bash
cp rabbitmq/definitions.example.json rabbitmq/definitions.json
```

Hash `RABBITMQ_PASSWORD` from `.env`:

```bash
docker run --rm rabbitmq:4 rabbitmqctl -q hash_password <RABBITMQ_PASSWORD>
```

Replace `<PASSWORD_HASH>` in `rabbitmq/definitions.json` with the output:

```json
"users": [
  {
    "name": "publish",
    "password_hash": "<PASSWORD_HASH>",
    "hashing_algorithm": "rabbit_password_hashing_sha256",
    "tags": []
  }
]
```

`hash_password` uses RabbitMQ's default, SHA-256, and `hashing_algorithm` must stay
`rabbit_password_hashing_sha256` to match. With another algorithm RabbitMQ starts, but rejects
the password.

The hash is salted, so every run prints a different one; each works.

## 4. Configure the host proxy

The API goes to `nginx-fpm-gateway` on `127.0.0.1:PUBLISH_PORT`, the published sites are files in `pages/`.
nginx example; replace `publish.example.org`, the certificate paths and `/path/to/publish`:

```nginx
server {
  listen 443 ssl http2;
  listen [::]:443 ssl http2;
  server_name publish.example.org;

  ssl_certificate /etc/nginx/certificates/publish.example.org/fullchain.pem;
  ssl_certificate_key /etc/nginx/certificates/publish.example.org/privkey.pem;

  # Build requests are small JSON documents
  client_max_body_size 64k;
  keepalive_timeout 10;

  proxy_set_header X-Forwarded-For    $proxy_add_x_forwarded_for;
  proxy_set_header Host               $host;
  proxy_set_header X-Forwarded-Host   $host;
  proxy_set_header X-Forwarded-Proto  $scheme;
  proxy_set_header X-Real-IP          $remote_addr;
  proxy_http_version 1.1;

  proxy_hide_header Strict-Transport-Security;
  add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
  proxy_hide_header X-Content-Type-Options;
  add_header X-Content-Type-Options "nosniff" always;
  proxy_hide_header X-Frame-Options;
  add_header X-Frame-Options "SAMEORIGIN" always;
  proxy_hide_header Referrer-Policy;
  add_header Referrer-Policy "same-origin" always;

  location /pages/ {
    alias /path/to/publish/pages/;
  }

  # Only the endpoints of publish; scans such as /wp-admin never reach the app
  location ~ ^(/health|/auth-verification|/build)$ {
    proxy_pass http://127.0.0.1:8080;
  }

  location / {
    return 404;
  }
}
```

nginx passes the `Authorization` header on unchanged; another proxy must do the same. A new route
in publish needs a new entry in the endpoints `location`.

The proxy's user must be able to read `pages/`. publish-worker writes it with `0755` for
directories and `0644` for files.

## 5. Start the stack

```bash
docker compose up -d
```

All services should be `running`, `nginx-fpm-gateway` and `rabbitmq` also `healthy`:

```bash
docker compose ps
```

## 6. Connect Nextcloud

In Nextcloud, admin settings → Collectives → "Publish feature":

| Field | Value |
| --- | --- |
| Static site generator URL | `https://publish.example.org` |
| Static site generator token | `PUBLISH_API_TOKEN` from `.env` |

"Test connection" calls `/auth-verification`, which answers `200` only for the right token. A
`401` means the token differs from `PUBLISH_API_TOKEN`, and publish logs `Rejected API token`.


## 7. Update

Read the [CHANGELOG](https://github.com/nextcloud-publish/publish/blob/main/CHANGELOG.md) of
the new release first; it lists the steps a release needs by hand, such as a new value in `.env`.

```bash
docker compose pull
```

```bash
docker compose up -d
```

nginx in `nginx-fpm-gateway` resolves `publish` only when it starts. When `up -d` recreated `publish`, restart
`nginx-fpm-gateway`, or the API answers `502`:

```bash
docker compose restart nginx-fpm-gateway
```

To stay on one release instead of `latest`, set `PUBLISH_IMAGE` and `PUBLISH_WORKER_IMAGE` in `.env`,
e.g. `ghcr.io/nextcloud-publish/publish:1.2.3`. Both images have these tags:

| Tag | Points to |
| --- | --- |
| `latest` | the newest release |
| `1`, `1.2` | the newest release of that major or minor version (no `0` during 0.x) |
| `1.2.3` | one release |
| `main` | the newest build of `main` |
| `<branch>` | the newest build of a pull request from that branch; `/` becomes `-` |
| `sha-<commit>` | the build of one commit |

### Change the RabbitMQ password

Set the new `RABBITMQ_PASSWORD` in `.env`, hash it as in step 3 and replace the hash in
`rabbitmq/definitions.json`. RabbitMQ reads the file only when it starts, and then replaces the
stored password, also in an existing `rabbitmq-data` volume. `down` and `up` recreate every
container, so `nginx-fpm-gateway` also finds the new `publish`:

```bash
docker compose down
```

```bash
docker compose up -d
```
