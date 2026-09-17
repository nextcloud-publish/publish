# SecurityBundle: what it bought and what it cost

`POST /build` used to be protected by a hand-written class. `ApiTokenCheck` parsed the
`Authorization` header with a regex and compared the result to `PUBLISH_API_TOKEN` with
`hash_equals()`; `BuildController` called it as its first statement and returned a `401`
itself. That was replaced by `symfony/security-bundle`: a stateless firewall using the
built-in `access_token` authenticator, configured in `config/packages/security.yaml`.

The authentication scheme did not change. It is still one shared secret in one
environment variable, still sent as `Authorization: Bearer <token>`, still compared with
`hash_equals()`, and there is still no database. What changed is where the check lives
and, more importantly, what happens when nobody writes one.

## Why we moved

The old design made protection opt-in per controller. The README said so outright:

> an endpoint is protected exactly when its controller asks for `ApiTokenCheck`

That is fail-open. A controller added later is public until somebody remembers to inject
the check, no test covers a route that does not exist yet, and neither `lint:container`
nor code review reliably catches an omission — there is nothing to notice, only something
absent. With callback endpoints on the roadmap, a default of "unprotected unless argued
otherwise" was the wrong way round.

## What we gained

**Deny by default.** This is the whole point; everything below is secondary. One
`access_control` rule (`^/` requires `ROLE_API`) covers every route, so a controller that
forgets to opt in returns `401` instead of serving. Opting *out* now means editing
`security.yaml`, which shows up in a diff. Note the limit: routing runs before the
firewall, so an unregistered path is still a `404`. Deny-by-default protects the routes
that exist, not the URL space.

**Authentication left the controller.** `BuildController` used to carry a comment
explaining that the token had to be checked before `$request->toArray()`, because that
throws `JsonException` on a malformed body and an unauthenticated caller must not be able
to reach it. The firewall answers on `kernel.request`, before the controller is resolved,
so that ordering constraint no longer exists and cannot be reintroduced by someone
reordering statements. The controller is now payload validation and dispatch, nothing
else.

**One place to shape auth failures.** Every `401` comes from the firewall now, so the
RFC 9457 `problem+json` work in `todo.md` has a single seam to hook instead of a
`JsonResponse` repeated in each protected controller.

**An identity instead of a boolean.** The old check answered "trusted or not". The
firewall produces a user (`publish-api`) with a role (`ROLE_API`), which is what makes a
second caller a config change rather than a redesign — per-tenant tokens, a read-only
monitoring token, or a worker callback with different rights.

**Header parsing is upstream's problem.** `HeaderAccessTokenExtractor` replaces our
regex, and `debug:firewall` / `debug:router` now describe the actual policy instead of it
being spread across constructors.

## What it cost

| Cost | Detail |
| --- | --- |
| Dependencies | Four packages: `security-bundle`, `security-core`, `security-http`, `security-csrf`, plus `password-hasher`. `security-csrf` and `password-hasher` are inert here. |
| Platform | `ext-xml` becomes a hard requirement and the PHP floor moves from 8.4 to 8.4.1, both from `symfony/security-bundle`. |
| Config surface | A firewall can fail open in ways a 20-line class cannot — a mis-anchored `pattern`, a rule in the wrong order, a firewall above the one that matters. `lint:container` does not catch any of those; only the tests and a live request do. |
| Sessions | `framework.session` had to be disabled. It was unused, but with `security-csrf` in `vendor/`, leaving it on auto-enables `framework.csrf_protection` — a CSRF token manager in a JSON API with no forms. |
| Code | Two new classes (~60 lines with comments) replace one (~35). The second exists only because `AccessTokenAuthenticator` is not an entry point; see below. |
| Tests | About 60 lines of churn. The 401 cases could no longer be unit tests, because a directly-constructed controller never sees a firewall, so they moved to the functional suite. |

### Why there are two classes, not one

`AccessTokenAuthenticator` implements `AuthenticatorInterface` and nothing else, so no
entry point is registered for the firewall. That splits the two failure modes:

- A request with a **wrong** token reaches the authenticator, `ApiTokenHandler` throws
  `BadCredentialsException`, and the authenticator's own `onAuthenticationFailure()`
  returns the `401`.
- A request with **no parseable credentials** never reaches the authenticator at all. It
  is denied by `access_control`, and `ExceptionListener` looks for an entry point. With
  none configured it calls `throwUnauthorizedException()`, and the caller gets an HTML
  error page from a JSON API.

`BearerEntryPoint` exists to close that second path. It is fifteen lines and it is not
optional.

## Accepted behaviour changes

Three things changed for callers. All were accepted deliberately.

| Change | Before | After |
| --- | --- | --- |
| Scheme casing and token charset | `/^Bearer\s+(\S+)$/i` | `/^Bearer\s+([a-zA-Z0-9\-_\+~\/\.]+=*)$/` — case-sensitive, narrower charset |
| `401` body | `{"status":"unauthorized","error":"..."}` | Empty, with the detail in `WWW-Authenticate` |
| Missing vs. invalid token | Indistinguishable by design | Distinguished, per RFC 6750 §3 |

On the first: `bearer <token>` is now rejected, as is a token containing characters
outside `[A-Za-z0-9-_+~/.]` with optional trailing `=`. `openssl rand -hex 32` — the
generator the README documents — base64 and base64url are all unaffected. This only ever
narrows what authenticates, never widens it, which is why the built-in extractor was
preferred over re-implementing the old regex.

On the second: an empty `401` currently carries `Content-Type: text/html; charset=UTF-8`,
which is a wart. The RFC 9457 exception listener in `todo.md` is where that gets fixed
properly, for every error status at once rather than just this one.

On the third: the old check answered missing and wrong tokens identically so the response
could not tell a caller which half of a guess was right. RFC 6750 puts
`error="invalid_token"` on the invalid case and leaves the bare challenge for the missing
one. That reveals whether the header parsed, not whether the token was close, so it gives
a guesser nothing — and standard clients key off `error="invalid_token"` to decide whether
to refresh a credential.

## What did not change

`hash_equals()` is still the comparison, and it is still guarded against a blank
configured secret (`hash_equals('', '')` is `true`, so blank has to reject explicitly or
it would accept everybody). `PUBLISH_API_TOKEN` still has no default anywhere. `/health`
is still public, and is now exempted by its own `security: false` firewall rather than by
a controller simply not asking for a check — which means a probe carrying a stale token
still gets a `200` instead of a `401`. The `202`/`400`/`503` contract on `/build` is
untouched, and there is still no database and no user store.

One behaviour did shift at the edges: `ApiTokenHandler` is only constructed once a token
has actually been extracted, so an unset `PUBLISH_API_TOKEN` now means `401` for a
credential-less caller and `500` for a real one, where it previously meant `500` for
every request to `/build`. Still closed, still loud.
