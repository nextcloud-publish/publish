# Getting started

A leaf page: a plain `.md` file sitting next to the collective's `Readme.md`.
It renders to `getting-started/index.html`.

1. Bring the stack up with `docker compose -f docker/dev/compose.yaml up --build`.
2. `POST /build` at the API on port 8080.
3. Watch `q.builds` in the management UI on port 15672.

The rendered site lands under `PUBLISHED_DIR/<slug>` and is served read-only on
port 8081.
