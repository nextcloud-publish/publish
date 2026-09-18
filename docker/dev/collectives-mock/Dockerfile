# syntax=docker/dockerfile:1

# Stands in for the Nextcloud Collectives content API, and doubles as the
# callback sink and the read-only server for the published tree. Three jobs,
# three ports -- see default.conf.
#
# The real service hands out a tar.gz of a collective's markdown pages, which
# ssg-worker fetches from `content_download_url`. Pointing the dev stack at a
# real Nextcloud would make every local build depend on someone's instance, so
# this serves the same shape from committed fixtures instead.
#
# The archives are built HERE, at image build time, rather than committed: a
# .tar.gz in git is an opaque blob nobody can review or edit. Change a page
# under content/ and rebuild with `docker compose build collectives-mock`.
FROM nginx:alpine

COPY default.conf /etc/nginx/conf.d/default.conf
COPY content /content

# sample-collective.tar.gz -- the happy path.
#
# `-C /content/sample-collective .` keeps the archive rooted at ".", so it
# unpacks as bare page files rather than nesting them under a directory name.
# That matches what SiteRenderer expects: it looks for .md files directly in
# the extracted directory.
#
# not-an-archive.tar.gz -- the retryable failure path. Named .tar.gz but it is
# plain text, so `tar -xzf` in ArchiveExtractor fails with a RuntimeException.
# One URL swap in a build request is then enough to exercise the whole
# retry -> cleanup -> failure callback path, including the delays exchange.
#
# no-pages.tar.gz -- the TERMINAL failure path, which is a different branch: an
# archive that unpacks fine but contains no markdown. SiteRenderer reports that
# as an InvalidArgumentException, so it must be reported on the FIRST delivery
# with no retries and no backoff.
RUN set -eu; \
    tar -czf /usr/share/nginx/html/sample-collective.tar.gz -C /content/sample-collective .; \
    printf 'this is deliberately not a gzip archive\n' > /usr/share/nginx/html/not-an-archive.tar.gz; \
    mkdir -p /tmp/no-pages/notes; \
    printf 'not markdown\n' > /tmp/no-pages/notes/readme.txt; \
    tar -czf /usr/share/nginx/html/no-pages.tar.gz -C /tmp/no-pages .; \
    rm -rf /tmp/no-pages; \
    tar -tzf /usr/share/nginx/html/sample-collective.tar.gz > /dev/null
