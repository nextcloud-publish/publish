# syntax=docker/dockerfile:1

# Mock the Nextcloud Collectives content API in the dev stack.
# - content_download_url: serves sample-collective.tar.gz, built from content/ at image build time
# - callback_status_url: receives status callbacks from ssg-worker
# - published_sites_url: serves the published sites read-only, each on its own port (see default.conf).
FROM nginx:alpine

COPY default.conf /etc/nginx/conf.d/default.conf
COPY content /content

RUN tar -czf /usr/share/nginx/html/sample-collective.tar.gz -C /content/sample-collective .
