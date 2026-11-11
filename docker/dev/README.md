# Running the full stack locally

This is for wiring a real Nextcloud + Collectives into the `publish`/`ssg-worker` dev stack,
instead of the built-in `collectives-mock`. See the
[Development wiki page](https://github.com/nextcloud-publish/publish/wiki/Development) for the
`publish`-only stack (with the mock).

## 1. Start publish + ssg-worker

From `publish/`, with `ssg-worker/` checked out as a sibling (see the main
[README](../../README.md)):

```bash
docker compose -f docker/dev/compose.yaml up -d --build
```

## 2. Start Nextcloud with Collectives

Nextcloud itself is run via
[nextcloud/nextcloud-docker-dev](https://github.com/nextcloud/nextcloud-docker-dev); see that
repo's own README for how to bring it up. Check out
[nextcloud/collectives](https://github.com/nextcloud/collectives) into its
`workspace/server/apps-extra/collectives`, then enable the app from Nextcloud's app store.

## 3. Configure the publish feature in Collectives

In Nextcloud admin settings → Collectives → "Publish feature":

| Field | Value |
| --- | --- |
| Static site generator URL | `http://web:8080` |
| Static site generator token | `dev-api-token-0123456789` (from `compose.yaml`'s `PUBLISH_API_TOKEN`) |

## 4. Connect the two Docker networks

The two stacks run on separate Compose networks (`publish-dev_default` and whatever
`nextcloud-docker-dev` uses, typically `master_default`), so containers can't reach each other by
name by default. Connect them manually:

```bash
# Nextcloud -> publish-api (needed for "Test connection")
docker network connect publish-dev_default master-nextcloud-1

# both ssg-worker replicas -> Nextcloud (content download + status callback)
docker network connect master_default publish-dev-ssg-worker-1
docker network connect master_default publish-dev-ssg-worker-2
```

Adjust container/network names if yours differ (`docker ps`, `docker network ls`).

**This does not persist.** Redo the relevant command(s) whenever a container gets recreated, e.g.
after `docker compose down` or `up --build` on either stack. Already-connected containers return
a harmless `endpoint ... already exists` error — safe to ignore.

Verify it worked:

```bash
docker exec publish-dev-ssg-worker-1 php -r 'echo gethostbyname("nextcloud.local"), PHP_EOL;'
```

This should print an IP, not the literal string `nextcloud.local`.

## 5. Publish

Use the "Test connection" button, then publish a collective from its page. Watch the logs if
something doesn't show up:

```bash
docker compose -f docker/dev/compose.yaml logs -f ssg-worker publish
```
