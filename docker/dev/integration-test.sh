#!/usr/bin/env bash
# End-to-end test of POST /static-sites against the dev stack: publish, publish-worker, Postgres,
# RabbitMQ and collectives-api-mock. Checks every expectation and ends with a summary.
#
#   docker/dev/integration-test.sh            run against the stack as it is
#   docker/dev/integration-test.sh --fresh    first wipe and rebuild the dev stack
#
# --fresh deletes the dev stack's database, queues, docker/dev/pages/ and docker/dev/builds/.
# Run from the publish checkout. DOCKER defaults to "sudo docker"; set DOCKER=docker if your
# user may talk to Docker directly.

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

DOCKER=${DOCKER:-sudo docker}
COMPOSE="$DOCKER compose -f docker/dev/compose.yaml"
API=http://localhost:8080
SITES=http://127.0.0.1:8081
MOCK=http://collectives-api-mock:8082
TOKEN=dev-api-token-0123456789
ARCHIVE=$MOCK/content/sample-collective.tar.gz

# Slugs are unique across all sites, so every run uses its own; the script can run again without --fresh.
RUN=$(date +%s)

PASSED=0
FAILED=0

# --- output -----------------------------------------------------------------

section() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
pass()    { PASSED=$((PASSED + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail()    { FAILED=$((FAILED + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }

# expect <description> <expected> <actual>
expect() {
    if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected '$2', got '$3'"; fi
}

# expect_code <description> <expected code> <post response>: shows the response body when the code differs
expect_code() {
    if [ "${3%% *}" = "$2" ]; then pass "$1"; else fail "$1" "expected $2, got: $3"; fi
}

# expect_contains <description> <needle> <haystack>
expect_contains() {
    case "$3" in
        *"$2"*) pass "$1" ;;
        *) fail "$1" "expected to contain '$2', got '$3'" ;;
    esac
}

# --- helpers ----------------------------------------------------------------

new_site()  { uuidgen; }
new_build() { uuidgen --time-v7; }

# post <site> <build> <slug> [content_url] [callback_url]: prints "<http code> <body>"
post() {
    local body
    body=$(printf '{"data":{"static_site_id":"%s","build_id":"%s","slug":"%s","title":"Test","content_download_url":"%s","callback_status_url":"%s"}}' \
        "$1" "$2" "$3" "${4:-$ARCHIVE}" "${5:-$MOCK/status/$1}")
    curl -sS -o /tmp/integration-test.body -w '%{http_code}' -X POST "$API/static-sites" \
        -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "$body"
    printf ' %s\n' "$(cat /tmp/integration-test.body)"
}

# db <sql>: the result, unaligned, without headers
db() { $COMPOSE exec -T postgres psql -U publish -d publish -tAc "$1"; }

status_of() { db "select status from static_site where id = '$1'"; }

# show_entry <site>: prints the site's static_site entry, after an operation that changes it
show_entry() {
    local row
    row=$(db "select slug || ' | ' || status || ' | build ' || coalesce(build_id::text, '-') from static_site where id = '$1'")
    printf '       \033[2mstatic_site %s: %s\033[0m\n' "$1" "${row:-(no entry)}"
}

# callback_of <site>: the body of the last callback sent to /status/<site>
callback_of() {
    $COMPOSE logs collectives-api-mock 2>/dev/null | grep "CALLBACK PATCH /status/$1 " | tail -1 | sed 's/.*body=//'
}

# wait_for <description> <timeout seconds> <command...>: retries the command until it succeeds
wait_for() {
    local description=$1 timeout=$2 waited=0
    shift 2
    until "$@" >/dev/null 2>&1; do
        if [ "$waited" -ge "$timeout" ]; then
            fail "$description" "still not true after ${timeout}s"
            return 1
        fi
        sleep 2
        waited=$((waited + 2))
    done
    pass "$description (${waited}s)"
}

has_status()   { [ "$(status_of "$1")" = "$2" ]; }
has_no_entry() { [ -z "$(status_of "$1")" ]; }
has_callback() { case "$(callback_of "$1")" in *"\"status\":\"$2\""*) return 0 ;; *) return 1 ;; esac; }

# --- preconditions ----------------------------------------------------------

if ! uuidgen --time-v7 >/dev/null 2>&1; then
    echo "uuidgen --time-v7 is needed (util-linux 2.41 or later)" >&2
    exit 1
fi

if [ "${1:-}" = "--fresh" ]; then
    section "Fresh dev stack"
    $COMPOSE down -v
    $DOCKER run --rm -v "$PWD/docker/dev:/dev-dir" alpine sh -c 'rm -rf /dev-dir/pages/* /dev-dir/builds/*'
    $COMPOSE up -d --build --renew-anon-volumes
fi

section "Stack"
wait_for "publish answers /health" 120 curl -sf "$API/health"
wait_for "the static_site table exists" 60 db "select 1 from static_site limit 1"

# --- A: happy path ----------------------------------------------------------

section "A: a new site is built and published"
A=$(new_site)
A_BUILD=$(new_build)
response=$(post "$A" "$A_BUILD" site-a-$RUN)
expect_code "POST answers 202" 202 "$response"
show_entry "$A"
wait_for "the entry is published" 90 has_status "$A" published
show_entry "$A"
expect "the entry keeps the client's build_id" "$A_BUILD" "$(db "select build_id from static_site where id = '$A'")"
wait_for "Collectives hears published" 30 has_callback "$A" published
expect "the site is served" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$SITES/$A/site-a-$RUN/")"

# --- B: validation and conflicts --------------------------------------------

section "B: validation and conflicts"
response=$(post "$A" "$(new_build)" other-slug-$RUN)
expect_code "the same static_site_id answers 409" 409 "$response"
expect_contains "  … because the site exists" "static site already exists" "$response"

response=$(post "$(new_site)" "$(new_build)" site-a-$RUN)
expect_code "a slug another site holds answers 409" 409 "$response"
expect_contains "  … because the slug is taken" "slug already taken" "$response"

show_entry "$A"
response=$(post "$(new_site)" "$(uuidgen)" site-b-$RUN)
expect_code "a build_id that is no UUIDv7 answers 400" 400 "$response"
expect_contains "  … naming build_id" "build_id must be a UUIDv7" "$response"

expect "the old path /static_sites is gone" 404 \
    "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/static_sites" -H "Authorization: Bearer $TOKEN")"

# --- C: failed build ----------------------------------------------------------

section "C: a failed build leaves nothing behind, and the site can be POSTed again"
C=$(new_site)
response=$(post "$C" "$(new_build)" site-c-$RUN "$MOCK/content/does-not-exist.tar.gz")
expect_code "POST answers 202" 202 "$response"
show_entry "$C"
wait_for "Collectives hears failed" 120 has_callback "$C" failed
show_entry "$C"
expect "the entry is gone" "" "$(status_of "$C")"
expect "nothing is published" "no" "$([ -e "docker/dev/pages/$C" ] && echo yes || echo no)"

response=$(post "$C" "$(new_build)" site-c-$RUN)
expect_code "the same POST works again" 202 "$response"
show_entry "$C"
wait_for "  … and publishes" 90 has_status "$C" published
show_entry "$C"

# --- D: failed callback -------------------------------------------------------

section "D: a build whose callback fails is taken down again"
D=$(new_site)
response=$(post "$D" "$(new_build)" site-d-$RUN "$ARCHIVE" "$MOCK/no-such-endpoint")
expect_code "POST answers 202" 202 "$response"
show_entry "$D"
wait_for "the entry is gone" 120 has_no_entry "$D"
show_entry "$D"
wait_for "the published site is removed" 30 test ! -e "docker/dev/pages/$D"
expect_contains "the worker logged the removal" "Removed the published site" \
    "$($COMPOSE logs publish-worker 2>/dev/null | grep "Removed the published site" | grep "$D" | tail -1)"

# --- summary ----------------------------------------------------------------

section "Summary"
printf '  %d passed, %d failed\n' "$PASSED" "$FAILED"
if [ "$FAILED" -gt 0 ]; then
    printf '\n  Look at: %s logs --tail=80 publish-worker\n' "$COMPOSE"
    exit 1
fi
