Runs publish, RabbitMQ and ssg-worker with Docker Compose on one host, behind a reverse proxy on
that host that terminates TLS.

## 1. Check the requirements

- Docker with Compose v2.
- A reverse proxy on the host, such as nginx, with a TLS certificate for one host name. It serves
  the API at `/` and the published sites at `/pages/`.
- Outbound access from the host to `ghcr.io` for the images, and to the Nextcloud instance:
  ssg-worker downloads content from it and reports each build's status back to it.
- Optional: a GlitchTip or Sentry project per service, and outbound access to it.

## 2. Get the files

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
mkdir -p pages logs/publish logs/ssg-worker
```

```bash
sudo chown -R 10001:10001 pages logs
```

## 3. Fill in `.env`

```bash
cp .env.example .env
```

Set the three required values at the top. The comments in the file say how to generate them;
the optional values below them have their defaults.

## 4. Add the RabbitMQ user

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

## 5. Configure the host proxy

The API goes to `web`, the published sites are files in `pages/`. An nginx example;
any proxy that forwards the `Authorization` header works:

```nginx
server {
    listen 443 ssl;
    server_name publish.example.org;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /pages/ {
        alias /path/to/publish/pages/;
    }
}
```

The proxy's user must be able to read `pages/`. ssg-worker writes it with `0755` for
directories and `0644` for files.

## 6. Start the stack

```bash
docker compose up -d
```

All services should be `running`, `web` and `rabbitmq` also `healthy`:

```bash
docker compose ps
```

## 7. Connect Nextcloud

Configure the Nextcloud side with the API's URL, `https://publish.example.org`, and
`PUBLISH_API_TOKEN`.


## 8. Update

Read the [CHANGELOG](https://github.com/nextcloud-publish/publish/blob/main/CHANGELOG.md) of
the new release first; it lists the steps a release needs by hand, such as a new value in `.env`.

```bash
docker compose pull
```

```bash
docker compose up -d
```

To stay on one release instead of `latest`, set `PUBLISH_IMAGE` and `SSG_WORKER_IMAGE` in `.env`,
e.g. `ghcr.io/nextcloud-publish/publish:1.2.3`.

### Change the RabbitMQ password

Set the new `RABBITMQ_PASSWORD` in `.env`, hash it as in step 4 and replace the hash in
`rabbitmq/definitions.json`. RabbitMQ reads the file only when it starts:

```bash
docker compose down
```

```bash
docker compose up -d
```
