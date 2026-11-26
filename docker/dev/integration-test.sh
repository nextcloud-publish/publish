#!/usr/bin/env bash
# End-to-end test of POST, PATCH and DELETE /static-sites against the dev stack: publish, publish-worker, Postgres,
# RabbitMQ and collectives-api-mock. Checks every expectation and ends with a summary.
#
#   docker/dev/integration-test.sh            run against the stack as it is
#   docker/dev/integration-test.sh --fresh    first wipe and rebuild the dev stack
#
# --fresh deletes the dev stack's database, queues, docker/dev/sites/ and docker/dev/builds/.
# Run from the publish checkout. DOCKER defaults to "docker"; set DOCKER="sudo docker" if your
# user is not in the docker group.

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

DOCKER=${DOCKER:-docker}
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

new_site()  { uuidgen --time-v7; }
new_build() { uuidgen --time-v7; }

# request <method> <path> <data json>: prints "<http code> <body>"
request() {
    curl -sS -o /tmp/integration-test.body -w '%{http_code}' -X "$1" "$API$2" \
        -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "{\"data\":$3}"
    printf ' %s\n' "$(cat /tmp/integration-test.body)"
}

# post <site> <build> <slug> [content_url] [callback_url]
post() {
    request POST /static-sites "$(printf '{"static_site_id":"%s","build_id":"%s","slug":"%s","title":"Test","content_download_url":"%s","callback_status_url":"%s"}' \
        "$1" "$2" "$3" "${4:-$ARCHIVE}" "${5:-$MOCK/status/$1}")"
}

# patch <site> <build> [content_url]
patch() {
    request PATCH "/static-sites/$1" "$(printf '{"build_id":"%s","title":"Test","content_download_url":"%s","callback_status_url":"%s"}' \
        "$2" "${3:-$ARCHIVE}" "$MOCK/status/$1")"
}

# delete <site>
delete() {
    request DELETE "/static-sites/$1" "$(printf '{"callback_status_url":"%s"}' "$MOCK/status/$1")"
}

# served <slug>: the http code of the site's index
served() { curl -s -o /dev/null -w '%{http_code}' "$SITES/$1/"; }

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
# The URL may arrive JSON-escaped (\/), so compare with the backslashes stripped.
has_callback_url() { case "$(callback_of "$1" | tr -d '\\')" in *"$2"*) return 0 ;; *) return 1 ;; esac; }

# --- preconditions ----------------------------------------------------------

if ! uuidgen --time-v7 >/dev/null 2>&1; then
    echo "uuidgen --time-v7 is needed (util-linux 2.41 or later)" >&2
    exit 1
fi

if [ "${1:-}" = "--fresh" ]; then
    section "Fresh dev stack"
    $COMPOSE down -v
    $DOCKER run --rm -v "$PWD/docker/dev:/dev-dir" alpine sh -c 'rm -rf /dev-dir/sites/* /dev-dir/builds/*'
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
expect "the site is served" 200 "$(served site-a-$RUN)"

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

response=$(post "$(uuidgen)" "$(new_build)" site-b-$RUN)
expect_code "a static_site_id that is no UUIDv7 answers 400" 400 "$response"
expect_contains "  … naming static_site_id" "static_site_id must be a UUIDv7" "$response"

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
expect "nothing is published" "no" "$([ -e "docker/dev/sites/site-c-$RUN" ] && echo yes || echo no)"

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
wait_for "the published site is removed" 30 test ! -e "docker/dev/sites/site-d-$RUN"
expect_contains "the worker logged the removal" "Removed the published site" \
    "$($COMPOSE logs publish-worker 2>/dev/null | grep "Removed the published site" | grep "$D" | tail -1)"

# --- E: update ----------------------------------------------------------------

section "E: an update rebuilds the site under its slug"
E_BUILD=$(new_build)
response=$(patch "$A" "$E_BUILD")
expect_code "PATCH answers 202" 202 "$response"
show_entry "$A"
wait_for "the entry is published" 90 has_status "$A" published
show_entry "$A"
expect "the entry holds the update's build_id" "$E_BUILD" "$(db "select build_id from static_site where id = '$A'")"
wait_for "Collectives hears the same URL" 30 has_callback_url "$A" "$SITES/site-a-$RUN/"
expect "the site is served" 200 "$(served site-a-$RUN)"

# --- F: update rejections -----------------------------------------------------

section "F: update rejections"
response=$(patch "$(new_site)" "$(new_build)")
expect_code "an unknown static_site_id answers 404" 404 "$response"
expect_contains "  … because the site is not found" "static site not found" "$response"

response=$(patch "$(uuidgen)" "$(new_build)")
expect_code "a path id that is no UUIDv7 answers 404" 404 "$response"

response=$(patch "$A" "$E_BUILD")
expect_code "a build_id not newer than the stored one answers 409" 409 "$response"
expect_contains "  … because the build_id is not newer" "build_id not newer" "$response"

response=$(patch "$A" "$(new_build)")
expect_code "a first PATCH answers 202" 202 "$response"
response=$(patch "$A" "$(new_build)")
expect_code "a second PATCH while it is pending answers 409" 409 "$response"
expect_contains "  … because an operation is in progress" "operation in progress" "$response"
wait_for "  … and the first is published" 90 has_status "$A" published

# --- G: failed update ---------------------------------------------------------

section "G: a failed update keeps the published site"
response=$(patch "$C" "$(new_build)" "$MOCK/content/does-not-exist.tar.gz")
expect_code "PATCH answers 202" 202 "$response"
wait_for "Collectives hears failed" 120 has_callback "$C" failed
show_entry "$C"
expect "the entry is failed" failed "$(status_of "$C")"
expect "the site is still served" 200 "$(served site-c-$RUN)"

# --- H: delete ----------------------------------------------------------------

section "H: a delete removes the site and its entry"
response=$(delete "$A")
expect_code "DELETE answers 202" 202 "$response"
wait_for "Collectives hears deleted" 90 has_callback "$A" deleted
show_entry "$A"
expect "the entry is gone" "" "$(status_of "$A")"
expect "the site is no longer served" 404 "$(served site-a-$RUN)"
expect "nothing is published" "no" "$([ -e "docker/dev/sites/site-a-$RUN" ] && echo yes || echo no)"

response=$(delete "$A")
expect_code "a second DELETE answers 404" 404 "$response"

# --- summary ----------------------------------------------------------------

section "Summary"
printf '  %d passed, %d failed\n' "$PASSED" "$FAILED"
if [ "$FAILED" -gt 0 ]; then
    printf '\n  Look at: %s logs --tail=80 publish-worker\n' "$COMPOSE"
    exit 1
fi
