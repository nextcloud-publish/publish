# Sample Collective

This is the landing page of the fixture collective served by `collectives-mock`
in the dev stack. It stands in for a real Nextcloud Collectives export.

A Collectives export is a directory tree:

- every folder is a page, and its content is that folder's `Readme.md`
- plain `.md` files beside it are leaf sub-pages
- `.attachments.<id>` folders hold that page's inline images

This fixture uses all three so a local build exercises the real rendering paths
rather than a single flat file.

## Editing this

These are plain markdown files in the repo, not a committed `.tar.gz` — the
archive is built inside the image. Change anything here and run:

```
docker compose build collectives-mock
```
