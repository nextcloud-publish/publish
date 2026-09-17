# syntax=docker/dockerfile:1

# Stands in for the Nextcloud Collectives content API.
#
# The real service hands out a tar.gz of a collective's markdown pages, which
# ssg-worker fetches from `content_download_url`. Pointing the dev stack at a real
# Nextcloud would make every local build depend on someone's instance, so this
# serves the same shape from committed fixtures instead.
#
# The archives are built HERE, at image build time, rather than committed: a
# .tar.gz in git is an opaque blob nobody can review or edit. Change a page under
# content/ and rebuild with `docker compose build collectives-mock`.
FROM nginx:alpine

COPY default.conf /etc/nginx/conf.d/default.conf
COPY content /content

# sample-collective.tar.gz -- the happy path.
#
# `-C /content/sample-collective .` keeps the archive rooted at ".", so it unpacks
# as bare page files rather than nesting them under a directory name. That matches
# what SiteRenderer expects: it globs *.md directly in the extracted directory.
#
# not-an-archive.tar.gz -- the failure path. Named .tar.gz but it is plain text, so
# `tar -xzf` in ArchiveExtractor fails. One URL swap in a build request is then
# enough to exercise the whole q.build-failures -> quarantine -> callback path.
RUN set -eu; \
    tar -czf /usr/share/nginx/html/sample-collective.tar.gz -C /content/sample-collective .; \
    printf 'this is deliberately not a gzip archive\n' > /usr/share/nginx/html/not-an-archive.tar.gz; \
    tar -tzf /usr/share/nginx/html/sample-collective.tar.gz > /dev/null
