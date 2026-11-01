# Planning: Connection Troubleshooter (Feature 101)

Closes [#176](https://github.com/acrossaico/acrossai-mcp-manager/issues/176).
Detects [#181](https://github.com/acrossaico/acrossai-mcp-manager/issues/181).
Deliberately excludes [#180](https://github.com/acrossaico/acrossai-mcp-manager/issues/180).

---

## In plain English

When an AI client can't connect, the WordPress admin currently shows **nothing**. The operator
sees only the client's own unhelpful error — "couldn't connect", "couldn't register" — and every
check they can reach says the site is healthy. Because it *is* healthy. Something between the two
is dropping the request, and we give them no way to see it.

This adds one new page, **AcrossAI → Troubleshoot**, with a button that runs a list of checks and
shows each one as pass / warn / fail with a plain-language reason and a fix they can copy and
paste into a hosting ticket.

The important idea is **three vantage points on the same URL**:

| | What it is | Who sees this |
|---|---|---|
| 1. **Inside PHP** | Build the discovery document in-process. No HTTP, no cache, no web server. | Nobody. This is the truth. |
| 2. **Signed-in HTTP fetch** | Fetch the real URL over HTTP carrying credentials. | You, in your browser |
| 3. **Anonymous HTTP fetch** | Fetch the same URL over HTTP with nothing attached. | **The AI client** |

Those three should return the same bytes. When they don't, the difference *names the problem*:

- **1 ≠ 2 and 1 ≠ 3** → the web server or a rewrite rule is mangling it. Everyone is broken.
- **1 = 2 but 1 ≠ 3** → **a page cache is serving a stale copy to anonymous visitors.** This is
  the whole of #181. Page caches almost always bypass signed-in requests, which is exactly why the
  operator's own browser showed the fixed document while the AI client kept getting the hour-old
  one. Comparing a signed-in fetch against an anonymous fetch turns a multi-hour mystery into one
  line of output.
- **all three match** → whatever is wrong is *outside* this server, and we say so honestly rather
  than pretending a green tick means the client can connect.

That third row is the part no existing check can do. It is the reason this feature is worth
building rather than extending the Site Health test we already ship.

### Three real defects this found in our own code

Verified on `main` at `714e4d8`, not taken on trust from the issue:

| | Where | What |
|---|---|---|
| 1 | `includes/OAuth/DiscoveryController.php:255` | The discovery success path sends `Cache-Control: public, max-age=3600` — we are **explicitly asking** every cache layer to keep the document for an hour |
| 2 | `includes/OAuth/AuthorizationController.php:352`, `:598` | Both authorization redirects call `wp_redirect()` bare. Every sibling OAuth path sends `no-store`; these two don't, and the first carries `code=<authorization code>` in its `Location` |
| 3 | `includes/Utilities/CacheHeaders.php:69` | `define_donotcache_constant()` is called from nowhere outside its own class. The discovery documents — the ones that actually got cached on a customer site — never opt out of page caching at all |

So the cache check isn't only reporting on third-party plugins. It reports on us.

---

## Scope

### In

A `?page=acrossai_mcp_troubleshoot` submenu page, admin-only, with a **Run checks** button that
calls one REST route and renders the results. Checks never write state and are safe to re-run
(#176 acceptance criteria; also: they must not consume the DCR rate-limit budget, per #179).

### Out

**The external prober (#180).** It needs a hosted service to run, keep available and secure. Every
check here runs from the site to itself, which structurally cannot see an IP-based block — so the
UA probe reports `warn`, never `fail`, and says so in its own message. That honesty is a
requirement, not a limitation to paper over.

### Open decision — report or repair?

The three defects above are *detected* by the plan as written. Whether this feature also **fixes**
them is a separate call, because it changes the blast radius from "new admin page, no existing
behaviour touched" to "new admin page plus changes to live OAuth response paths". Recorded here so
it is decided rather than drifted into.

---

## The checks

Dependency-ordered. A failed prerequisite marks its dependants `skipped` with the reason, rather
than piling five red rows on one root cause.

| # | Check | Asserts | Issue |
|---|---|---|---|
| 1 | **Server enabled / transport** | at least one MCP server exists, is enabled, and has a resolvable route | #176 |
| 2 | **Rewrite state** | `.well-known/*`, `/authorize`, `/token` resolve — these are rewrite-based and all 404 after a plugin *update* until the version-keyed flush runs (`OAuthRouter.php:62-86` records us shipping exactly that regression once) | #176 |
| 3 | **Anonymous REST** | `rest_url()` answers an unauthenticated request. A security plugin restricting REST to logged-in users blocks DCR before WordPress runs | #176 |
| 4 | **Discovery reachability** | both documents: status, media type, and that the document is **ours** — `DiscoveryHealthCheck::document_is_ours()` already does this | #176 |
| 5 | **Cache staleness** | the three-vantage-point comparison above | **#181** |
| 6 | **Cache-control posture** | what *we* send on those paths, and whether `DONOTCACHEPAGE` is defined — the three defects above | **#181** |
| 7 | **Non-browser User-Agent probe** | fetch our own discovery document four times varying only the UA: `python-httpx/…`, `python-requests/…`, `node`, `Go-http-client/2.0`. **403 or 503 is a block** (403 plain, 503 the challenge interstitial). Claude.ai connects as `python-httpx`, and "block Python" rules ship *enabled by default* in at least one popular firewall plugin | #176 |
| 8 | **Edge / security-layer detection** | presence only, never blame. From response headers (`cf-ray`, `server: cloudflare`, `x-sucuri-*`, `x-wpe-*`, `server: litespeed`) and from `active_plugins` basenames (`wordfence/`, `better-wp-security/`, `sucuri-scanner/`, `wp-cerber/`, `malcare-security/`, `advanced-ip-blocker/`, …) | #176 |
| 9 | **DCR reachability** | `POST …/v1/oauth/register` answers at all — without consuming the registration budget | #176, #179 |
| 10 | **Application Password state** | the basics, so one panel answers "is this site connectable" end to end | #176 |

### Check 5 in detail — the cache comparison

```
A = DiscoveryController rendered in-process          (ground truth)
B = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => 'Basic …' ] ] )
C = wp_remote_get( $url )                            (what the AI client gets)

A == B == C                  → ok
A == B, B != C               → fail: "a page cache is serving a stale copy to
                                anonymous visitors" + which URL + purge instructions
A != B                       → fail: not a cache — the request is being rewritten,
                                redirected or intercepted before it reaches us
```

Corroborating evidence collected alongside, so the verdict cites proof rather than inference:
`cf-cache-status: HIT`, `x-kinsta-cache`, `x-litespeed-cache`, `x-cache`, `x-proxy-cache`, and a
non-zero `age:` header.

The signed-in fetch (B) uses a short-lived Application Password minted for the probe and revoked
immediately, **or** — preferred, and decided at implementation — simply sends the `Authorization`
header, since most page caches bypass on its mere presence and we then never mint anything. The
second option is strictly better if it holds across the cache layers we care about; it needs
checking against LiteSpeed and Kinsta specifically.

### Every failing check yields copy-paste text

A red row saying "blocked" moves nobody forward. Each failure produces text for a hosting ticket
naming:

- Anthropic's **outbound** range `160.79.104.0/21` and `2607:6bc0::/48`
  (the `/23` on [the docs page](https://platform.claude.com/docs/en/api/ip-addresses) is *inbound*
  and is the one people mistakenly allowlist)
- the paths: `/.well-known/oauth-*`, `/authorize`, `/token`,
  `/wp-json/acrossai-mcp-manager/v1/oauth/*`, and the server's MCP route
- the plain reason: AI hosts connect with non-browser user agents, so generic bot rules reject
  them before WordPress runs

Plus a whole-page **support report** — plain text, no secrets — carrying site context, detected
layers and every check with its status.

---

## Shape

Mirrors `novamira/includes/troubleshoot/` (bootstrap / checks / rest / ui / admin / notice), which
is a working implementation of this exact idea, adapted to this plugin's conventions: classes under
`AcrossAI_MCP_Manager\`, hook wiring in `Main.php` only (A1), admin HTML in `admin/Partials/`.

```
admin/Partials/TroubleshootPage.php      submenu registration + page render
includes/Troubleshoot/CheckRunner.php    dependency ordering, skipped-prerequisite logic
includes/Troubleshoot/Result.php         the ok/warn/fail/info/skipped value object
includes/Troubleshoot/Checks/*.php       one class per check
includes/Troubleshoot/SupportReport.php  plain-text report builder
includes/REST/TroubleshootController.php POST /troubleshoot/run-checks, manage_options
src/js/troubleshoot/                     the panel
src/scss/troubleshoot.scss
```

Menu position **9**, directly after Logs (position 8): diagnostics belong next to the record of
what happened.

### Reuse rather than rebuild

| Existing | Used for |
|---|---|
| `includes/OAuth/DiscoveryHealthCheck.php` | loopback fetch, status ranking, `document_is_ours()` |
| `includes/OAuth/DiscoveryConflictGuard.php` | the "walk a loaded class back to its plugin directory and **name it**" pattern |
| `includes/Utilities/CacheHeaders.php` | what the posture check asserts against |
| `includes/Utilities/LocalEnvironment.php` | `is_publicly_reachable()`, and the self-signed-cert relaxation a local site needs or every probe fails on TLS instead of testing anything |
| `includes/OAuth/OAuthRouter.php` | the rewrite-flush state the rewrite check reads |

---

## Speckit Workflow

```
/speckit.git.feature "connection-troubleshooter"
/speckit.specify "<this document>"
/speckit.clarify
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan
/speckit.tasks
/speckit.architecture-guard.governed-tasks
/speckit.security-review.tasks
/speckit.implement
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.analyze
/speckit.memory-md.capture
```

---

## Manual verification

1. **Fake a stale cache.** Serve a doctored `.well-known/oauth-protected-resource` from a cache
   layer (or an nginx `return 200` stub) and confirm check 5 reports `fail`, names the URL, and
   distinguishes it from the "not ours" case in check 4.
2. **Signed-in vs anonymous actually differ.** With a page cache active, confirm B matches A while
   C does not — the asymmetry the check is built on must be real on at least LiteSpeed and one
   host-level cache, not just theorised.
3. **UA probe.** Enable a "block Python user agents" rule and confirm check 7 goes `warn` with the
   four signatures named and the loopback limitation stated in its own message.
4. **Rewrite regression.** Bump `ACROSSAI_MCP_MANAGER_VERSION`, delete the `rewrite_rules` option,
   and confirm check 2 fails *before* someone has to re-save Permalinks.
5. **No budget consumed.** Run the whole panel ten times and confirm the DCR rate limiter is
   untouched (#179).
6. **Checks write nothing.** Diff `wp_options` before and after a full run.
7. **Local-site caveat (B69).** Local by Flywheel's nginx drops `fastcgi_param HTTP_AUTHORIZATION`,
   so the signed-in probe can resolve to user 0 and look broken for reasons unrelated to this code.
   Rule that out before debugging check 5.

---

## Deferred — recorded so it is not rediscovered

- **#180, the external prober.** The only way to prove an IP-based block. Needs a hosted service;
  decided on its own merits.
- **Invalidate on change** (#181). Firing the common purge hooks when a server's route, namespace
  or enabled state changes is a fix, not a diagnostic, and belongs with the repair decision above.
- **Documenting host-level cache exclusion rules** alongside the existing firewall allowlist
  guidance — documentation work, tracked with the repair.
