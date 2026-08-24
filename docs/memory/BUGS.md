# Bug Patterns

## Template
### YYYY-MM-DD - Bug / Failure Pattern
**Status**
Active | Monitored | Retired

**Symptoms**
What was observed?

**Root Cause**
What actually caused it?

**Future mistake prevented**
What change pattern should future work avoid?

**Evidence**
Failing test, production incident, review finding, or verified fix.

**Prevention / Detection**
How should future work avoid it and how can we catch it sooner?

**Where to look next**
Files, modules, logs, or checks maintainers should inspect.

---

### 2026-05-29 — Namespace Resolution Double-Includes in Activator.php

**Status**
Active

**Symptoms**
`class_exists( Includes\Database\MCPServer\Query::class )` inside
`Activator.php` always returns `false`. DB tables are never created at
activation. Activation completes silently with no error.

**Root Cause**
`Activator.php` is in namespace `AcrossAI_MCP_Manager\Includes`. PHP resolves
bare names relative to the current namespace. Writing `Includes\Database\MCPServer\Query`
inside that file produces the FQN `AcrossAI_MCP_Manager\Includes\Includes\Database\MCPServer\Query`
— a double-`Includes` path that resolves to nothing.

**Future mistake prevented**
Any file in `AcrossAI_MCP_Manager\Includes` that references a sub-namespace
class with a bare relative path (starting with `Includes\`) will silently fail.
This is especially dangerous in `class_exists()` checks, which return false
without throwing.

**Evidence**
Caught during `/speckit.plan` Phase 0 research (research.md §5).
Would have caused silent activation failures if deployed.

**Prevention / Detection**
ALWAYS use one of these forms inside any `AcrossAI_MCP_Manager\Includes` file:
- `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;`
  then `class_exists( MCPServerQuery::class )`
- Or: `class_exists( \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::class )`
Run `vendor/bin/phpstan --level=8` — it catches unresolved class references.

**Where to look next**
`includes/Activator.php`, `includes/Main.php`, any file inside
`AcrossAI_MCP_Manager\Includes` that references sibling sub-namespaces.

---

### 2026-05-29 — Uninitialised $this->plugin_name in define_constants()

**Status**
Active (fix applied in Feature 001; pattern to avoid in future)

**Symptoms**
`ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` is defined as empty string / null.
All callers that reference the slug constant get an empty value.

**Root Cause**
`define_constants()` was called BEFORE `$this->plugin_name = 'acrossai-mcp-manager'`
in the constructor. `$this->plugin_name` is null at that point. The `define()`
guard accepted null silently.

**Future mistake prevented**
Never use `$this->property` as the value argument to `$this->define()` in
`define_constants()`. The properties are set AFTER this method returns.

**Evidence**
Found in existing `includes/Main.php`. Fixed in Feature 001 spec (FR-003).

**Prevention / Detection**
Code review: verify `define_constants()` uses only literals and
`ACROSSAI_MCP_MANAGER_PLUGIN_FILE` (defined at file scope before Main::instance()).

**Where to look next**
`includes/Main.php::define_constants()`

---

### 2026-05-29 — Namespace Drift in TODO Stub FQNs [Feature-001]

**Status**
Active

**Symptoms**
`REST\CliController` TODO stub in `includes/Main.php` used namespace `\AcrossAI_MCP_Manager\REST\CliController` — missing `\Includes\` segment. Would fatal on uncomment in Phase 5.

**Root Cause**
Stub FQN not verified against the PSR-4 map in ARCHITECTURE.md before committing. Plan.md also contained the wrong FQN.

**Future mistake prevented**
Always verify every TODO stub FQN against the PSR-4 directory layout before writing it. Wrong-namespace stubs silently compile but fatal at runtime.

**Prevention / Detection**
Cross-check stub FQNs against ARCHITECTURE.md directory layout. PHPStan level 8 catches unresolved class references when the class exists; stubs won't be caught until the class is created.

**Where to look next**
`includes/Main.php` — all TODO stub comments containing `\AcrossAI_MCP_Manager\...` FQNs.

---

### 2026-05-29 — Unescaped Dot in PCRE Rewrite Rules [Feature-001]

**Status**
Active

**Symptoms**
`add_rewrite_rule( '^.well-known/oauth-authorization-server/?$', ... )` matches any character in place of the leading dot — `axwell-known/...` would also match.

**Root Cause**
Inside a PHP single-quoted string passed to `add_rewrite_rule()`, `.` is a bare PCRE wildcard. Must be `\\.` (double-escaped: one `\` escapes the PHP string, leaving `\.` for PCRE).

**Future mistake prevented**
All literal dots in `add_rewrite_rule()` patterns must be `\\.` in single-quoted PHP strings, not `.`.

**Prevention / Detection**
Code review: grep for `add_rewrite_rule` and verify all literal `.` chars are `\\.`.

**Where to look next**
`includes/Activator.php` — all `add_rewrite_rule()` calls.

---

### 2026-05-29 — Public Constructor on Singleton Allows Double Hook Registration [Feature-001]

**Status**
Active

**Symptoms**
External code calls `new \AcrossAI_MCP_Manager\Includes\Main()` directly. All plugin hooks register twice. In Phase 7 this can cause double-fired access-control middleware.

**Root Cause**
`Includes\Main::__construct()` was `public` rather than `private`. The `final` class modifier prevents subclassing but not direct instantiation.

**Future mistake prevented**
Every singleton `__construct()` MUST be `private`. Constitution rule. A `final` class alone is not sufficient protection.

**Prevention / Detection**
PHPCS / code review: all classes with `static $_instance` must have `private function __construct()`.

**Where to look next**
Any new class added to `admin/`, `includes/`, or `public/` with `$_instance`.

---

### 2026-05-29 — Missing esc_url() on admin_url() Output [Feature-001]

**Status**
Active

**Symptoms**
`sprintf('<a href="%sadmin.php?page=%s">', admin_url(), ...)` — `admin_url()` is filterable via the `admin_url` hook. A hijacked filter can return `javascript:alert(1)//`, producing stored XSS in the WP Admin plugins list.

**Root Cause**
`admin_url()` treated as a safe value because it typically returns a URL. It is not safe — it passes through a WordPress filter.

**Future mistake prevented**
Always wrap `admin_url()`, `get_admin_url()`, and similar filter-backed URL functions with `esc_url()` before use in any HTML attribute.

**Prevention / Detection**
PHPCS WPCS escaping sniffs. Code review: search for `admin_url()` in HTML context without `esc_url()` wrapper.

**Where to look next**
`admin/Partials/Menu.php` and any new admin Partials class with `plugin_action_links`.

---

### 2026-06-17 — Mass-Assignment via Forged POST Keys to Custom-Table Writes [Feature-002]

**Status**
Active

**Symptoms**
A handler reads `$_POST` into a `$data` array and passes it to `$wpdb->update($table, $data, $where)` without filtering. A malicious admin (or a non-admin via a forged form that bypasses an upstream cap check) can include extra fields like `is_enabled=1`, `registered_from=plugin`, or future schema additions to write columns the form was never supposed to touch.

**Root Cause**
WP_DB `$wpdb->insert/update/delete` write every key in the `$data` array that corresponds to a column in the table — they do NOT filter against a schema whitelist. Trusting `$_POST` shape means trusting the attacker's shape.

**Future mistake prevented**
When writing to a custom DB table via a Query class, MUST iterate `Schema::columns()` (or an equivalent allow-list) and drop any unknown keys BEFORE the `$wpdb` call. Never pass raw `$data` from `$_POST` straight to `$wpdb->update/insert`.

**Prevention / Detection**
Canonical implementation pattern (see Feature 002):
```php
// In Query::update_item / add_item:
foreach ( $data as $col => $value ) {
    if ( ! $schema->has_column( (string) $col ) ) {
        continue; // silent drop
    }
    $update[ $col ] = ...;
}
```
Test: in a manual security test, submit a form with extra `<input name="is_enabled" value="1">` against the Claude Connector save handler. After submission, query the DB — the row's `is_enabled` MUST be unchanged.

**Where to look next**
`includes/Database/MCPServer/Query.php::add_item/update_item` for the canonical reference. Any future custom-table Query class MUST follow the same pattern.

---

### 2026-06-17 — "// esc_url'd above" Comment Pattern Is Fragile [Feature-002]

**Status**
Active

**Symptoms**
A form action attribute reads `<?php echo $post_url; // esc_url'd above ?>`. The escape was applied 10 lines earlier when `$post_url = esc_url( add_query_arg(...) )` was assigned. Currently safe, but a future refactor that renames `$post_url`, moves the assignment, swaps the assignment for an unescaped value, or copy-pastes the echo line into a new render method silently breaks the escape — XSS reintroduced with no audit trail. PHPCS WPCS escaping sniffs may or may not see across the variable assignment.

**Root Cause**
The comment is documentation, not enforcement. The reviewer / linter / future author has no signal at the **output point** that the value is pre-escaped. Defense in depth fails because there's only one defense.

**Future mistake prevented**
Even when a value was already escaped at assignment time, re-escape it at the output point. `esc_url()`, `esc_attr()`, `esc_html()` are all idempotent — calling them twice is cheap. The cost of being explicit is one function call; the cost of a silent regression is XSS.

**Prevention / Detection**
At output sites, always write `<?php echo esc_url( $post_url ); ?>` (or `esc_attr`, `esc_html`, etc.) — never bare `<?php echo $foo; ?>` with a "// already escaped" comment. PHPCS configurations should enable strict escaping rules at output sites regardless of upstream escaping. Pairs with B6 (admin_url XSS) for full coverage.

**Where to look next**
`admin/Partials/Settings.php` — every `<?php echo $post_url ?>` site has been hardened to `<?php echo esc_url( $post_url ); ?>` as of SEC-S2 (2026-06-17). Future render methods should follow that pattern.

---

### 2026-06-18 — PHPUnit 13+ `@dataProvider` Annotation Silently Fails [Feature-004]

**Status**
Active

**Symptoms**
A test method using `@dataProvider providerMethod` annotation throws `ArgumentCountError: Too few arguments to function ... 0 passed ... and exactly N expected` at runtime. The annotation is silently ignored by PHPUnit 10+ (and definitively in PHPUnit 13); the test runs once with no arguments instead of N times with provider data.

**Root Cause**
PHPUnit 10 deprecated annotation-style metadata in favor of native PHP attributes; PHPUnit 13 removed annotation support entirely (still parses the docblock but doesn't bind the provider). The migration is silent — no deprecation warning at test-load time.

**Future mistake prevented**
When writing PHPUnit tests in this repo (currently 13.2-dev per `composer.json`), use PHP attributes, NOT docblock annotations:

```php
// WRONG (PHPUnit 13 silently ignores)
/**
 * @dataProvider providerMethod
 */
public function testFoo( string $a, string $b ): void { ... }

// CORRECT
use PHPUnit\Framework\Attributes\DataProvider;
#[DataProvider('providerMethod')]
public function testFoo( string $a, string $b ): void { ... }
```

The same applies to `@depends`, `@group`, `@test`, etc. — every annotation has an attribute equivalent in `PHPUnit\Framework\Attributes\*`.

**Prevention / Detection**
PHPUnit's `--display-warnings` flag DOES surface this if enabled; otherwise the test silently fails with `ArgumentCountError`. Code review: search for `@dataProvider` (or any `@` test annotation) in new test files.

**Where to look next**
`tests/phpunit/MCPClients/AbstractMCPClientTest.php` for the canonical `#[DataProvider]` pattern + the `use PHPUnit\Framework\Attributes\DataProvider;` import.

---

### 2026-06-25 — Check-Then-Act on One-Shot Credentials MUST Use Atomic CAS [Feature-005]

**Status**
Active

**Symptoms**
Two concurrent token-redemption requests with the same auth code both succeed: both issue access tokens, defeating the anti-replay guarantee. The "code already redeemed" check from the second request reads stale data because the first request hasn't yet flipped the `completed_at` flag.

**Root Cause**
The original FR-013 wrote `SELECT … WHERE redeemed_at IS NULL` followed by `UPDATE … SET redeemed_at = NOW()`. Under concurrent requests both SELECTs return NULL at T0 (predicate evaluates BEFORE either UPDATE), then both UPDATEs flip the flag at T1 — both pass their internal "not redeemed yet" check, both issue tokens. The same SELECT-then-UPDATE race exists in ANY DB-backed one-shot credential (auth codes, magic links, password-reset tokens, single-use coupons) and is silently exploitable under concurrent load.

**Fix Pattern (B10)**
The redemption step MUST be a single SQL statement of the form:

```sql
UPDATE <table>
SET completed_at = NOW()
WHERE id = :id AND completed_at IS NULL
```

Then inspect `$wpdb->rows_affected`:
- `1` → THIS request won the CAS, proceed with the privileged side effect
- `0` → another request already redeemed; fall through to the REPLAY branch (revoke any tokens issued by the sibling winner; return error; audit the replay attempt)

A `SELECT … WHERE completed_at IS NULL; if (! null) { UPDATE … }` pattern is NEVER acceptable for one-shot credentials, regardless of how short the window between SELECT and UPDATE is.

**Prevention / Detection**
- Code review gate: every new one-shot-credential redemption MUST include a concurrent-redeem PHPUnit test that runs the redemption N times against the SAME credential and asserts exactly ONE returns success (Phase 5 T054 `ConcurrentRedeemRaceTest` is the canonical shape).
- Audit gate: ANY token issued by the winner of an inverted CAS (the rare case where the loser's request issues a duplicate via a subsequent step) MUST be revoked in the replay branch.
- Grep gate: search for `SELECT.*WHERE.*completed_at IS NULL` followed by `UPDATE` — that's the regression pattern.

**Where to look next**
`includes/Database/CliAuthLog/Query.php::redeem_atomic` — the canonical CAS implementation. `includes/OAuth/Storage.php::redeem_authorization_code_cas` + `revoke_all_tokens_for_code` — the orchestration. `tests/phpunit/OAuth/ConcurrentRedeemRaceTest.php` — the load-bearing race test. `specs/005-oauth-connectors/spec.md` Q4 (SEC-001 amendment 2026-06-21) for the threat model.

---

### 2026-06-25 — Transient-Stored Associative Arrays Need Defensive Triple-Check on Read [Feature-006]

**Status**
Active

**Symptoms**
A read of a WordPress transient that's expected to hold an associative array (e.g. `array{user_id: int, server_id: string}`) returns the wrong shape and the calling code silently misbehaves — most often by reading `null` from a missing key, then comparing it to a real value with `===` (which returns false silently). Common failure modes:

- Object cache eviction during a partial write leaves the key set to `false` or to a different value-type than expected.
- A bug elsewhere in the code writes a bare `int` to the same key (e.g., during a refactor that changed the payload shape — this WAS the bug Q4 fixed).
- A transient TTL expires between two reads in the same request lifecycle.

**Root Cause**
PHP's `get_transient()` returns `false` on miss but ALSO returns `false` if the stored value is literally `false`. Combined with `isset()`'s lax "is the key set?" semantics (which returns `true` for an empty string `''`), naive single-line checks like `if ( false === $payload || ! is_numeric( $payload ) )` silently accept malformed data.

**Bug Pattern (B11)**
When reading a transient whose value is expected to be an associative array, use this triple-check pattern verbatim:

```php
$payload = get_transient( self::SOME_PREFIX . $key );
if ( ! is_array( $payload )                                            // catches false, scalars, objects
     || ! isset( $payload['expected_key_a'], $payload['expected_key_b'] )  // both keys present
     || ! is_numeric( $payload['expected_key_a'] )                     // value-type check for known-typed fields
) {
    return new \WP_Error( 'rest_unauthorized', '...', array( 'status' => 401 ) );
    // OR: return false from a static helper; OR: 404 from a polling endpoint
}
```

For transients with `array{key: int}` shape, use `is_numeric()` on the int field (catches strings that LOOK like ints AND real ints — WP transient storage strips int type on the wp_options fallback path). For `array{key: string}` fields, no additional check needed beyond `isset()`.

**Prevention**
- Code review gate: every `get_transient()` call that's expected to return an array MUST be followed by the triple-check.
- Static analysis: PHPStan L8 catches some shape mismatches but NOT runtime transient corruption — the triple-check is the runtime guard.
- Phase 5's `BearerAuth::resolve_bearer_token` (`includes/OAuth/BearerAuth.php`) reads a bare int value and uses a 2-line guard (`false === $user_id || ! is_numeric($user_id)`) — that's CORRECT for the bare-int shape. Phase 6's `verify_session_token` reads an array and uses the triple-check — that's the array-shape equivalent.

**Where to look next**
- Canonical implementation: `includes/REST/CliController.php::verify_session_token` (Phase 6) and `::handle_auth_status`.
- Counter-example (correct for the bare-int shape): `includes/OAuth/BearerAuth.php::resolve_bearer_token`.
- The Phase 6 Q4 clarification (`specs/006-rest-cli-auth/spec.md` §Clarifications) drove the array-shape adoption; prior-art bare-int reads in Phase 5 are fine because their payloads are bare ints, not arrays.

### 2026-06-30 — wp_enqueue_scripts Does Not Fire When template_redirect Exits Before wp_head() [Feature-007]

**Status**
Active — known failure mode for standalone HTML rendering via `template_redirect`

**Why this is durable**
WordPress fires the `wp_enqueue_scripts` action from inside `wp_head()`. Any handler hooked there only runs if the page goes through the theme rendering chain. When a plugin handles `template_redirect`, emits its own HTML, and `exit`s — the standard pattern for browser-mediated consent surfaces, well-known endpoints, JSON-LD pages, and custom Pretty URLs — `wp_head()` is never called and the `wp_enqueue_scripts` action never fires. Hooks wired via the Loader to `wp_enqueue_scripts` silently never run on these requests. The asset registration does not happen, and any subsequent `wp_print_styles( $handle )` call prints nothing because the style was never registered. The page renders unstyled with no error indication.

**Finding**
Wiring `enqueue_assets` to `wp_enqueue_scripts` via the Loader is necessary but not sufficient for `template_redirect`-based standalone pages. The page renderer MUST also call the enqueue method explicitly before `wp_print_styles()`, in addition to the hook wiring. The hook wiring is kept for future code paths that DO go through `wp_head()`; the explicit call covers the exit-before-head path. `wp_enqueue_style` is idempotent — both invocations are safe.

**Prevention**
- For any class wired to `wp_enqueue_scripts` that ALSO renders via `template_redirect` + `exit`, call the enqueue method explicitly from the render helper (e.g. `$this->enqueue_assets()` at the top of `render_page_shell()`).
- Test the asset registration with a dedicated PHPUnit case that asserts `wp_style_is( $handle, 'enqueued' )` after calling the render helper, NOT after firing `do_action( 'wp_enqueue_scripts' )`. Firing the action would mask the gap because the test harness sees the hook fire, but production code paths don't.
- If the page later starts using `wp_head()` (e.g. a feature flag adds DataViews to the consent UI), the explicit call becomes a redundant no-op via idempotency — no breakage.

**Evidence**
- 2026-06-30 mid-implementation bug: `public/Partials/FrontendAuth.php` initially relied solely on the `wp_enqueue_scripts` hook wired in `Main::define_public_hooks()`. The consent page rendered without CSS because the action never fired on the `template_redirect` exit path.
- Fix: `public/Partials/FrontendAuth.php` `render_page_shell()` adds `$this->enqueue_assets();` before `wp_print_styles( 'acrossai-mcp-frontend' )`.
- Test coverage: `tests/phpunit/FrontendAuth/EnqueueAssetsTest.php` asserts state after calling the render helper directly, not after firing the hook.

**Where to look next**
`public/Partials/FrontendAuth.php` — the explicit `$this->enqueue_assets();` call inside `render_page_shell()` and the docblock comment above it. Any future standalone-HTML page plugin should grep `wp_print_styles` and verify a paired explicit `enqueue_assets()` call exists in the same render method.

### 2026-06-30 — wp_redirect Test Interception MUST Throw From Filter, Not Return False [Feature-007]

**Status**
Active — established WP-PHPUnit testing convention

**Why this is durable**
The standard production pattern for state-mutating GET endpoints is `wp_safe_redirect( $url ); exit;`. WP_UnitTestCase wraps `wp_die()` with a custom handler that throws `WPDieException` so the test runner can catch it — but it does NOT wrap `exit`. Returning false from the `wp_redirect` filter cancels the `header( 'Location: …' )` call (the filter's documented purpose) but does NOT prevent the surrounding code from reaching `exit;`. The test runner then terminates mid-test. This trap is subtle because the test sometimes appears to "work" — if PHPUnit happens to run the offending test last, the runner exit is invisible in the output.

**Finding**
To intercept `wp_redirect` / `wp_safe_redirect` calls in tests without losing the test runner, the filter MUST throw an exception. The exception propagates up through `wp_redirect()` to the calling code, which never reaches `exit`. The test catches the exception via `try { … } catch ( \RuntimeException $e ) { /* expected */ }`. The repo's existing convention (see `tests/phpunit/OAuth/ClaudeConnectorsDiscoveryTest.php` for the parallel `wp_die` handler pattern) uses `RuntimeException`.

**Prevention**
- Use this exact pattern for any redirect interception:
  ```php
  $redirect_target = null;
  add_filter(
      'wp_redirect',
      static function ( $location ) use ( &$redirect_target ) {
          $redirect_target = $location;
          throw new \RuntimeException( 'redirect_intercepted' );
      },
      10,
      1
  );
  ```
- Catch `\RuntimeException` (or `\Exception` if your test also catches `WPDieException` via the same try/catch).
- Reset `$redirect_target = null` between multiple calls in the same test, since the filter persists across calls.
- Add the filter BEFORE the first redirect-emitting call in the test, not after — order matters.

**Evidence**
- 2026-06-30 mid-implementation bug: `HandleApproveTest` + `MaybeRenderPageTest` initially used `return false` and the test runner died on the first `wp_safe_redirect` path.
- Fix: switched to `throw new \RuntimeException( 'redirect_intercepted' )` in `tests/phpunit/FrontendAuth/HandleApproveTest.php` and `tests/phpunit/FrontendAuth/MaybeRenderPageTest.php`.
- Repo precedent: `tests/phpunit/OAuth/ClaudeConnectorsDiscoveryTest.php` uses the same throw-from-handler pattern for `wp_die` interception (line ~49 of that file).

**Where to look next**
`tests/phpunit/FrontendAuth/HandleApproveTest.php` private helper `run_approve()` — the catch pattern that handles BOTH `WPDieException` AND `RuntimeException` in a single test entry point is the canonical implementation.

---

### 2026-07-02 — register_activation_hook default priority 10 vs. priority-1 vendor guard [Feature-010]

**Status**
Active

**Why this is durable**
WordPress's `register_activation_hook( __FILE__, 'callback' )` internally registers on the action `activate_<plugin-basename>` at default priority 10. A separate `add_action( 'activate_' . plugin_basename( __FILE__ ), ..., N )` with lower priority number N runs BEFORE the default-priority-10 callback (WP hook ordering: lower number = earlier). If the priority-10 activation callback tries to load composer classes and `vendor/` is missing, it FATALS with an unhelpful PHP error before any later-priority guard can wp_die() with a friendly message. Users see a wall of PHP fatal instead of "run composer install".

**Pattern to apply**
For any activation-time vendor-autoload / vendor-class-existence check that must gracefully wp_die() with a user-friendly message, register the check at priority 1 on `activate_<plugin-basename>`, BEFORE the default-priority-10 `register_activation_hook()` callback runs:

```php
add_action(
    'activate_' . plugin_basename( __FILE__ ),
    static function () {
        if ( ! file_exists( __DIR__ . '/vendor/autoload_packages.php' ) ) {
            wp_die( esc_html__( 'Plugin cannot activate: run "composer install".', 'plugin-slug' ) );
        }
    },
    1  // Priority 1 — runs BEFORE the default-priority-10 activation callback
);
```

The two-hook pattern coexists cleanly:
- `register_activation_hook( __FILE__, 'activate_plugin' )` — priority 10, runs the actual activation work
- `add_action( 'activate_<basename>', fn() => guard, 1 )` — priority 1, runs FIRST, wp_die() early on missing prereqs

**Prevention rule**
For any activation-time prerequisite check that emits `wp_die()`, use `add_action('activate_' . plugin_basename(__FILE__), ..., 1)` — NEVER put the check inline in the register_activation_hook callback (which runs at default priority 10 and may fatal before your check).

**Evidence**
- `acrossai-mcp-manager.php:71–90` (Feature 010 / 2026-07-02 FR-030 implementation)
- `acrossai-abilities-manager/acrossai-abilities-manager.php:82–96` (Feature 038 reference implementation with `SEC-002` documentation)

**Where to look next**
For any future plugin activation prereq (PHP extension check, WP version check, MySQL feature check), apply the same priority-1 pattern. See D15 for the companion "shared package bootstrap in plugin entry file" pattern — B14 + D15 are the paired vendor-package resilience patterns.

### 2026-07-02 — Regex verification gates that pattern-match only the bare-name form silently miss FQN and short-name aliased forms [Feature-011]

**Status**
Active

**Why this is durable**
Grep-based cross-file verification gates that pattern-match a target symbol using a **single surface form** silently produce **false negatives** against the other legal PHP spellings of the same symbol:

1. **Leading-`\` FQN form**: WPCS-compliant code often writes `class Foo extends \WP_List_Table` (leading backslash) rather than `extends WP_List_Table` — the bare-name grep `'extends WP_List_Table'` returns 0.
2. **Short-name aliased form**: files that add `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query;` at the top can call `new Query()` — the qualified-name grep `'new [A-Za-z_]*Query'` misses these because there's no prefix at the call site.

In both cases the gate reports "0 matches — PASS" while the underlying invariant is actually intact — masking the bug where a future regression IS present.

**Pattern to apply**
For every grep-based verification gate on a target class/method/const, use one of:

**Option A — Single ERE that accepts both forms** (preferred when the pattern is short):
```
# Matches both `extends WP_List_Table` and `extends \WP_List_Table`
grep -cE 'extends\s+\\?WP_List_Table' <file>

# Matches both `new MCPServerQuery()` and `new Query()`
grep -rEn '\bnew\s+([A-Za-z_\\]+\\)?Query\s*\(\s*\)' <files>
```

**Option B — Two grep passes** (use when the ERE gets awkward or when the fixed-string form is clearer):
```
# Pass 1: qualified form
grep -rEn '\bnew [A-Za-z_]*(MCPServer|CliAuthLog)Query\s*\(' <files>
# Pass 2: short-name form (bound via `use ...\Query;`)
grep -rEn '\bnew\s+Query\s*\(\s*\)' <files>
# Gate = both greps must be green
```

**Prevention rule**
Any grep-based verification gate MUST account for at minimum:
(a) The bare-name form of the target symbol.
(b) The leading-`\` FQN form of the target symbol.
(c) The short-name aliased form (bound via a `use` import) when the target is a class name.

Reviewers writing verification gates in `tasks.md` DoDs (or in FR grep contracts) MUST test the gate against BOTH the intended-pass state AND the intended-fail state before shipping — a gate that returns 0 on both healthy and broken code is worse than no gate at all, because it lulls reviewers into believing the invariant is being enforced.

**Evidence**
- **Manifestation 1 — DEV1 non-widening gate false negative**: Feature 011 `tasks.md` T032 (pre-fix) used `grep -c 'extends WP_List_Table' admin/Partials/CliAuthLogListTable.php` which returned 0 because the file has `class CliAuthLogListTable extends \WP_List_Table` (leading `\`). Architecture-review V1 (2026-07-02) caught it; T032 fixed to use `grep -cE 'extends\s+\\?WP_List_Table'`.
- **Manifestation 2 — Pre-flight callers grep missed short-name form**: Feature 011 `spec.md` FR-020 (pre-remediation) used `grep -rEn 'new [A-Za-z_]*(MCPServer|CliAuthLog|OAuthToken|OAuthAudit)[A-Za-z_]*Query'` which missed 11 caller sites in `admin/Partials/Settings.php` (× 7), `admin/Partials/MCPServerListTable.php`, `admin/Partials/ApplicationPasswords.php`, and `includes/Database/CliAuthLog/Recorder.php` that use `use ...\Query;` at the top and call `new Query()` (bare short-name). Whole-plugin gate T037 (2026-07-02) surfaced the survivors post-workflow; FR-020 fixed to require a two-pass grep.

**Where to look next**
`tasks.md` T032 (post-V1-fix) shows the canonical `extends\s+\\?<Class>` idiom.
`spec.md` FR-020 (post-I1-fix) shows the two-pass idiom (qualified + short-name via `use`).
Any future FR that codifies a grep gate for a rename sweep or boundary preservation should reference this B15 entry in its DoD line.

### 2026-07-03 — Mixed positional/numbered printf placeholders in a single format string silently mislabel output [Feature-012]

**Status**
Active

**Why this is durable**
PHP's `printf`/`sprintf` accept BOTH positional (`%s`) and numbered (`%1$s`, `%2$s`, ...) placeholders in the same format string without an error. When you concatenate a positional-`%s` format-string with a numbered-`%1$s`/`%2$s`/`%3$s` i18n string (a common pattern when you want translator-friendly `wp_kses_post( __( 'metadata: <code>%1$s</code> ...' ) )` snippets inside a larger admin layout), the numbered placeholders bind to the FIRST N arguments — NOT to the arguments you appended AFTER the leading text arguments. Result: labels or URLs display against the wrong slot with no PHP warning, no PHPStan complaint, no PHPCS violation. The bug is invisible until visual QA catches the mislabeled output.

Feature 012 hit this in `SettingsMenu.php::render_claude_connectors_section_description()`: a single `printf` concatenated `'<p>%s</p>...<p><strong>%s</strong> %s</p>' . wp_kses_post( __( 'Authorization server metadata: <code>%1$s</code><br>Authorize URL: <code>%2$s</code><br>Token endpoint: <code>%3$s</code>' ) )` with 4 leading text-arg `%s` slots followed by 3 URL args. The rendered output showed `"Authorization server metadata: Optional direct Claude Connectors mode. Use this page only to turn the experimental feature on or off."` — because `%1$s` reached for the FIRST arg (the description label), not the AS metadata URL (which was arg 5).

**Pattern to apply**
When a `printf`/`sprintf` needs to compose a positional-`%s` outer layout with an i18n string that internally uses numbered `%1$s`/`%2$s` placeholders (usually because translators need the numbered form for word-order flexibility), do NOT concatenate the two format strings. Instead:

**Option A — Split into two calls** (preferred; each `printf` sees only ONE placeholder style):
```php
// Outer layout uses positional %s only:
printf(
    '<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
    esc_html__( 'Do not cache the URLs.', 'text-domain' ),
    esc_html__( 'Long explanatory sentence.', 'text-domain' ),
    // Inner sprintf isolates the numbered placeholders inside their own i18n string:
    sprintf(
        /* translators: 1: AS metadata URL, 2: authorize URL, 3: token URL */
        wp_kses_post( __( 'AS metadata: <code>%1$s</code><br>Authorize: <code>%2$s</code><br>Token: <code>%3$s</code>', 'text-domain' ) ),
        esc_url( $as_metadata_url ),
        esc_url( $authorize_url ),
        esc_url( $token_url )
    )
);
```

Since the inner `sprintf` returns a fully-formatted string with all URLs already substituted, it can safely be passed to the outer `printf` as an ordinary `%s` argument (marked with `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` because the sniff can't statically see the `esc_url()`+`wp_kses_post()` chain — the escape is proven by construction).

**Option B — Convert everything to numbered form** (works only when the outer layout is ALSO a translated string; often it isn't):
```php
printf(
    /* translators: 1: label, 2: URL */
    wp_kses_post( __( '<strong>%1$s</strong>: <code>%2$s</code>', 'text-domain' ) ),
    esc_html( $label ),
    esc_url( $url )
);
```

**Prevention rule**
NEVER concatenate a format string containing `%s` with a format string containing `%1$s`/`%2$s`/`%3$s` inside a single `printf`/`sprintf` call. If the composition needs both styles (translated inner + literal outer, or vice versa), split into two calls where each format string uses ONE placeholder style consistently. Code review checkpoint: any `printf( '...' . wp_kses_post( __( '...%1$s...' ) ), ... )` line is a red flag.

**Evidence**
- **Manifestation**: Feature 012 `admin/Partials/SettingsMenu.php::render_claude_connectors_section_description()` (pre-fix) displayed "Authorization server metadata: Optional direct Claude Connectors mode..." because `%1$s` bound to the FIRST arg (description text) instead of the intended `esc_url( $as_metadata_url )` at arg-5. Reported by user during smoke QA (2026-07-03 session).
- **Fix commit**: Refactor to 3 separate `printf` calls; URL block built via nested `sprintf` with its own isolated numbered-placeholder i18n string.
- **Static analysis blindspot**: PHPStan L8 + PHPCS both passed on the buggy code — the mix is legal PHP; only runtime output revealed the label swap.

**Where to look next**
Any admin partial that emits `wp_kses_post( __( '...%1$s...%2$s...' ) )`-style translated snippets inside a larger `printf` call — verify each such call uses ONE placeholder style. Sibling `acrossai-abilities-manager` `SettingsMenu.php:212-220` shows the pattern working correctly because it uses positional `%s` throughout with no numbered-placeholder concatenation. Sibling wordpress-ai copy at `src/Admin/Settings.php:490-506` uses full `<?php ... ?>`-tag rendering which bypasses printf entirely — either idiom is safe; the mixed-mode idiom is the trap.

---

### 2026-07-04 - B17 — `rest_url()` returns URL WITH trailing slash; consumer concat produces `//`-double-slash 404s

**Status**
Active

**Why this is durable**
`rest_url()` returns the site's REST base URL WITH a trailing slash (e.g. `https://example.com/wp-json/`). Any consumer that builds sub-paths by string concat (`restApiRoot + '/wpb-ac/…'`) produces `//wpb-ac/…` which WordPress does not route → 404. Symptom is invisible in PHPStan/PHPCS/PHPUnit because the URL is only assembled at JS runtime by the downstream consumer.

**Evidence**
- **Manifestation**: F015 vendor `@wpb/access-control` React component received `restApiRoot = 'https://wordpress-7-0.local/wp-json/'` via `wp_localize_script` and concatenated `restApiRoot + '/wpb-ac/v1/mcp/providers'` — every apiFetch call resolved to `/wp-json//wpb-ac/…` and 404'd. Discovered when the Access Control tab picker was empty; DevTools revealed the double slash in Request URL.
- **Fix commit**: `admin/Main.php:195` — `'restApiRoot' => esc_url_raw( untrailingslashit( rest_url() ) )`. Same pattern applies to any `wp_localize_script` field whose consumer joins with a leading-slash sub-path.
- **Static analysis blindspot**: PHP-side lint is silent because the trailing-slash URL is well-formed. The bug only manifests in the JS consumer's URL builder.

**Where to look next**
Before passing `rest_url()` (or `home_url()`, `admin_url()`, `site_url()` — same trailing-slash convention) to any third-party JS bundle / config / template that will concatenate sub-paths, either strip the trailing slash — `esc_url_raw( untrailingslashit( rest_url() ) )` — or pass the fully-formed URL via `rest_url( 'sub-path' )` (WordPress joins correctly). Grep for the pattern: `grep -rn "restApiRoot\|rest_url()" admin/ includes/` and audit each site that hands the value to a downstream URL builder.

---

### 2026-07-04 - B18 — Strict-int comparison against MySQL TINYINT columns silently mislabels every row

**Status**
Active

**Why this is durable**
`$wpdb` returns MySQL TINYINT columns as string `"0"` / `"1"` — not int. Strict-equality checks like `1 === $row->is_enabled` are always false → boolean rendering silently defaults to the "0" branch on every row. On BerlinDB Row property reads, the declaration `public int $col = 0;` is a documentation hint, not runtime typing — the driver still returns strings. Bug is invisible to static analysis because the strict compare is valid PHP.

**Evidence**
- **Manifestation**: F015 session — `admin/Partials/MCPServerListTable::prepare_items()` used `'enabled' => 1 === $row->is_enabled` and every server (regardless of DB state) rendered as "Inactive" + "Enable" button. Same bug in `admin/Partials/Settings::toggle_server_status()`: `1 === $current_enabled` was always false, so the Active→Inactive transition silently no-op'd. The Overview tab used `! empty( $server['is_enabled'] )` and correctly showed "Active" — the mismatch between the two callers is what exposed the bug (same server row rendered as "Active" on the edit page and "Inactive" on the list).
- **Fix commit**: `MCPServerListTable.php:91` — `1 === $row->is_enabled` → `! empty( $row->is_enabled )`. `Settings.php:277` — `$current_enabled = (int) $rows[0]->is_enabled` before the strict compare.
- **Static analysis blindspot**: PHPStan L8 sees `$row->is_enabled` as `int` per the Row property doc-hint and considers `1 === int` valid. Runtime typing is where the bug lives.

**Where to look next**
When rendering a table column derived from a boolean-shaped int, prefer `(bool) $row->col` in the row-map or `! empty( $row->col )` for boolean semantics. When comparing strictly, cast: `1 === (int) $row->col`. Grep gate for new BerlinDB Row consumers: `grep -nE '(===|!==) *\$row->' admin/ includes/` to catch strict-compare-on-driver-string patterns. Applies to every plugin using `$wpdb` or BerlinDB — not F015-specific.

---

### 2026-07-04 - B19 — WP Application Password client-config generators MUST emit both `WP_API_USERNAME` and `WP_API_PASSWORD`

**Status**
Active

**Why this is durable**
WordPress Application Passwords authenticate via HTTP Basic (`Authorization: Basic base64(user:pass)`). A client config that ships only `WP_API_PASSWORD` (env var, CLI flag, JSON key) breaks auth silently — the consuming MCP/HTTP client can't build the Basic header without both. Symptom: user pastes the generated config, tool starts, every request 401s with no obvious reason (nothing in `wp-admin` says "your config is missing the username"). Bug is a "shipped without the peer field" pattern — trivial to write, hard to spot on inspection because each individual `env` block looks internally consistent.

**Evidence**
- **Manifestation**: F015 session — 7 MCP client classes (`VSCodeClient`, `ClaudeDesktopClient`, `CursorClient`, `CodexClient`, `CustomClient`, `GitHubCopilotClient`, `ClaudeCodeClient`) all shipped configs with `WP_API_PASSWORD` but no `WP_API_USERNAME`. Reference plugin `acrossai-mcp-manager` at `/Users/raftaar1191/local-sites/wordpress-ai/…/src/Admin/ApplicationPasswords.php:366-367` had both — the bug was a "port omitted a field" regression.
- **Fix commit**: Added `AbstractMCPClient::current_username()` helper returning `wp_get_current_user()->user_login`. Every concrete client's `get_config_snippet()` now emits `'WP_API_USERNAME' => $this->current_username()` between URL and PASSWORD. `ClaudeCodeClient` (CLI form) uses `escapeshellarg($username)` in the sprintf.
- **Static analysis blindspot**: PHPStan + PHPCS both green — each client class is self-consistent. Test coverage passed because tests asserted `env.WP_API_PASSWORD === '(placeholder)'` without inspecting siblings.

**Where to look next**
When a plugin generates client-facing WP-API auth configs, ship `WP_API_USERNAME` (from `wp_get_current_user()->user_login`) as a peer of `WP_API_PASSWORD`. Add a helper method on the abstract config generator (e.g. `AbstractMCPClient::current_username()`) so no subclass forgets it. Verify by grepping every concrete generator: `grep -L 'WP_API_USERNAME' includes/MCPClients/*.php` should return zero files. Applies to any plugin family generating MCP/HTTP client configs, wp-json REST curl examples, or WP-CLI login snippets that depend on Application Passwords.

---

### 2026-07-07 - B20 — Plaintext OAuth secret in `varchar(255)` column (S3 constitution violation)

**Status**
Active — retroactively closed by F016 for `claude_connector_client_secret`. Prevention pattern durable.

**Why this is durable**
Constitution §III S3 says "OAuth tokens and Application Passwords MUST be stored hashed (SHA-256 minimum) — never plaintext". A `char(64)` column paired with `hash('sha256', $secret)` in the write path enforces this at both the DDL layer (column can't hold anything but a fixed-width digest) and the code layer (the hash call is the only sane thing to write). A `varchar(255) default ''` column paired with `sanitize_text_field()` does NOT — it silently accepts and stores the plaintext secret. Static analysis (PHPCS/PHPStan) is blind to this because both the column definition and the sanitizer call are individually well-formed. Grep is the only reliable detection.

**Symptom**
A BerlinDB `Schema.php` column definition like:
```php
array( 'name' => 'foo_client_secret', 'type' => 'varchar', 'length' => '255', 'default' => '' ),
```
paired with an admin form handler that writes `sanitize_text_field($_POST['foo_client_secret'])` directly into that column via `$query->update_item()`. The plaintext secret is now stored on disk (`.ibd` tablespace), in the MySQL binary log, in every backup, and potentially in slow-query / general-query logs.

**Evidence**
- **Manifestation**: `admin/Partials/Settings.php::handle_claude_connector_update` (deleted in F016 2026-07-07) wrote `sanitize_text_field($_POST['claude_connector_client_secret'])` into `wp_acrossai_mcp_servers.claude_connector_client_secret varchar(255) default ''`. The column existed for ~5 months before the S3 violation was surfaced during F016 security review SEC-STAGED-001.
- **Retroactive fix**: F016 (a) deleted the write path (`handle_claude_connector_update` + `handle_actions()` allow-list entry); (b) published a manual retirement recipe including a pre-DROP `UPDATE wp_acrossai_mcp_servers SET claude_connector_client_secret = ''` step to force InnoDB tablespace overwrite before column drop; (c) dropped the three `claude_connector_*` columns via operator-run `ALTER TABLE ... DROP COLUMN` (per DEC-FRESH-INSTALL-ONLY-RETIREMENT / D21 pattern).
- **Static analysis blindspot**: PHPCS + PHPStan both green through the entire ~5-month window. Neither tool inspects Schema column type/length against write-path helpers.

**Where to look next**
Every future custom-DB table introducing a `_secret`, `_token`, `_password`, or `_key` column MUST use `char(64)` (SHA-256) or `char(*)` (larger digest, e.g. `char(128)` for SHA-512). `varchar` on a secret/token column is a review-time hard-fail. Reviewer grep-gate:
```
grep -rEn "'name' *=> *'[^']*_(secret|token|password|key)'" includes/Database/
```
Every hit MUST have `'type' => 'char'` with `length >= 64` on the next 1-3 lines. If `'type' => 'varchar'` or shorter length appears, either (a) reject the PR, or (b) confirm the column is intentionally storing a non-secret (e.g. a public identifier, an opaque request ID) and add an inline comment justifying it.

Retroactive-fix pattern (F016 canonical): (1) delete the write path (admin form handler + REST controller); (2) pre-DROP `UPDATE table SET secret_col = ''` to overwrite InnoDB pages; (3) `ALTER TABLE ... DROP COLUMN`; (4) update `Schema.php`, `Row.php`, `DefaultServerSeeder.php` to remove the field entirely. Applies to any custom-DB plugin storing OAuth secrets, API keys, or session tokens.

---

### 2026-07-07 - B21 — BerlinDB v3 recognized column flags do NOT include `date_updated`

**Status**
Active — surfaced during F017 implementation on PHP 8.2 (2026-07-07).

**Why this is durable**
BerlinDB v3's `Kern\Column` docblock enumerates ~20 recognized column flags. `created` (INSERT-time timestamp) and `modified` (UPDATE-time timestamp) are the two datetime flags — there is NO `date_updated` flag despite the intuitive name. Passing an unrecognized flag as a column-args key silently creates a dynamic property on `Column`, which trips PHP 8.2+'s "Creation of dynamic property Column::$X is deprecated" notice at every column boot. In `debug.log`, this looks like every request logs the same deprecation for every Schema definition using the wrong flag. In an admin-only path, it's an ugly noise wall in `wp-content/debug.log`; on a live install with `WP_DEBUG_DISPLAY = true`, the notice would surface at the top of every admin page.

**Symptom**
```
Deprecated: Creation of dynamic property BerlinDB\Database\Kern\Column::$date_updated is deprecated
in vendor/berlindb/core/src/Database/Traits/Base.php on line 183
```
Row inserts and updates still succeed (BerlinDB's `save_item()` handles the `created` timestamp; without a valid `modified` flag, the `updated_at` column never gets auto-stamped). The bug is silent at the DB layer but noisy at the PHP layer.

**Evidence**
- **Manifestation**: F017 `includes/Database/MCPServerAbility/Schema.php` shipped with `'date_updated' => true` on the `updated_at` column. On the developer's local install running PHP 8.2, every `Query::instance()->query(...)` (executed on every request from `Main::bootstrap_database_tables()` → `Table::instance()`) fired the deprecation.
- **Root cause**: I intuited the flag name from the memory `A11/A15` pattern documentation and BerlinDB's `date_query` flag, without checking the BerlinDB Column docblock. The docblock at `vendor/berlindb/core/src/Database/Kern/Column.php:38-56` is the authoritative list of recognized flags.
- **Fix**: Change `'date_updated' => true` → `'modified' => true`. No DDL change needed (the column type is already `datetime`); BerlinDB's next `maybe_upgrade()` diff-pass leaves the column shape untouched.

**Where to look next**
The BerlinDB v3 Column docblock at `vendor/berlindb/core/src/Database/Kern/Column.php:38-56` is the authoritative list of recognized flags. Recognized datetime flags: `created` (INSERT-time), `modified` (UPDATE-time), `date_query` (enables __between / __compare / __not_in variants). Recognized boolean flags include `unsigned`, `zerofill`, `binary`, `allow_null`, `primary`, `uuid`, `searchable`, `sortable`, `in`, `not_in`, `cache_key`, `transition`. Any Schema flag NOT on that list becomes a dynamic property on PHP 8.2+.

Reviewer grep-gate for every new Schema.php:
```
grep -rEn "'(date_updated|updated_date|modified_date|updated_time)'" includes/Database/
```
MUST return zero matches — these are all common misspellings of the `modified` flag.

Broader lesson: when authoring subclass configs against a vendor package's args array, read the vendor's docblock @param list end-to-end. Do NOT rely on flag names inferred from memory documentation or sibling code, especially when the vendor was recently upgraded (BerlinDB v3 renamed several v2 flags).

---

### 2026-07-08 - B22 — New `@wordpress/*` packages need runtime string store lookup, not build-time import

**Status**
Active — surfaced during F017 implementation on `@wordpress/abilities@0.16.0` (2026-07-08).

**Why this is durable**
`@wordpress/scripts` (v30.x) maintains an internal externals map that translates `import ... from '@wordpress/foo'` into the runtime handle `wp.foo` + the enqueue-side dep `wp-foo`. Packages not yet in that map get either bundled (silent bloat) or manifest-listed under a handle WordPress doesn't actually register (silent no-op). The failure is invisible — the bundle builds, the JS runs, the exported symbol imports OK, but its runtime side-effects (store registration) never fire.

**Symptom**
```js
import { store as fooStore } from '@wordpress/foo';
useSelect( ( select ) => select( fooStore ).getSomething() );  // returns undefined forever
```
The React tree hangs on a permanent loading state; no console error; the network tab shows no dependency handle loaded.

**Evidence**
F017 initial implementation at `src/js/abilities.js` imported `store as abilitiesStore` from `@wordpress/abilities`. Tab rendered `Loading abilities…` indefinitely. Rewrite to string-key runtime lookup + REST fallback resolved it: `wp.data.select( 'core/abilities' )` returns undefined at that moment, the fallback fetches `GET /servers/{id}/abilities?include_abilities=1`, and the tab populates from the server-shipped ability list.

**Where to look next**
The canonical shape lives in `src/js/abilities.js:52-100` — a `ABILITIES_STORE_KEY` constant, a `useSelect` that returns `null` when the store isn't registered, and a REST fallback state populated by the `?include_abilities=1` path. Add this pattern to every future `@wordpress/*` package the plugin adopts until the package lands in `@wordpress/scripts`' externals bundle. Reviewer grep-gate: `grep -rEn "import.*from '@wordpress/(?!scripts|env)" src/js/` — any hit is a candidate for the string-key rewrite.

---

### 2026-07-08 - B23 — Test-suffix method names on production-load-bearing helpers

**Status**
Active — surfaced during F017 staged security review (SEC-STAGED-001, 2026-07-08).

**Why this is durable**
A method named `_reset_cache_for_tests()`, `_for_testing()`, or `_test_only_reset()` signals "safe to remove, guard, or replace with a mock" to any maintainer reading the source. When production code silently depends on the method to enforce an invariant, removal produces no visible failure — the invariant just quietly stops holding. Common invariants at risk: per-request cache invalidation between two reads, singleton reset between requests, shared static state clearing between hook fires.

**Symptom**
```php
final class ExposureResolver {
    private static array $cache = array();
    public static function _reset_cache_for_tests(): void {  // <-- name lies
        self::$cache = array();
    }
}
// AbilitiesController::post_abilities():
$was = ExposureResolver::resolve( $server_id, $slug, $meta );
Query::instance()->upsert( $server_id, $slug, $is_exposed );
ExposureResolver::_reset_cache_for_tests();  // <-- production call site!
$now = ExposureResolver::resolve( $server_id, $slug, $meta );
if ( $was !== $now ) { do_action( 'exposure_changed', ... ); }
```
Maintainer sweeps `grep -rn "_for_tests" includes/` looking for cleanup targets, removes the method — `$was === $now` becomes always-true — `exposure_changed` action never fires again — silent regression on the audit contract.

**Evidence**
- `includes/Database/MCPServerAbility/ExposureResolver.php:75-77` — method definition.
- `includes/REST/AbilitiesController.php:~215, ~264` — production call sites.
- Staged security review 2026-07-08 SEC-STAGED-001 (MEDIUM) — full analysis.

**Where to look next**
Reviewer grep gate: `grep -rEn '_reset.*for_tests|_for_testing|_test_only' includes/ src/`. Each hit needs classification:
- Called ONLY from `tests/**` → legitimate, keep test-suffix name, no action.
- Called from production code (`includes/`, `admin/`, `public/`) → rename to production-shape (`clear_request_cache`, `reset_state`), OR redesign to eliminate the dependency (e.g., have `Query::upsert_and_get_effective()` return the new value directly, skipping the resolver's cache entirely).

Applies to any static / singleton state reset the plugin uses to enforce a cross-call invariant.

---

### B24 — Vendor accessor assumption via `instanceof` silently fails when vendor namespace differs

**Status**: Active (F020 — 2026-07-09)
**Scope**: Cross-package integration, especially `mcp-adapter` / any vendor object accessed via WordPress action/filter payload.
**Tags**: `vendor-accessor, method-exists, instanceof-antipattern, silent-failure, enforcement-gate, sec-020-007, generalizable`

**Why this is durable**

F020's first plan-remediation drafted `$server instanceof \WP\MCP\Server` and `$server->get_id()` for the runtime enforcement gate — plausible-looking pattern copied from casual documentation. The real vendor class is `\WP\MCP\Core\McpServer` (verified at `vendor/wordpress/mcp-adapter/includes/Core/McpServer.php:26`) with `get_server_id(): string` (returning a slug, not an int). As written, the `instanceof` check would have failed on every real request, `$server_id` stayed `0`, the fail-open branch triggered, and the enforcement gate became a **no-op**. Same effective outcome as the original SEC-020-001 gap the remediation was meant to close. Only caught because a second security review verified the contract text against the vendor's actual source.

**Pattern to prevent**

Use **duck-typed feature detection** for cross-package accessors:

```php
// WRONG — vendor namespace assumptions rot silently:
if ( $server instanceof \WP\MCP\Server ) {
    $id = (int) $server->get_id();
}

// RIGHT — feature-detected, forward-compatible with vendor refactors:
if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
    return $args; // Fail-open.
}
$slug = (string) $server->get_server_id();
```

F015 (`AcrossAI_MCP_Access_Control.php:249-253`), F017 (`AbilityExposureGate.php:98-119`), and F020 (`ToolExposureGate.php:113-136`) all follow this pattern. Grep gate for new code that touches a vendor object's accessor: `grep -rEn 'instanceof.*\\\\.*McpServer|->get_id\(\)' includes/` MUST return zero matches OR the match MUST be inside an `if` block whose header is `method_exists( ..., 'get_server_id' )`.

**Evidence**
- `docs/security-reviews/2026-07-09-020-per-server-tool-selection-plan-v2.md §SEC-020-007` — full analysis.
- `includes/MCP/ToolExposureGate.php:113-136` — correct shipped pattern.
- `includes/MCP/AbilityExposureGate.php:98-119` — F017 canonical reference.
- `includes/AccessControl/AcrossAI_MCP_Access_Control.php:249-253` — F015 canonical reference.

**Where to look next**

For every vendor object accessed via WordPress action/filter payload, check the vendor source for the exact class name + accessor signature BEFORE writing the check. Casual documentation and README snippets often lag behind the vendor's actual namespace layout.

---

### B25 — Redundant `apiFetch.createRootURLMiddleware` in admin JS risks double-slash 404

**Status**: Active (F020 — 2026-07-09)
**Scope**: Admin-context React/JS bundles enqueued via `admin_enqueue_scripts`.
**Tags**: `apifetch, middleware, wp-api-settings, redundancy, double-slash-404, silent-failure, admin-js`

**Why this is durable**

WordPress admin JS bundles enqueued on plugin screens automatically inherit `wpApiSettings.root` from WordPress core; `@wordpress/api-fetch` uses that as its default rootURL. Explicitly wiring `apiFetch.use( apiFetch.createRootURLMiddleware( config.restApiRoot + '/' ) )` is redundant AND, when `config.restApiRoot` is already `untrailingslashit`-clean (per B17), risks silent 404s: combining a trailing-`/` base with paths that start with `/` produces `//`-doubled URLs that WordPress routes as 404. F020's initial mount function shipped this pattern; F017's `src/js/abilities.js:95` correctly wires ONLY `createNonceMiddleware` and leaves URL rooting to core.

**Pattern to prevent**

For admin-context JS, wire ONLY the nonce middleware:

```javascript
// WRONG — redundant + double-slash risk:
if ( config.nonce ) {
    apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}
if ( config.restApiRoot ) {
    apiFetch.use( apiFetch.createRootURLMiddleware( config.restApiRoot + '/' ) );
}

// RIGHT — matches F017 abilities.js:95, relies on wpApiSettings.root:
if ( config.nonce ) {
    apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}
```

Only add `createRootURLMiddleware` when the JS runs OUTSIDE an admin script context (e.g. in a public block that WordPress doesn't auto-configure, in a mail-template preview, or against a separate REST host).

**Evidence**
- `docs/security-reviews/2026-07-09-020-per-server-tool-selection-staged.md §SEC-020-STG-001` — full analysis.
- `src/js/abilities.js:95` — F017 canonical reference (nonce middleware only).

**Where to look next**

Grep gate for admin JS: `grep -rEn 'createRootURLMiddleware' src/js/`. Every hit needs justification: is this JS enqueued in a WordPress admin context (`admin_enqueue_scripts`)? If yes, delete the wire. Companion check: `grep -rEn '\+ \x27/\x27\)' src/js/` catches trailing-slash concatenation patterns that trip B17.

---

### B26 — Governance-gate scope drift: grep gates that hard-code a directory allow-list silently skip newly-added layers

**Status**: Active (F021 T118d added — 2026-07-12)
**Scope**: Project-specific verification gates under `bin/verify-*.sh` that use `grep -r` against a hard-coded set of directories to enforce a layering rule.
**Tags**: `grep, gate-hygiene, layer-scope, silent-pass, boundary, refactor-hazard, verification-gate`

**Why this is durable**

Related to but distinct from B15 (regex form completeness). B15 is about the *shape* of the pattern (bare-name vs FQN); B26 is about the *scope* of the scan (which directories the pattern is applied against). Both are grep-based verification-gate hygiene failures with silent-pass symptoms — the gate reads green in CI, the boundary is broken. F021's T118c gate scanned only `includes/OAuth/**` when checking "Controllers MUST NOT touch `$wpdb`"; the F024 nested-tabs work added `global $wpdb` + direct `\...\OAuthClients\Query::instance()` calls to `admin/Partials/ServerTabs/AIConnectorsTab.php:273-326` (the presentation layer, participating in the same layering rule). The gate passed for 24+ hours; the architecture review found it as V2.

**Pattern to prevent**

Every layering gate MUST enumerate every layer that participates in the layering rule, not just the layer being called out in the rule name. Concrete checklist:

1. **Enumerate all layers**: For a rule like "X MUST NOT touch Y", list every directory/namespace pattern that could contain code standing in for X. For F021 T118c, "no `$wpdb` above the Repository line" applies to Controllers AND Partials AND Renderers AND anywhere else that instantiates Query classes.
2. **Add the layer to the gate when the layer is added to the codebase**: When introducing a new admin-Partial subdirectory, new REST controller subclass, or new namespace, audit every `bin/verify-*.sh` for whether the scan set needs to grow.
3. **Validate every new gate against a known violation**: A gate that has never seen red is not a gate — it is a decoration. Before shipping `T118d`, verify it fires on the pre-fix code, then re-run after the fix and confirm green.
4. **Prefer inclusive base directories over per-layer allow-lists** when possible: `grep -rEn ... includes/ admin/Partials/ public/Partials/` is more robust than an exhaustive per-namespace list, because it grows with the codebase.

**Evidence**
- `bin/verify-f021-gates.sh` (pre-fix): T118c grep set = `includes/OAuth/Discovery/Authorization/Token/ClientRegistration/TokenValidator/UserLifecycle/Cleanup/OAuthRouter/PKCE.php` — 9 files, controllers only.
- `admin/Partials/ServerTabs/AIConnectorsTab.php:273-326` (pre-fix): direct `\...\OAuthClients\Query::instance()` + `global $wpdb; $wpdb->get_var(...)` — passed T118c silently.
- `bin/verify-f021-gates.sh` (post-fix 2026-07-12): T118d added to scan `admin/Partials/ServerTabs/AIConnectorsTab.php` for both `$wpdb` and `\...\OAuth*\Query::instance` patterns — fires red on the pre-fix code before R2 refactor.
- Architecture review report 2026-07-12 V2 + V3 findings.

**Where to look next**

Grep for governance gate drift: `grep -rEn 'grep.*includes/[A-Z]' bin/verify-*.sh` → every hit's directory list must be reviewed for completeness whenever a new top-level PHP directory pattern is added under `admin/`, `public/`, or `includes/`. Related: [[D22]] (fold-in tracking — same failure mode class); [[B15]] (regex form completeness).

---

### B27 — GitHub Actions matrix-cell check names are brittle for `required_status_checks.contexts` — matrix cells register as separate check names that shift when the matrix expands/contracts

**Status**: Active (F021 branch protection setup — 2026-07-12)
**Scope**: GitHub Actions matrix workflows + repository branch-protection `required_status_checks.contexts` API config.
**Tags**: `github-actions, matrix, required-status-checks, brittle-pinning, ci-drift, branch-protection`

**Why this is durable**

Applies to any repo that uses GH Actions matrix workflows + branch protection with `required_status_checks.contexts`. The check-run naming pattern is a stable GH Actions API contract; the brittleness follows deterministically from that contract. Not a bug in GH Actions — a bug in how the two features are wired together at the operator level.

**Pattern to prevent**

When a workflow uses a `strategy.matrix`, GitHub creates one check run per matrix cell with a name derived from the job's `name:` field + the matrix combination (e.g. `PHPUnit (pure) — PHP 8.1`, `PHPUnit (pure) — PHP 8.2`, ...). Pinning these in `repos/{owner}/{repo}/branches/{branch}/protection` → `required_status_checks.contexts` produces three failure modes:

1. **Adding a new matrix cell** (e.g. PHP 8.5): creates a NEW check name NOT in the required list → merges no longer wait for it. Silent coverage loss.
2. **Removing a matrix cell** (e.g. dropping PHP 8.1): leaves a stale required-name that will never report → merges block indefinitely. Loud but confusing.
3. **Renaming the job's `name:`**: breaks all pinned matrix cells atomically → all cells become stale required-names. Merges block until the operator manually updates protection settings.

Concrete remediation options in order of preference:

1. **Prefer non-matrix single-job workflows for gate checks** (PHPCS, PHPStan, PHPCompat, ESLint, validate-packages, project-specific grep gates). One check name per workflow, stable across matrix changes. Pin these.
2. **Use matrix workflows for coverage only** (PHPUnit across PHP versions). Do NOT pin matrix cells in branch protection. Accept that a PR could theoretically pass with one PHP cell red; enforce elsewhere (e.g. required review from CODEOWNERS who spot-check the matrix).
3. **Meta-job pattern** when you MUST enforce a matrix: add a single `jobs.gate` job with `needs: [phpunit]` and pin the meta-job name instead. Costs one extra scheduled job per PR; buys a stable pinned name.
4. **Audit protection settings whenever the workflow matrix changes**: cross-check `git grep -l 'strategy:' .github/workflows/` × `gh api repos/{owner}/{repo}/branches/main/protection` `required_status_checks.contexts`. Every workflow with a matrix should either NOT appear in the list, or appear via its meta-job name.

**Evidence**
- `.github/workflows/phpunit.yml` (2026-07-12): matrix `[8.1, 8.2, 8.3, 8.4]` — 4 matrix cells producing checks `PHPUnit (pure) — PHP 8.1`, `... 8.2`, `... 8.3`, `... 8.4` for the pure job and 2 more (`PHPUnit (integration) — PHP 8.1 / WP latest`, `... 8.4 / WP latest`) for the integration job.
- `acrossai-co/acrossai-mcp-manager` branch protection (2026-07-12): `required_status_checks.contexts` = 6 non-matrix check names (PHPCS, PHPStan, PHPCompat, ESLint, validate-packages, F021 gates). PHPUnit intentionally omitted.
- Architecture review + workflow-setup session 2026-07-12.

**Where to look next**

Before applying branch protection: `gh api repos/{owner}/{repo}/actions/runs?per_page=1 --jq '.workflow_runs[0].check_run_url'` and inspect the actual check names GitHub assigns. For every matrix workflow, decide: pin the meta-job, or omit and rely on CODEOWNERS. Never pin raw matrix-cell names unless the matrix values are frozen at the plugin's supported-version floor (rare).

---

### B28 — Freemius auto-submenus require BOTH `menu.<key>` AND the corresponding `has_<key>` / `is_<key>` at `fs_dynamic_init()` top level (silent no-render otherwise)

**Status**: Superseded (Feature 028 — 2026-07-17). Retirement is by removal: `freemius/wordpress-sdk` is uninstalled with `acrossai-co/main-menu` 0.0.22, so no consumer surface here can trigger the two-level enablement failure mode. Body stays for reference until an audit prunes it (see PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION). See `docs/planings-tasks/028-remove-freemius-and-filter-self.md`.
**Original status**: Active (Feature 022 — 2026-07-13)
**Scope**: Every consumer of the Freemius WordPress SDK (any AcrossAI plugin using `\AcrossAI_Addon\AddonsPage` from `acrossai-co/main-menu`, plus any future direct Freemius consumer).
**Tags**: `freemius, two-level-enablement, menu-config, fs_dynamic_init, silent-no-render, generalizable`

**Symptom**

Setting `menu.<key> => true` in the Freemius `fs_dynamic_init()` config array produces zero visible effect for many keys — the submenu row simply does not appear under the plugin's parent menu, with no error log line, no admin notice, and no PHP warning. F022 hit this exact failure for the Add-ons row: `fs_menu.addons => true` was passed via the `acrossai-co/main-menu` 0.0.16 `fs_menu` override, but the Add-ons submenu remained invisible on wp-admin for ~5 rounds of diagnosis before the SDK-level gate was found.

**Root cause**

The Freemius SDK enables auto-submenus via a **two-level check** that is not documented in the SDK-config reference. Each `menu.<key>` boolean is AND'd with an independent top-level fs_dynamic_init capability flag at render time. Both must be `true` for the row to appear:

| `menu.<key>` | Also gated on (top-level fs_dynamic_init) | SDK code path |
|---|---|---|
| `menu.addons` | `has_addons => true` | `class-freemius.php:18964` — `if ( $this->has_addons() ) { ... add_submenu_item('addons', ...) }` |
| `menu.pricing`, `menu.upgrade` | `has_paid_plans => true` (and premium-plan config) | premium-flow gates around `_pricing_page_render` / `_upgrade_page_render` |
| `menu.account` | `is_registered() === true` (opt-in complete) | `class-freemius.php:18913` — `if ( ! WP_FS__DEMO_MODE && $this->is_registered() ) { ... add_submenu_item('account', ...) }` |
| `menu.contact` | (no additional flag — just `menu.contact === true`) | direct `add_submenu_item()` call, no capability gate |
| `menu.support` | (no additional flag — just `menu.support === true`) | direct `add_submenu_item()` call, no capability gate |

The `contact` and `support` keys are the ONLY ones that work with a single-level enablement — every other key needs a second flag.

**Decision (Prevention Recipe)**

When enabling any Freemius auto-submenu on an AcrossAI plugin:

1. Set the `menu.<key>` boolean via `AddonsPage`'s `fs_menu` override in `includes/Main.php`.
2. Grep the SDK for the render gate on that key: `grep -n "'<key>'\|has_<key>\|is_<key>\|_<key>_page_render" vendor/freemius/wordpress-sdk/includes/class-freemius.php | head -20`.
3. If a second-level flag is required and no override key exists on `AddonsPage`, extend the vendor (see F022 Phase 4e — `fs_has_addons` was added exactly this way in main-menu 0.0.18).
4. Verify at least one submenu row renders in wp-admin after opt-in completes; NEVER ship a "menu enabled" change without visual confirmation.

**Tradeoffs / Prevention**
- Gained: no more silent-no-render debugging arcs on Freemius menu changes.
- Reconsider: if Freemius simplifies the SDK to single-flag enablement in a future release, this table becomes obsolete — verify the gate list before assuming the table is current.
- Related: `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` (F022 corollary documents the `fs_menu` + `fs_has_addons` override API on `AddonsPage`); `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT` (F022 — covers the `is_registered()` half of the `menu.account` gate).

**Evidence**
- `vendor/freemius/wordpress-sdk/includes/class-freemius.php:18964` — Add-ons render gate.
- `vendor/freemius/wordpress-sdk/includes/class-freemius.php:18913` — Account render gate.
- F022 branch commits `ba27058` (plugin-side fix — pass `fs_has_addons => true`) and `a6a35ff` (vendor-side fix — expose `fs_has_addons` override in main-menu 0.0.18) — both trace back to this failure mode.
- Session log 2026-07-13 shows ~5 rounds of "why isn't Add-ons showing" diagnosis before the SDK gate was located.

**Where to look next**

When a new Freemius auto-submenu is proposed (e.g. Freemius adds a `menu.affiliation` or `menu.gdpr` in a future SDK release): grep the SDK immediately for the second-level flag before declaring the enable "done". If the second flag isn't yet exposed on `AddonsPage`, plan a `acrossai-co/main-menu` bump using the same override pattern as `fs_has_addons` (main-menu 0.0.18).

---

### 2026-07-14 - B29 — Vendor `add_action` inside `__construct` misses actions that already fired

**Status**
Active — F025 evidence 2026-07-14.

**Why this is durable**
Third-party WordPress packages routinely wire `add_action` calls inside their class `__construct()` or `init()` methods. If those classes are instantiated inside another hook's callback (e.g., our `Controller::initialize_adapter()` on `rest_api_init`), then any `add_action` for hooks that FIRE BEFORE the outer callback runs will silently miss — the listener attaches AFTER its target action already fired. `wp_get_ability()` returned empty at F025 POST-time for exactly this reason.

**Pattern**

```php
// Vendor code — Plugin::__construct() (we instantiate via ::instance() inside our rest_api_init).
class Plugin {
    public function __construct() {
        // Fires during WP `init` — already fired by the time our rest_api_init runs.
        add_action( 'wp_abilities_api_init', array( $this, 'register_default_abilities' ) );
    }
}
```

Symptom: `wp_get_abilities()` (or equivalent lookup) returns fewer entries than expected at REST/AJAX time. The missing entries were supposed to be registered by an action that fires EARLIER than the outer callback where the vendor is instantiated.

**Prevention / Detection**

1. Any REST route validating a vendor-registered slug via `wp_get_ability()` MUST include a manual smoke test (curl the endpoint) — static hook analysis is insufficient.
2. When a plugin-owned canonical source exists for the slug (like F025's `ToolPolicy::PROTOCOL_TOOLS`), prefer it over `wp_get_abilities()` for validation.
3. For vendor packages whose init pattern uses `add_action` inside `__construct`, document the required outer hook order OR bootstrap on `plugins_loaded` P0 for hooks that must catch `init`.

**Fixed by (F025)**

- FR-018: POST validation bypasses `wp_get_abilities()` for canonical protocol slugs.
- `ToolPolicy::PROTOCOL_TOOL_METADATA`: GET catalog fallback for reader-side visibility.

**Where to look next**

- `vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php:120`
- `includes/REST/ToolsController.php` FR-018 comments
- `docs/security-reviews/2026-07-13-025-server-tools-registration-hooks-plan-v2.md` §SEC-025-v2-2

---

### 2026-07-14 - B30 — Plugin composer shadowing vendor discovery must mirror the vendor's type-filter semantic exactly

**Status**
Active — new bug pattern surfaced during F026 v1 → v2 fold-in.

**Why this is durable**
When plugin code composes a slug list that will be handed to a vendor API which
INTERNALLY dispatches by ability type (tool vs resource vs prompt), the plugin
composer MUST filter by the same `mcp.type` key the vendor's own discovery
helper uses — including its `?? 'tool'` default when unset. Missing this
filter causes cross-type advertisement: e.g. a `mcp.type === 'resource'`
ability leaks into `tools/list`, then `tools/call` on it rejects at
invocation time because the vendor's call-time dispatcher can't find it in
the tool registry.

**Symptom**
- `tools/list` includes ability slugs that `tools/call` immediately rejects
  with `_doing_it_wrong` or 404.
- `resources/list` and `prompts/list` are empty despite public resource/prompt
  abilities being registered.

**Root cause**
Vendor `DefaultServerFactory::discover_abilities_by_type( 'tool' )` filters by
`$meta['mcp']['type'] ?? 'tool'`. F026 v1's `compose_effective_tools_for_row()`
missed the filter — it included every ability where `ExposureResolver::resolve()`
was true. Fixed in F026 v2 by adding `AbilityDiscovery::for_server( $id, $type )`
which mirrors the vendor's `?? 'tool'` default.

**Prevention**
- When authoring a composer that shadows or supplements a vendor discovery
  helper, first read the vendor helper's filter chain end-to-end. Mirror EVERY
  filter step (including `?? 'default'` fallbacks) in the plugin composer.
- Write at least one test case per vendor-recognized type that registers a
  scratch ability with that type and asserts it appears in ONLY the matching
  composer output — not the others. F026 v2 added
  `test_for_server_tool_type_includes_only_tool_typed_public_abilities` etc.

**Where to look next**
- `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:141`
  (canonical vendor semantic)
- `includes/Database/MCPServer/AbilityDiscovery.php:59` (mirror)
- `tests/phpunit/Database/MCPServer/AbilityDiscoveryTest.php` (per-type coverage cases)

---

### 2026-07-15 - B31 — Vendor tool-name sanitization silently breaks slug-compare bypass constants

**Status**
Active — new bug pattern surfaced during F026 v3 refactor arc when the three built-in meta tools became first-class execution paths.

**Why this is durable**
When a plugin gate compares `$tool_name` (as passed to
`mcp_adapter_pre_tool_call`) against a constant list of "always-bypass" slugs,
it MUST account for vendor's `McpNameSanitizer::sanitize_name` which swaps `/`
→ `-` in ability slugs at MCP tool registration time
(`vendor/wordpress/mcp-adapter/includes/Domain/Utils/McpNameSanitizer.php:73`).
The client-facing tool name — what appears in `tools/list` and what the AI
client sends back on `tools/call` — is the HYPHEN form, while the raw ability
slug (registered via `wp_register_ability()`) is the SLASH form. Bypass
constants that list only the slash form never match.

**Symptom**
- Gate rejects a call with a `WP_Error` like `acrossai_mcp_tool_not_added`
  ("This tool is not enabled on this MCP server.") on a slug that's explicitly
  in the plugin's own "always-allowed" list.
- Symptom only manifests when the plugin's own code actually invokes the
  vendor-registered tool via `tools/call`. Pre-invocation, the tool exists in
  the vendor registry (indexed by the sanitized name) but the gate rejects it
  before dispatch.

**Root cause**
Vendor `RegisterAbilityAsMcpTool::build_tool_data()` (`vendor/.../Domain/Tools/
RegisterAbilityAsMcpTool.php:211`) calls
`McpNameSanitizer::sanitize_name($this->ability->get_name())` and stores the
sanitized result as the tool DTO's `name`. Vendor's `McpComponentRegistry::
add_mcp_tool()` keys `$mcp_tools[$sanitized_name] = $tool`. So the
end-to-end tool name — client-facing AND filter-facing — is the sanitized
form. F020 `ToolExposureGate::EXCLUDED_SLUGS` originally listed only the raw
form; bypass never matched; F020 denied all three built-in meta tools.

**Prevention**
- When authoring a gate that compares `$tool_name` against a bypass constant
  (or against a slug-set from the DB), list BOTH forms explicitly, or apply
  `McpNameSanitizer::sanitize_name` to the constant at compare time. Prefer
  the both-forms approach — it avoids vendor coupling at gate time and makes
  the intent explicit in the source.
- Write at least one test case per bypassed slug that calls the gate with
  the SANITIZED form and asserts it passes through. F020's test class
  (`tests/phpunit/MCP/ToolExposureGateTest.php`) does this per protocol slug.
- General principle: whenever plugin code compares a slug-derived string
  against a value that flows through a vendor-provided normalization
  pipeline, read the vendor's pipeline end-to-end before writing the
  compare. This gap survived undetected for months because pre-070ffe2
  nobody actually invoked the affected tools via `tools/call`.

**Where to look next**
- `vendor/wordpress/mcp-adapter/includes/Domain/Utils/McpNameSanitizer.php:73`
  (canonical sanitizer)
- `vendor/wordpress/mcp-adapter/includes/Domain/Tools/RegisterAbilityAsMcpTool.php:211`
  (where sanitization is applied)
- `includes/MCP/ToolExposureGate.php:55` (fixed EXCLUDED_SLUGS constant)
- `tests/phpunit/MCP/ToolExposureGateTest.php` (regression guard)
- Commit `69e689c` (the fix)

---

### 2026-07-15 - B32 — Filter defaults MUST express the plugin's canonical semantic (never a partial derivation)

**Status**
Active — new bug pattern surfaced during F026 v3 fix arc (commit `e0189b0`).

**Why this is durable**
When plugin code fires `apply_filters()` with a default value, that default
IS the authoritative expression of the plugin's semantic when no callback
intervenes. If the default is a partial derivation (e.g., "check a static
metadata flag" instead of "consult the canonical resolver"), consumers see
the SHORTCUT behavior — the correct behavior only kicks in if they know to
hook the filter and re-add the missing logic. This silently ignores any
higher-precedence rules (per-server overrides, deprecation shims, feature
flags) that the resolver would have honored.

**Symptom**
- Feature reads "correctly" when a canonical function is called directly
  (e.g., `ExposureResolver::resolve()` returns the right answer), but
  reads "incorrectly" through a filter-mediated path (e.g., a filter whose
  default is `meta.mcp.public` only).
- User-facing count / list is a strict subset of what the operator expects.
  Operator-configured per-server overrides are silently ignored.
- Distinguishing tell: adding a `var_dump` at the filter default computation
  shows the wrong value; removing/replacing that computation with a call to
  the canonical resolver fixes it.

**Root cause**
Filter author reasoned "the DEFAULT is the trivial static case; anyone who
wants richer behavior can hook the filter to add it". This works for opt-in
enhancements (color themes, formatting) but FAILS for enforcement semantics
(authorization, per-tenant visibility, per-server overrides) — those must
be baked into the default because the plugin owns the semantic, not the
caller.

**Prevention**
- When designing a filter that gates security or per-context enforcement
  decisions (visibility, exposure, ownership, capability), the DEFAULT MUST
  be the canonical resolver's output.
- If a canonical resolver exists in the plugin (e.g., `ExposureResolver::
  resolve()` per `DEC-ABILITY-OVERRIDE-RESOLUTION`), the filter default
  MUST call it — even if that seems redundant, because callers depending on
  the filter's decision cannot know the resolver exists.
- Write at least one test case that seeds the CANONICAL state (e.g., a
  per-server override row) and asserts the filter's output honors it
  WITHOUT any test-registered callback intervening. This is the exact case
  that catches the bug.
- **General principle**: filters exist to let callers ADD or MUTATE the
  plugin's decision, not to let the plugin outsource its decision to
  callers.

**Where to look next**
- `includes/Abilities/AbilityHelpers.php:61-108` (`apply_exposure_filter`
  post-fix — default = `ExposureResolver::resolve()`)
- `includes/Database/MCPServerAbility/ExposureResolver.php` (canonical
  resolver)
- Commit `e0189b0` (the fix)
- `tests/phpunit/Abilities/DiscoverTest.php` — cases
  `test_execute_includes_non_public_ability_with_f017_override_when_holder_set`
  and `test_execute_excludes_public_ability_with_f017_override_disabled_when_holder_set`
  (regression guards)
- `DEC-ABILITY-OVERRIDE-RESOLUTION` (the canonical-resolver principle this
  bug pattern violated)

---

### B33 — Admin gate keyed on a data field that a write path leaves empty by default silently falls open for every row created by that path

**Status**: Active (Feature 029 — 2026-07-18)
**Scope**: Any admin-facing security control (enable/disable toggle, approval gate, allow-list, capability check) whose resolution consults a column, meta field, or option value that could be legitimately empty. Includes F024's per-connector settings gating, but generalizes to every future admin control keyed on data.
**Tags**: `admin-gate, silent-bypass, data-model, connector_slug, f024, dcr, generalizable`

**Symptom**

An admin UI exposes a control (e.g., "Enable this connector? [ ]"). The control's runtime enforcement reads a data field on the resource being gated (e.g., `wp_acrossai_mcp_oauth_clients.connector_slug`) and looks up settings keyed by that value. When the resource-creation code path leaves the field empty by default (previously: `connector_slug => ''` hardcoded in `ClientRegistrationController::handle_register()` line 373), the gate lookup returns "no settings found" and falls open — the operator's toggle has no effect for that class of rows. No error, no admin notice, no log line — the gate silently doesn't apply.

**Root cause**

F024 (introduced with F021) added per-connector settings storage keyed on `connector_slug`. The admin-generator path (`handle_admin_generate`) populated `connector_slug` from the REST param. The DCR path (`handle_register`) hardcoded `connector_slug => ''`. F024's gate resolver was written assuming `connector_slug` was always populated; the empty-string case was never distinguished from "unknown connector" (which should surface an admin-visible state) — both fell through to "no gate applies". The `AuthorizationController::infer_slug_from_dcr_client()` helper was added later to work around this by walking the profile registry at `/authorize` time, but the persisted field remained empty, so DIRECT F024 gate consumers (e.g., a future report or CLI dumper that reads `connector_slug` directly) still saw the wrong picture.

**Decision (Prevention Recipe)**

For any admin control keyed on a data field:

1. **Every write path must populate the field**, or the resolver must have a documented explicit fallback that surfaces "no value" as an admin-visible state (an admin notice, a "unattributed" row on a report, a warning banner). Do NOT let the gate silently fall open on empty.
2. **When adding a write path after the fact** (e.g., DCR alongside admin-generate), audit every consumer of the gate for how it handles the empty case. If any consumer treats empty as "no gate applies", either populate the field at the new write path (F029's fix) or update the consumer to reject empty explicitly.
3. **Grep gate**: whenever a data field is added to an admin-gating resolver, `grep -rn '<field_name>' includes/` MUST return at least one populated write path per creation surface (admin, REST DCR, WP-CLI, etc.). Reviewer verifies every returned line writes a non-empty value.
4. **Test invariant**: any admin gate keyed on a data field MUST have at least one test case that creates a row via every documented creation path and asserts the gate resolves correctly for each. If the gate resolves "no settings apply" for any creation path, the test fails.

**Tradeoffs / Prevention**

- **Gained**: no more silent-bypass of admin gates; operators trust that toggles apply universally.
- **Made harder**: every future write path must remember to populate every admin-gating data field. Mitigation: this BUG entry + the grep gate above.
- **Reconsider**: this pattern generalizes beyond OAuth. Any admin gate on a data field (post_meta, term_meta, user_meta, options) is subject to it. Consider extracting into an ARCHITECTURE constraint if it recurs on a third feature.
- **Related**: `D18` (canonical `mcp_adapter_pre_tool_call` enforcement hook — a related gating architecture, gates on payload not persisted data so not vulnerable to this bug); `D20` (consumer-side default-provider registration hygiene — sibling "audit-before-wiring" pattern); `S9` (consent-surface server-side authoritative state — same underlying principle: don't trust missing data as absent, treat it explicitly).

**Evidence**

- `includes/OAuth/ClientRegistrationController.php:373` (pre-F029): `'connector_slug' => ''` hardcoded — the silent-bypass write path.
- `includes/OAuth/ClientRegistrationController.php:369-379` (post-F029): the profile-walk attribution block that populates `connector_slug` correctly.
- `includes/OAuth/AuthorizationController.php:497-510`: `infer_slug_from_dcr_client()` helper — a workaround at gate-consumer time that masked the bug from the /authorize path but left DIRECT consumers exposed.
- Runtime evidence: on `acrossai.co` pre-F029, every DCR-registered Claude client bypassed the F024 connector-enable toggle because their `connector_slug` was empty; the admin's "disable Claude" toggle had no effect for DCR-created rows.

**Where to look next**

Before adding a new admin control keyed on a data field, run the grep gate above. When reviewing any feature that adds a new creation surface for a resource with existing admin gates, verify every admin-gating field is populated. If you find an admin gate with empty-value tolerance today, either fix the write path or add a documented fallback that surfaces the "unattributed" state to the admin.

---

### B34 — Silent write-loss when live BerlinDB table drifts from Schema.php with matching db_version stamp

**Status**: Active (Feature 029 — 2026-07-18)
**Scope**: Any plugin-owned BerlinDB Kern\Table where `Schema.php` gained columns or bumped column widths without a paired `$version` bump + `$upgrades` callback. Applies to every future BerlinDB Table module in this plugin and every sibling plugin using the same base class.
**Tags**: `berlindb, schema-drift, silent-write-loss, wpdb-insert-returns-false, generalizable`

**Symptom**

An application-level INSERT (`$wpdb->insert()` / BerlinDB `Query::add_item()`) that references a column present in `Schema.php` but absent from the physical table returns `false`. The caller casts the return to `int(0)` (per `add_item()`'s existing shape) and treats it as a successful insert with row_id=0. The REST API returns a 200/201 with a "success" JSON payload containing data that was never persisted. No error log, no admin notice, no exception — total silent failure.

**Root cause**

BerlinDB `Table::maybe_upgrade()` only runs `dbDelta`-equivalent schema changes when the stored `db_version` (wp_options) differs from the declared `$version` (Table property) AND a matching `$upgrades` callback exists (`vendor/berlindb/core/src/Database/Kern/Table.php:982-1012` — see `D28`). When `Schema.php` gains a column but `$version` stays the same, `needs_upgrade()` returns false and the physical table is never touched. When `$version` is bumped WITHOUT a matching `$upgrades` callback, BerlinDB just stamps the new version and returns success without altering the schema. In both cases the drift persists forever, and every subsequent write that references the missing column silently returns `false`.

The F011 phantom-version guard (`if ( ! $this->exists() ) { delete_option( $this->db_version_key ); }` in `Table::maybe_upgrade()` overrides) only catches the "physical table missing" case — it does NOT detect column drift on an existing table.

**Real-world evidence**

- **`procureco.uk` OAuth outage** (fixed live via `DROP TABLE + recreate` before F029): `wp_acrossai_mcp_oauth_tokens` was missing columns declared in `OAuthTokens\Schema.php`. Token issuance at `/wp-json/acrossai-mcp-manager/v1/oauth/token` returned success, Claude.ai received a token response, but the token row was never persisted. Subsequent bearer usage failed at `TokenValidator` because the token hash didn't exist in the table.
- **`acrossai_mcp_servers`** — same shape, latent: `Schema.php` declares `tool_discover_abilities` / `tool_get_ability_info` / `tool_execute_ability` (F025) but installs on <1.1.1 code never grew the columns. F025 tool-selection reads returned undefined; writes silently no-op'd.
- **`acrossai_mcp_cli_auth_logs`** — same shape, latent: `Schema.php` declared four columns (`redirect_uri`, `code_challenge`, `code_challenge_method`, `scope`) that older installs never grew, plus three columns whose widths drifted (`status`, `failure_code`, `app_password_uuid`).

**Decision (Prevention Recipe)**

1. **Ship `D28`** — every Schema change on an existing table needs the 3-part coordinated change: `$version` bump + `$upgrades` entry + admin_init trigger.
2. **Post-release SQL sanity check** — for any table whose schema changed in a recent release, on any install that already existed pre-release, run:
   ```sql
   SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT
     FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = '<prefix>acrossai_<module>'
    ORDER BY ORDINAL_POSITION;
   ```
   Diff manually against `Schema.php`. If any column is missing OR widths differ OR defaults differ, the `$upgrades` callback didn't run — investigate (usually: version stamped but callback was missing).
3. **Emergency remediation** — if a live install is silently write-losing, the temporary fix is `DROP TABLE <table>` + reload any admin page to trigger `install()` on the fresh table. This obviously loses data. The permanent fix is the `D28` reconciliation callback running on the next admin request after upgrade.
4. **Grep gate on `Schema.php` diffs**: any PR that changes an `includes/Database/<Module>/Schema.php` MUST also touch the paired `Table.php` (`$version` + `$upgrades` diff). If a reviewer sees the Schema diff without the paired Table diff, block the PR.

**Tradeoffs / Prevention**

- **Gained**: Every future Schema evolution auto-reconciles on the next admin request; no operator action required; no "success" responses with lost data.
- **Made harder**: Reviewers must catch missing `$upgrades` entries at PR time; grep gate above.
- **Reconsider**: If a future BerlinDB Core release adds auto-diff-on-upgrade (Schema vs live table comparison), this bug pattern goes away — but until then it's a real, recurring hazard.
- **Related**: `D28` (the fix pattern); `F011 WORKLOG` (phantom-version guard — catches "table missing" only, NOT column drift); `B18` (BerlinDB TINYINT string return — different failure mode, same lesson: don't trust that vendor abstractions "just work" without vendor-source verification).

**Evidence**

- `procureco.uk` outage post-mortem (live fix): `wp_acrossai_mcp_oauth_tokens` schema drift, restored via drop + recreate.
- Pre-F029 `wp_acrossai_mcp_servers` sanity: `SHOW COLUMNS LIKE 'tool_%'` returned zero rows despite `Schema.php` declaring three.
- Pre-F029 `wp_acrossai_mcp_cli_auth_logs` sanity: 4 columns missing + 3 width mismatches vs `Schema.php`.
- `vendor/berlindb/core/src/Database/Kern/Table.php:988-993`:
  ```php
  if ( empty( $upgrades ) ) {
      $this->set_db_version();
      return true;
  }
  ```
  This is the exact code path that made version bumps a no-op when `$upgrades` was empty.
- Commit `479518e` — first attempt: bumped `MCPServer::$version` without a callback. Would have silently stamped the new version with columns still missing. Reviewer-caught. Commit `90cbdeb` — corrected using `$upgrades` per `D28`.

**Where to look next**

When onboarding a new BerlinDB Table module, run the `INFORMATION_SCHEMA` diff above once against a pre-release install to confirm no legacy drift exists. Before every release, `git diff` on all `Schema.php` files vs the last release tag — every changed file MUST have a matching `Table.php` `$version` + `$upgrades` change or the release is defective.

### 2026-07-20 - B35 — Filter-priority footrace on `wp_register_ability_args` callback-swap chains

**Status**
Active — Feature 030

**Why this is durable**

Filter priority is a load-order coincidence, not a durable authorization boundary. Any plugin registering above the highest existing consumer silently supersedes it. Documents the slot map so future consumers pick non-conflicting priorities and reviewers can spot ordering regressions at PR time.

**Failure mode**

When multiple plugins hook `wp_register_ability_args` with the intent of overriding `permission_callback`, the LAST-registered filter wins. Registration order is priority-based: lower priority number → runs first; higher → runs last and gets the final say.

Any silent addition of a new consumer at OR ABOVE the highest existing priority number silently subsumes every earlier consumer's decision. In F030's case, a rogue plugin registering at P1000000 could wrap F030's `true`-returning closure, restore the original `permission_callback` verdict, and thereby restore denials the operator explicitly toggled off — without any log entry or admin-visible signal.

**Current priority slot map** (last updated Feature 030):

- **P10** — `AcrossAI_MCP_Manager\Includes\Abilities\CallbackReplacer::replace_callbacks` — swaps callbacks for exactly three vendor abilities (`mcp-adapter/discover-abilities`, `.../get-ability-info`, `.../execute-ability`). Narrow scope; runs first so downstream consumers see the plugin-owned callback shape.
- **P100000** — sibling `acrossai-abilities-manager` plugin's `AcrossAI_Ability_Override_Processor::inject_override_args` — per-slug injector that reads DB-stored override rows and rebinds `permission_callback` when a rule exists for the ability slug.
- **P999999** — F030 `PermissionOverrideProcessor::inject_override` — per-server operator-opt-in bypass; MUST beat P100000 so operator's toggle wins over the sibling plugin's per-ability rules for MCP requests routed to the specific server.

**Prevention**

Before adding a new `wp_register_ability_args` hook, check this slot map. Pick a priority that intentionally orders relative to existing consumers:

- If the new consumer should NEVER be overridden by F030's operator toggle: register above P999999 AND update this entry with the new slot AND coordinate with the F030 semantic (does the new consumer respect the toggle or explicitly override it?).
- If the new consumer should be subject to F030's toggle: register below P999999.
- If the new consumer should be subject to the sibling plugin's per-ability rules: register below P100000.

If a fourth entrant emerges, consider consolidating into a `WpRegisterAbilityArgsPriorityMap` constants class (analog to `DEC-F020-TOOL-ENFORCEMENT-PRIORITY` for the `mcp_adapter_pre_tool_call` filter — same problem shape, same solution).

**Detection gap** (accepted follow-up)

F030 does NOT implement boot-time detection of conflicting registrations at ≥ P999999. Recommended follow-up feature: add a `plugins_loaded` P999999 hook that walks `$GLOBALS['wp_filter']['wp_register_ability_args']` and admin-notices any conflict. Non-blocking for F030 — installing a rogue plugin at P1000000 requires `install_plugins`, which is game-over territory anyway.

**Evidence**

- `includes/Main.php` — three Loader wire-ups: `CallbackReplacer` at 10, F030's `PermissionOverrideProcessor` at 999999.
- `../acrossai-abilities-manager/includes/Modules/Abilities/AcrossAI_Ability_Override_Processor.php:164` — sibling plugin's P100000 registration.
- `tests/phpunit/Abilities/PermissionOverrideIsolationTest::test_p999999_beats_p100000_denying_filter` — regression test asserting the ordering invariant.

**Where to look**

Any new file under `includes/` or `admin/` that emits `add_filter( 'wp_register_ability_args', ... )` in the diff — check the priority number against this slot map. Any diff that registers at or above P999999 needs a matching update to this entry AND a coordination note with F030 in the PR description.

### 2026-07-20 - B36 — Inline `<script>` string-interpolation requires `wp_json_encode()`, not `esc_html()`/`esc_attr()`

**Status**
Active — Feature 030 (generalizable beyond F030)

**Why this is durable**

`esc_html()` and `esc_attr()` are the default WP escaping habits and PHPCS wants to see them everywhere. But neither correctly encodes strings for a JavaScript string-literal context — both leave `'`, `"`, `\`, newline, and `</script>` un-escaped for a JS parser. Stored XSS by any user who can set the interpolated value (in F030's case, an admin who set a hostile `server_name` for an MCP server row).

**Failure mode**

Consider:

```php
$server_name = 'Server '; alert(document.cookie); //'; // stored in DB by an admin
printf(
    '<script>window.confirm("%s")</script>',
    esc_html( $server_name )  // ← WRONG: HTML-context escaper, not JS-context
);
```

Rendered HTML:
```html
<script>window.confirm("Server '; alert(document.cookie); //")</script>
```

`esc_html()` doesn't escape `'` — the JS parser sees `window.confirm("Server ");` followed by `alert(document.cookie);` followed by `//")`. XSS fires under any admin who visits the page.

`esc_attr()` fails the same way — it targets HTML attribute quoting, not JS string quoting. It escapes `<` and `>` but leaves `'`, `"`, `\`, and control chars alone.

**Correct pattern**

For ANY inline `<script>` block that interpolates a dynamic PHP value into a JavaScript string literal (`document.getElementById(?)`, `window.confirm(?)`, event-handler payloads, config object values, etc.), MUST use `wp_json_encode( $value )` for the interpolation.

```php
printf(
    '<script>window.confirm(%s)</script>',       // ← no quotes around %s — wp_json_encode adds them
    wp_json_encode( $server_name )
);
```

Rendered HTML:
```html
<script>window.confirm("Server '; alert(document.cookie); \/\/")</script>
```

`wp_json_encode()` produces a JSON-quoted string that is safe both as a JS literal AND as HTML content — JSON's `<` encoding for `<` prevents `</script>` breakout. The emitted literal INCLUDES the surrounding quotes; the `<script>` template must NOT wrap the placeholder in additional quotes.

**Grep gate** (candidate for pre-commit hook or PHPCS custom sniff):

```
grep -rn 'echo *"<script\|printf( *[\x27"]<script' includes/ admin/ public/ | grep -v 'wp_json_encode'
```

Any hit that interpolates a dynamic PHP value into a JS literal needs `wp_json_encode()`. Zero hits post-F030.

**Reference implementation**: `admin/Partials/ServerTabs/AccessControlTab.php:509-513` — both `$form_id` and `$confirm_msg` interpolated via `wp_json_encode()`. This was the SEC-030-001 ship-blocker in the F030 plan-phase security review, remediated in the implementation phase and verified in the staged review.

**Tradeoffs / Prevention**

- **Prevention**: Add the grep to the pre-commit checklist. When a dynamic value MUST reach JS, `wp_json_encode()` — no exceptions.
- **Note**: `wp_json_encode()` INCLUDES the surrounding quotes; the emitted literal is `"value"` not `value` — the `<script>` template should NOT wrap the placeholder in additional quotes. Common mistake when converting from `esc_html`-with-quotes to `wp_json_encode`-without.
- **Related**: `B22` (WordPress admin JS bundle contract — different failure mode but same "trust the WP escaping helper for the right context" lesson).

**Evidence**

- `docs/security-reviews/2026-07-20-030-per-server-permission-override-plan.md` §SEC-030-001 — original finding.
- `docs/security-reviews/2026-07-20-030-per-server-permission-override-staged.md` §SEC-030-001 — remediation verified.
- `admin/Partials/ServerTabs/AccessControlTab.php:509-513` — the fixed pattern in use.

**Where to look next**

Any new admin PHP that emits inline `<script>` with dynamic interpolation — run the grep gate. Any use of `wp_json_encode()` OUTSIDE of JS-context is fine (it's a superset of `esc_html`'s safety guarantees for the string-content case), but the reverse is dangerous.

---

## B37 / B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY

**Status**: Active — Feature 032; generalizable

**Where it happened**

Pre-F032 `wp_acrossai_mcp_oauth_clients` used `UNIQUE(client_id)` global. The admin-generated `client_id` convention `server-{id}-{slug}-{rand}` encoded server ownership as a string prefix — NOT as a first-class column. `ConnectorAdminController::handle_revoke_client_tokens` + `handle_delete_client` + `handle_revoke_connector_tokens` accepted only `client_id` in the request body and mutated the referenced row without cross-checking against the admin's current server context. An admin on Server A's Connectors tab could revoke or delete Server B's OAuth clients + tokens by modifying `client_id` in the outbound REST body. Same-shape read-side leak: `TokensQuery::get_active_user_ids_by_client_id()` returned users across every server holding tokens for the same `client_id`, which the AI Connectors tab displayed as the "authorized users" list — leaking Server B's user roster into Server A's admin surface. DCR-registered clients (Claude.ai / ChatGPT / Cursor / Cline) had no server binding at all, so the pattern was silently invisible for them.

**Root cause**

Multi-tenant admin endpoint accepted only a tenant-scoped identifier (`client_id`) without a matching tenant validation. Prefix parsing of `client_id` for ownership signal was (a) not schema-enforced, (b) not REST-validated, (c) inapplicable to DCR-registered clients that use random client_ids without the prefix.

**Prevention (grep gate — generalizable beyond OAuth)**

Any per-tenant admin REST endpoint MUST:
1. Require the tenant identifier (e.g., `server_id`) in the request body — never derive from a composite identifier string.
2. Look up the target row via composite key `(client_id, server_id)` — return 403 opaque on any mismatch.
3. Fire an observability action BEFORE the 403 return — 4-arg signature (NEVER include the actual owning tenant id, which would recreate the oracle the response body is protecting).
4. Include the tenant identifier as a first-class `NOT NULL` column on every table storing tenant-scoped data; do NOT encode it in a composite string.

Grep gate to run on any file under `includes/OAuth/` OR any file exposing a mutating REST endpoint with a tenant-scoped resource:

```bash
grep -rn 'get_param.*client_id\|get_param.*token' <file>
```

Every hit MUST be accompanied by a matching `server_id` (or equivalent tenant) extraction + validation before the referenced row is touched. Companion to `D28` (schema-drift reconciliation — the schema-migration counterpart) and to `D31 / DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS` (the SQL-level invariant this pattern enforces).

**Evidence**

- `docs/security-reviews/2026-07-21-032-oauth-per-server-scoping-plan.md` — v1 plan review that surfaced the gap.
- `docs/security-reviews/2026-07-21-032-oauth-per-server-scoping-plan-v2.md` — v2 verifying all 5 SEC-032-001 through SEC-032-005 findings closed.
- `includes/OAuth/ConnectorAdminController.php:175-259` — canonical remediation shape (handle_revoke_client_tokens + handle_delete_client with server_id validation + 4-arg observability fire).


---

## B38 / B-ADMIN-SELF-APPROVAL-AUDIT-TRAIL-AMBIGUITY

**Scope**: any admin-bypass path that auto-inserts into an existing "reviewer-approved" table without a discriminator field OR a distinct observability action.

**Why this is durable**

When an admin bypasses a "reviewer must approve" workflow via a self-service shortcut, the auto-inserted row into the approval table looks IDENTICAL to a row where another admin explicitly approved this user — both cases produce `approved_by = X AND user_id = X` when the reviewer happens to be reviewing themselves (path A) OR the user self-bypassed (path B). Forensic reviewers examining the audit trail post-incident cannot distinguish the two intents. Path A is a legitimate reviewer decision; path B is a self-service convenience. Conflating them poisons the forensic signal — an investigator asking "did anyone review this?" gets the wrong answer.

F032 FR-051 shipped the admin bypass without either a `bypass_reason` schema field OR a distinct observability action; SEC-L1 flagged the gap during the staged security review. Remediated by firing `acrossai_mcp_connector_admin_self_bypassed` — application-level fix chosen over schema-level (`bypass_reason` column) because the observability action is smaller diff, no D28 upgrade needed, and matches the plugin's existing observability pattern.

**Pattern to avoid**

Any future PR that adds an admin-only bypass to a workflow with a reviewer-approval semantic MUST ship EITHER:

1. **Schema-level discriminator** — a `bypass_reason ENUM(...)` or `source ENUM('explicit_review', 'admin_self_bypass')` column on the approval table, populated distinctly by the two code paths.
2. **Application-level observability** — a distinct `do_action` fired from the bypass branch with a 4-arg signature. Never emit only the shared "approval_granted" action from a self-bypass path.

Grep gate for any file introducing an admin bypass:

```bash
grep -rn 'user_can.*manage_options.*approve' includes/ admin/
```

Every hit MUST be immediately followed by EITHER a schema write to a discriminator column OR a distinct `do_action` fire that a forensic listener can differentiate from the explicit-review path.

**Evidence**

- `docs/security-reviews/2026-07-22-032-oauth-per-server-scoping-staged.md` — staged review that flagged the gap as SEC-L1.
- `includes/OAuth/AuthorizationController.php` — the FR-051 bypass branch + remediation fire site.
- `specs/032-oauth-per-server-scoping/contracts/php-hooks.md` — `acrossai_mcp_connector_admin_self_bypassed` action contract.
- `tests/phpunit/OAuth/AdminBypassTest.php` — verified test coverage that action fires ONLY on admin path, NOT on subscriber path.


---

## B39 / B-DYNAMIC-IN-CLAUSE-TRIGGERS-PHPCS-FALSE-POSITIVE

**Scope**: any `$wpdb->prepare()` invocation with a dynamically-built `IN()` clause.

**Why this is durable**

The WordPress-Coding-Standards `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` sniff cannot statically verify that `{$placeholders}` built via `implode( ',', array_fill( 0, count($items), '%s' ) )` is safe. Even when the interpolation is provably safe (every value flows through the same `prepare()` call), the sniff fires as a false-positive. Suppressing with `phpcs:ignore` + a defensive comment on every call site accumulates noise and hides real interpolation bugs the sniff was designed to catch.

**Pattern to avoid**

```php
// AVOID — dynamic IN() clause requires phpcs:ignore + defensive comment.
$placeholders = implode( ',', array_fill( 0, count( $client_ids ), '%s' ) );
$args = array_merge( array( $table, $user_id ), $client_ids );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built from array count.
$wpdb->query( $wpdb->prepare( "UPDATE %i WHERE user_id = %d AND client_id IN ({$placeholders})", $args ) );
```

**Pattern to prefer**

Extract a per-item helper that uses only static placeholders. Wrap it in a loop. Zero `phpcs:ignore`, no dynamic SQL, easier to reason about, per-call trace granularity.

```php
public function revoke_by_client_id_and_user_id( string $client_id, int $server_id, int $user_id ): array {
    // ... static placeholder query, PHPCS-clean.
}

public function revoke_by_user_and_server_and_client_ids( int $user_id, int $server_id, array $client_ids ): array {
    $all = array();
    foreach ( $client_ids as $client_id ) {
        $all = array_merge( $all, $this->revoke_by_client_id_and_user_id( $client_id, $server_id, $user_id ) );
    }
    return $all;
}
```

**When to accept the loop cost**: when `count($client_ids)` is bounded to double-digits (per-connector enumeration, per-user cascade). If the collection can grow unbounded (site-wide bulk operations), reconsider — a single UPDATE via a temp table or a chunked static query may be needed.

**Grep gate**

```bash
grep -rn 'IN\s*({\$' --include='*.php' includes/
```

Every hit MUST be either (a) refactored to a per-item loop OR (b) justified in a docblock explaining why the dynamic clause is unavoidable + a defensive test that seeds a `';DROP TABLE'`-shaped input and asserts safe handling.

**Reference impl**: `includes/Database/OAuthTokens/Query.php::revoke_by_client_id_and_user_id` + `revoke_by_user_and_server_and_client_ids`.

**Evidence**

- `docs/security-reviews/2026-07-22-032-oauth-per-server-scoping-staged.md` — SEC review's "Confirmed Secure Patterns" table cites this refactor.
- `includes/Database/OAuthTokens/Query.php` — canonical per-item loop implementation.

## B40 / B-WRAPPER-CLOSURE-MUST-FORWARD-ARGS-AND-PRESERVE-WP-ERROR

**Scope**: any closure that wraps a user callback (`permission_callback`, `execute_callback`, filter/action callback, decorator around a stored callable).

**Status**: Active — Feature 033 (generalizable beyond F030).

**Why this is durable**

Two silent-failure mistakes in the same file combine into a catastrophic security bypass. Both look reasonable in code review when read in isolation. Documenting the combined pattern makes future callback-wrapping code either avoid the shape or self-audit. F030 shipped with both bugs and let any authenticated user (down to `subscriber`) invoke any registered ability via `mcp-adapter/execute-ability` on the default MCP server, bypassing the Abilities-tab exposure gate entirely.

**Failure mode**

1. **Args silently dropped.** A wrapping closure declared as `static function () use ( ... )` (zero parameters) silently discards every argument the caller passes. `call_user_func( $original )` inside the closure then invokes the original with no args, causing callbacks that inspect `$input` (e.g. `Execute::check_permission` reading `$input['ability_name']`) to enter the "input missing" branch — which typically returns `WP_Error`.

2. **`WP_Error` coerced to `true`.** `return (bool) call_user_func( $original );` casts the resulting `WP_Error` object to boolean `true` (all PHP objects cast to true). The vendor's `if ( true !== $permission )` check in `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:148` reads it as "permission granted" and proceeds to `execute()`.

**Combined effect**: bug 1 FORCES the callback into a `WP_Error` branch it wouldn't otherwise take, bug 2 HIDES the `WP_Error` from the vendor. Live-reproduced with `acrossai-abilities-manager/site-title-get` (`is_exposed=0` in `wp_acrossai_mcp_server_abilities`) as a subscriber-level user: response was 200 with the site title, not the expected 403.

Fixing only one leg leaves the bypass open:

- **Only fix (1)** → original callback returns `WP_Error(acrossai_mcp_ability_not_exposed_for_server)`; bug (2) still coerces it to `true`.
- **Only fix (2)** → original callback still sees empty input, returns `WP_Error(missing_ability_name)`; vendor now denies but the callback's intended input-driven logic never runs — subtle misbehaviour that hides the underlying bug.

**Pattern to avoid**

```php
// AVOID — closure accepts no args, silently drops caller input.
// AVOID — (bool) cast converts WP_Error into true, defeats vendor's `if ( true !== $permission )` check.
$args['permission_callback'] = static function () use ( $slug, $original ) {
    // ...six-layer check...
    return self::call_original( $original );
};

private static function call_original( $original ): bool {
    if ( is_callable( $original ) ) {
        return (bool) call_user_func( $original );
    }
    return false;
}
```

**Pattern to prefer**

```php
// Variadic + forward args; preserve WP_Error before any bool coercion.
$args['permission_callback'] = static function ( ...$callback_args ) use ( $slug, $original ) {
    // ...six-layer check...
    return self::call_original( $original, $callback_args );
};

private static function call_original( $original, array $args = array() ) {
    if ( ! is_callable( $original ) ) {
        return false;
    }
    $result = call_user_func_array( $original, $args );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    return (bool) $result;
}
```

**Grep gates**

```bash
# Callback-wrapping closures declared parameterless — any hit inside a *_callback filter installer needs review.
grep -rn "static function () use\|function () use" includes/ admin/ | grep -iE "permission|callback|filter|action"

# Bool casts around callback invocations — any hit inside a wrapping helper needs an is_wp_error() guard immediately before.
grep -rn "(bool) call_user_func" includes/ admin/
```

Every hit needs either (a) accept `...$args` and forward via `call_user_func_array`, or (b) an explicit code comment explaining why the wrapper is intentionally parameterless AND why WP_Error coercion is safe in that specific context.

**Reference impl**

- `includes/Abilities/PermissionOverrideProcessor.php:116` — variadic closure signature (post-fix).
- `includes/Abilities/PermissionOverrideProcessor.php:201-210` — `call_original` preserves `WP_Error`.

**Evidence**

- `tests/phpunit/Abilities/PermissionOverrideProcessorTest.php` — three regression tests: args forwarding, WP_Error preservation, role-parameterised end-to-end across `subscriber` / `contributor` / `author` / `editor` / `administrator`.
- `docs/planings-tasks/033-f030-permission-callback-wrapper-fix.md` — full reproduction + root cause trace.
- PR #45 — the fix commit + regression tests.
- Vendor boundary that the coercion silently defeats: `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:148`.

**Where to look**

Any diff that adds a filter callback which wraps another callback in a closure. Includes but not limited to: `wp_register_ability_args`, `wp_authenticate*`, `rest_pre_dispatch`, `pre_do_shortcode_tag`, decorator classes that stash an original callable and re-invoke it later, plugin bridges.

**Related**

- B35 — F030 filter-priority footrace (same file, adjacent concern).
- D29 — F030 six-layer bypass decision (this bug lived inside the six-layer implementation).
- B32 — Filter defaults MUST use canonical resolver (similar "silent" security-adjacent pattern in the same subsystem).


---

### 2026-08-01 - B43 — Loader signature mismatch: `$loader->add_filter($hook, array($this, 'method'), $priority, $args)` fatals at fire time

**Status**
Active

**Symptoms**
Admin page (or any page firing the mis-registered filter/action) crashes with:

```
Fatal error: Uncaught TypeError: call_user_func_array(): Argument #1 ($callback)
must be a valid callback, first array member is not a valid class name or object
in wp-includes/class-wp-hook.php:343
```

The error appears at *fire time*, not at *registration time* — the plugin activates cleanly, and only later when the filter/action actually fires (often when a specific admin screen loads) does the fatal happen. Grep-visible in the stack trace as `apply_filters('<filter-name>', ...)` or `do_action('<action-name>', ...)` calling into `WP_Hook->apply_filters()`.

**Root cause**
Copy-paste habit: developer used the **native WP `add_filter()` signature** (`$hook, $callback, $priority, $accepted_args`) when calling the plugin's own **Loader class**, whose signature is DIFFERENT: `$hook, $component, $callback_string, $priority, $accepted_args` (component and callback method name are two separate positional args, not a combined array).

Bad (compiles cleanly, fatals at fire time):
```php
$this->loader->add_filter( 'my_hook', array( $this, 'my_method' ), 10, 2 );
//                                    ^^^^^^^^^^^^^^^^^^^^^^^^^^^^   ^^  ^
// Interpreted by Loader as $component ─────────────────────────────  |   |
// Interpreted by Loader as $callback (method name string) ──────────┘   |
// Interpreted by Loader as $priority ──────────────────────────────────┘
// $accepted_args defaults to 1
```

When `Loader::run()` fires, it emits:
```php
add_filter( 'my_hook', array( $component, $callback ), $priority, $accepted_args );
// which becomes:
add_filter( 'my_hook', array( array( $this, 'my_method' ), 10 ), 2, 1 );
//                     ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
// A 2-element callback array where the FIRST element is another array
// and the second is an integer — invalid for call_user_func_array
```

Right:
```php
$this->loader->add_filter( 'my_hook', $this, 'my_method', 10, 2 );
```

The class-string variant works the same way:
```php
// Static call — component is a class-string, callback is the static-method name.
$this->loader->add_action( 'my_hook', SomeClass::class, 'static_method', 10, 4 );
```

**Discovered by**
Feature 040 (2026-08-01) — the AI Connectors companion plugin's tab registration on `acrossai_mcp_manager_server_tabs` fatalled the moment a site admin loaded the server-edit screen (which is where `Registry::visible_tabs()` fires the filter). The bug had been latent in the companion since the tab-injection code was written, hidden by mcp-manager still owning the OAuth stack and the companion's self-disable probe short-circuiting `bootstrap_oauth_hooks()` — so this Loader call never actually executed until F040 flipped ownership.

**Future mistake prevented**
Any add_filter/add_action call routed through the plugin's own `Loader` class MUST use the 5-arg positional shape `( $hook, $component, $callback_string, $priority, $accepted_args )` — never the WP-native 4-arg `array(...)`-callback shape. This is a signature-shape difference between the abstraction layer and the WP core function it eventually delegates to, and the two shapes silently compose incorrectly (no PHP warning at registration time — the fatal only fires when the hook fires).

**Prevention / Detection**

Grep gate at review time:
```bash
# Any Loader::add_filter / add_action call whose 2nd arg is an inline array() → wrong shape.
grep -rEn '\$this->loader->add_(action|filter)\s*\(\s*[^,]+,\s*array\s*\(' \
  --include='*.php' includes/ admin/ public/
```

Every hit needs refactoring to separate the `$component` and `$callback` args:
```
# BEFORE
$this->loader->add_filter( 'hook', array( $this, 'method' ), 10, 2 );
# AFTER
$this->loader->add_filter( 'hook', $this, 'method', 10, 2 );
```

Runtime detection (belt-and-suspenders — if the Loader is your own code, you can add this):
```php
// In Loader::add() — reject the wrong shape at registration time, not fire time.
private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
    if ( is_array( $component ) ) {
        _doing_it_wrong(
            __METHOD__,
            esc_html__(
                'Loader::add_filter/add_action received an array as $component; expected an object or class-string. Did you use the native WP add_filter( $hook, array( $this, "method" ), ... ) shape instead of the Loader shape $hook, $this, "method", $priority, $args?',
                'text-domain'
            ),
            '1.0.0'
        );
        return $hooks;
    }
    if ( ! is_string( $callback ) ) {
        _doing_it_wrong(
            __METHOD__,
            esc_html__( 'Loader::add_filter/add_action $callback MUST be a method-name string.', 'text-domain' ),
            '1.0.0'
        );
        return $hooks;
    }
    // ...normal storage...
}
```

**Reference impl**

- `wp-content/plugins/acrossai-ai-connectors/includes/Main.php:709` — pre-fix WRONG form (kept in git history for reference; superseded by fix).
- `wp-content/plugins/acrossai-ai-connectors/includes/Main.php:718` (post-fix) — CORRECT 5-arg form with an inline docblock explaining the footgun.
- `wp-content/plugins/acrossai-ai-connectors/includes/Loader.php:94-110` — the Loader signature that both plugins use.
- `wp-content/plugins/acrossai-mcp-manager/includes/Main.php` — every `$this->loader->add_action/add_filter` call is the reference form for how to call it correctly (many valid examples in that file).

**Evidence**

- Production fatal: loading `?page=acrossai_mcp_manager&action=edit&server=1` after F040 activation.
- Root cause verified: matched the reported "first array member is not a valid class name or object" TypeError to the specific Loader-call misuse via stack-trace analysis (Registry.php:151 apply_filters → WP_Hook::apply_filters → call_user_func_array on the malformed callback).
- Fix commit: single-line edit at `acrossai-ai-connectors/includes/Main.php:709` — removed the `array(...)` wrapper, split into separate `$this, 'register_ai_connectors_tab'` positional args.

**Where to look**

Any plugin that has its own Loader/HookRegistry/Dispatcher abstraction around WP's `add_action`/`add_filter`. Common shapes for this abstraction:
- **AcrossAI convention (both plugins)**: 5-arg `( $hook, $component, $callback, $priority, $accepted_args )` — the pattern this bug hit.
- **Some vendor plugins**: 4-arg `( $hook, $callback, $priority, $accepted_args )` (matches WP native).
- **WooCommerce-style event dispatchers**: `->on( $event, $listener )` — no positional args at all.

Whenever you introduce a Loader abstraction, decide on ONE signature and enforce it (grep gate + runtime `_doing_it_wrong` guard). Mixing signatures across the codebase because "some places use the WP native shape and some use ours" is a recipe for B43-class fatals that only surface at hook-fire time.

**Related**

- B42 (2026-07-31) — the OTHER latent bug F040 surfaced (same "probe hides latent bug" meta-pattern).
- PATTERN-PROBE-HIDES-LATENT-BUG (WORKLOG 2026-08-01) — when a defense-in-depth guard is retired, expect a shakedown of previously-unreachable buggy paths. B42 and B43 are the first two witnesses.

---

### 2026-07-31 - B42 — `add_rewrite_rule()` on `plugins_loaded` fatals because `$wp_rewrite` is not yet initialized

**Status**
Active

**Symptoms**
Fatal error the moment a plugin's `plugins_loaded` handler fires:

```
Fatal error: Uncaught Error: Call to a member function add_rule() on null
in /wp-includes/rewrite.php:143
Stack trace:
#0 <plugin>/OAuthRouter.php(N): add_rewrite_rule('^\\.well-known/o...', 'index.php?...', 'top')
#1 <plugin>/Main.php(M): <Class>->register_rewrite_rules()
#2 <plugin>/Main.php(M): <Class>->maybe_run_first_run_migration('')
#3 wp-includes/class-wp-hook.php: WP_Hook->do_action(Array)
#4 wp-includes/plugin.php: do_action('plugins_loaded')
```

**Root cause**
`add_rewrite_rule()` is a wrapper around `$wp_rewrite->add_rule( $regex, $query, $after )`. The `$wp_rewrite` global (an instance of `WP_Rewrite`) is instantiated in `wp-settings.php` at the line `$GLOBALS['wp_rewrite'] = new WP_Rewrite();` — **which fires AFTER `do_action( 'plugins_loaded' )`** in the WP bootstrap sequence. Any plugin that calls `add_rewrite_rule()` (directly or transitively via a router/registrar class) during `plugins_loaded` hits `$wp_rewrite->add_rule(...)` on a `null` receiver and fatals.

The bug is easy to write and easy to miss: the WP Codex says "call `add_rewrite_rule()` after `init`" but doesn't spell out WHY, and the fatal only manifests when the calling code path is actually reached — so a probe-guarded handler (like the AI Connectors self-disable probe) can hide the bug for months until the probe stops guarding.

**Discovered by**
Feature 040 (2026-07-31) — deleted `AuthorizationController.php` from mcp-manager, which flipped the AI Connectors companion plugin's `mcp_manager_still_owns_oauth()` probe from `true` → `false` for the first time in production. The probe had been guarding the companion's `maybe_run_first_run_migration()` on `plugins_loaded @ 20`, which internally called `OAuthRouter::instance()->register_rewrite_rules()` → `add_rewrite_rule()` → fatal.

**Future mistake prevented**
Any plugin file that calls `add_rewrite_rule()` (or any function that does — `add_rewrite_endpoint`, `add_rewrite_tag`, `flush_rewrite_rules`, `wp_add_rewrite_rules`, etc.) MUST be wired to a hook that fires **on or after `init`**. Never `muplugins_loaded`, `plugins_loaded`, `setup_theme`, or `after_setup_theme` — every one of those runs before `$wp_rewrite` exists.

**Prevention / Detection**

Grep gate at review time:

```bash
# Every handler that ends up calling add_rewrite_rule() (or the router that wraps it) MUST be wired to init or later.
grep -rEn "add_action\s*\(\s*['\"](muplugins_loaded|plugins_loaded|setup_theme|after_setup_theme)['\"]" \
  --include='*.php' includes/ admin/ public/ | while read -r line; do
  file="${line%%:*}"
  method=$(echo "$line" | grep -oE "array[^)]+" | tail -1)
  # Trace whether the handler calls register_rewrite_rules / add_rewrite_rule
  echo "REVIEW: $line"
done

# Direct-call detection — any add_rewrite_rule outside an init-or-later context.
grep -rEn 'add_rewrite_rule\s*\(' --include='*.php' includes/ admin/ public/
```

Every hit needs verification that the enclosing method is either (a) called only from `init` (or later hooks: `wp_loaded`, `template_redirect`, admin-init, REST-init, etc.), (b) called from an activation/deactivation hook (WP re-bootstraps to `init` before firing those), or (c) called from a WP-CLI command context (where `$wp_rewrite` is set up during CLI bootstrap).

Runtime detection via WP_DEBUG:

```php
// In any wrapper method that calls add_rewrite_rule():
global $wp_rewrite;
if ( ! ( $wp_rewrite instanceof \WP_Rewrite ) ) {
    _doing_it_wrong(
        __METHOD__,
        esc_html__( 'add_rewrite_rule() called before $wp_rewrite is initialized. Wire this to init or later.', 'text-domain' ),
        '1.0.0'
    );
    return;
}
```

**Fix pattern**

The correct hook is **`init` at any priority** (WP core sets up `$wp_rewrite` at init@0). If your handler also depends on other init@10 handlers having already fired (e.g. it wants to `flush_rewrite_rules()` and other plugins are registering rules on init@10), wire to **`init` at a priority ≥ 11** (recommended: `init @ 999`).

Before (broken):

```php
add_action( 'plugins_loaded', array( $this, 'maybe_run_first_run_migration' ), 20 );

public function maybe_run_first_run_migration(): void {
    // ...version-diff check...
    OAuthRouter::instance()->register_rewrite_rules();  // ← fatal: $wp_rewrite is null
    flush_rewrite_rules();
}
```

After (correct):

```php
// init@999 — $wp_rewrite is ready AND any init@10 rule registrations
// (including this plugin's own bootstrap_oauth_hooks() wiring) have run.
add_action( 'init', array( $this, 'maybe_run_first_run_migration' ), 999 );

public function maybe_run_first_run_migration(): void {
    // ...version-diff check...
    // No need to call register_rewrite_rules() ourselves — bootstrap_oauth_hooks()
    // has already wired it on init@10 via the Loader. Just flush.
    flush_rewrite_rules();
}
```

**Reference impl**

- `wp-content/plugins/acrossai-ai-connectors/includes/Main.php:264` — post-fix `add_action('init', ..., 999)` wiring.
- `wp-content/plugins/acrossai-ai-connectors/includes/Main.php:~600` — post-fix migration method (`register_rewrite_rules()` line deleted; `flush_rewrite_rules()` retained).

**Evidence**

- Production incident: activating the companion plugin post-Feature-040 fatals during `plugins_loaded` before the plugins page renders.
- Root cause verified via `wp-includes/rewrite.php` line 143 (`$wp_rewrite->add_rule(...)`) + `wp-settings.php` bootstrap ordering (`$GLOBALS['wp_rewrite']` instantiated after `do_action('plugins_loaded')`).
- Fix commit: `wp-content/plugins/acrossai-ai-connectors/includes/Main.php` — 2 edits (hook change + delete redundant register call).

**Where to look**

Any plugin or theme file registering a WordPress hook whose handler ends up in a rewrite-rules chain. Common footguns beyond `plugins_loaded`:
- `muplugins_loaded` (fires even earlier).
- `setup_theme` / `after_setup_theme` (both fire before `$wp_rewrite`).
- `plugin_loaded` for a specific plugin file (still pre-init).
- Constructors of singletons wired via `plugins_loaded` — if the constructor calls `add_rewrite_rule()`, same fatal.
- First-run / version-migration handlers — these are the most common pattern that trips B42 because they naturally want to fire "early" to reconcile state before user code runs, but rewrite rules are a special case that must wait.

**Related**

- Prior WordPress-timing bug patterns in this codebase — none directly, but the shape (probe hides latent bug until probe deactivates) generalizes to any long-lived defense-in-depth guard.
- Feature 040 WORKLOG entry (2026-07-31) — the migration that discovered this bug.

---

### 2026-07-29 - B41 — Cross-user enumeration via target-user-id parameter

**Pattern**
Any public primitive that accepts a target user_id parameter and returns per-user access-control state (or per-user data derived from AC rules) MUST document caller-authority responsibility in the class-level docblock. Consumers passing an arbitrary `$target_user_id` MUST independently verify the current viewer's authority to see that user's information.

**Failure Mode**
The primitive evaluates its own gates FOR the target user, NOT against the calling viewer's authority. If a consumer plugin (BuddyPress profile widget, WooCommerce endpoint, WPUM member page) passes any user_id from a URL/session without gating the CALLER first, any logged-in visitor can enumerate what other users can access — leaking access-control policy meta-information without needing admin capabilities.

**Prevention**
- Docblock section on the primitive's class MUST warn about caller-authority. Suggested title: "Caller-authority responsibility (SEC-001)". Naming conventions cited alongside "SEC-001" or "cross-user enumeration" so IDE grep + code-review find it.
- Grep gate for future features: `grep -rEn 'function.*\?int \$(?:target_)?user_id.*array' public/` — every hit MUST have a matching docblock section warning about caller-authority.
- Contract file MUST include a "Caller-authority responsibility" subsection AND a matching test asserting the docblock text is present in the source (defensive against maintenance PRs that trim the docblock).

**Reference impl**
`public/Renderers/UserServers/AbstractUserServersRenderer.php` (F038) — SEC-001 subsection in the class-level docblock, `contracts/AbstractUserServersRenderer.contract.md` §Caller-authority responsibility.

**Related**
- `D40 / DEC-USER-SCOPED-ENUMERATION-COMPOSES-GATES` (F038 architectural companion — same feature, this is the prevention side)
- SEC-001 from `docs/security-reviews/2026-07-29-037-user-accessible-mcp-servers-shortcode-plan.md`


---


### 2026-08-02 - B44 — BerlinDB Table subclass added but uninstall.php DROP list not updated → orphaned table on uninstall

**Status**
Active

**Symptoms**
Operator deletes the plugin (with `acrossai_mcp_uninstall_delete_data=1`); most tables are dropped, but one specific table (whose Table subclass was added in a recent feature) survives. No error, no warning — the operator sees a mostly-clean uninstall and only notices the orphaned table on `SHOW TABLES` or during a fresh reinstall.

**Root cause**
`uninstall.php` maintains a `$tables` array of literal table names to DROP. When a new BerlinDB `Database/<Module>/Table.php` subclass is added (with its own `protected $name = 'acrossai_mcp_...'`), the table is created on activation via `maybe_upgrade()` — but there is no compile-time coupling between the Table subclass and the uninstall DROP list. If the PR author forgets to add a matching line to `uninstall.php`, the table becomes an orphan on uninstall and there's no test that catches it.

**Discovered by**
F037 MCPServerMeta table (added 2026-07-28) survived uninstall for weeks. Surfaced 2026-08-02 during F040 uninstall verification.

**Future mistake prevented**
Every new `includes/Database/<Module>/Table.php` PR MUST add a matching line to `uninstall.php`'s `$tables` array. This is easy to forget because the Table subclass appears self-contained; the uninstall coupling is invisible from the Table's code.

**Prevention / Detection**

Grep gate at PR review:

```bash
# Extract every BerlinDB Table $name and verify each is in uninstall.php's DROP list.
for name in $(grep -rEh "protected \\\$name\s*=\s*'acrossai_" includes/Database/ | \
              grep -oE "'acrossai_[^']+'" | tr -d "'"); do
  grep -q "$name" uninstall.php || echo "MISSING FROM UNINSTALL: $name"
done
# Expected: no output. Any hit is a bug — add to uninstall.php's $tables array.
```

Test-based gate (recommended — add once to `tests/phpunit/Uninstall/UninstallSweepBoundaryTest.php`):

```php
public function test_every_berlindb_table_is_in_uninstall_drop_list(): void {
    $uninstall_contents = file_get_contents( ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'uninstall.php' );
    $table_files        = glob( ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'includes/Database/*/Table.php' );
    foreach ( $table_files as $file ) {
        $contents = file_get_contents( $file );
        if ( preg_match( "/protected \\\$name\s*=\s*'([^']+)'/", $contents, $m ) ) {
            $this->assertStringContainsString(
                $m[1],
                $uninstall_contents,
                "BerlinDB Table {$m[1]} (from {$file}) MUST be in uninstall.php's \$tables array"
            );
        }
    }
}
```

**Reference impl** (post-fix): `uninstall.php:60` — `acrossai_mcp_servers_meta` added 2026-08-02.

**Related**
- B34 (BerlinDB silent write-loss on schema drift — same "add table but forget to reconcile" class of coverage gap).
- PATTERN-COMPILE-TIME-COUPLING-MISSING generalizes both B34 and B44: when TWO artifacts must stay in sync, either enforce it at compile-time (a shared registry both consult), or enforce it via a test/grep gate. Never rely on PR reviewer memory.

---

### 2026-08-19 - B45 / B-WP-SET-TRANSIENT-FALSE-ON-UNCHANGED

**Pattern**
`set_transient( $key, $value, $ttl )` returns `false` when the new `$value` equals the currently-stored value — a "no change" signal, NOT a store-write failure. Caused by delegation to `update_option()` when no external object cache is configured (`wp-includes/option.php`). Callers that treat the bool return as "success/failed" surface spurious failure errors any time the payload happens to be identical to what's already stored.

**Affected area**
Any code writing to `wp_options` via `set_transient()`, `update_option()`, `update_user_meta()`, `update_post_meta()`, `update_term_meta()`, `update_site_option()` — every option/meta writer that treats identical-value writes as failures.

**Symptom**
User re-clicks a "save" or "advance" button in an admin surface; POST handler surfaces a generic `persist_failed` / 500 error even though side-effects earlier in the same handler (DB inserts, external API calls) succeeded. The failure only reproduces when the second write's payload matches the first — passes every "initial write" test.

**Discovered in**
F069 Phase 9 (2026-08-19). `QuickSetupController::write_scratchpad()` returned false on the wizard's second identical `POST /step` when the user re-clicked "Enable all and continue" after the abilities were already all enabled. Backend upserts had already run; only the scratchpad-write signal was spurious. Fixed with read-back verification.

**Fix**
```php
private function write_scratchpad( array $data ): bool {
    $key = self::SCRATCHPAD_KEY_PREFIX . get_current_user_id();
    if ( set_transient( $key, $data, self::SCRATCHPAD_TTL ) ) {
        return true;
    }
    // No-change branch — verify by read-back.
    $stored = get_transient( $key );
    return is_array( $stored ) && $stored === $data;
}
```

**Prevention**
- **Grep gate**: any `if ( ! set_transient(` OR `if ( false === set_transient(` OR `if ( ! update_option(` should be paired with a read-back fallback, OR justified with a comment (e.g., "value is a monotonically-increasing counter, no-change is impossible").
- **Regression test**: for any handler that writes a per-user/per-entity scratchpad, add a test that POSTs the same payload twice — second call must return 200 (or the same success status as the first).

**Related**
- Similar silent-signal class: B18 (`$wpdb` returns TINYINT as string — `1 === $col` always false).
- WP core issue tracking this behavior: https://core.trac.wordpress.org/ticket/22192 (no-change return semantic documented but "won't fix").

---

### 2026-08-19 - B46 / B-INITIAL-LOADING-GATE-UNMOUNTS-STEPS-ON-REFETCH

**Pattern**
A React admin-surface component with a global loading gate — `if ( state.status === 'loading' ) return <FullScreenSpinner />` — will unmount whatever step/screen the user is on EVERY time a refetch fires (visibility change, focus, popstate, or a step-level `useEffect(() => refetch(), [])`). The step remounts when the refetch resolves, losing local form state + scroll position + re-firing per-step mount effects. If the step's mount effect fires a refetch, this loops.

**Affected area**
Any React admin surface (wizard, DataViews screen, multi-tab editor) with (a) a global loading store shared across steps AND (b) a shell-level "full screen loading" gate that fires on ANY `loading` status. Especially dangerous when combined with `visibilitychange`/`focus`/`popstate` refetch listeners.

**Symptom**
- User tabs away from wizard and back → wizard "flashes" back to the loading icon → step content re-appears (scroll reset to top, in-progress form input lost).
- OR: a specific step renders as a blank white page indefinitely (infinite unmount/remount loop between step + full-screen spinner).

**Discovered in**
F069 Phase 9 (2026-08-19). Step 8 (Pro promo pitch) and Step 9 (Pro activate gate) each had `useEffect(() => refetch(), [])`. Refetch → `state.status='loading'` → App.jsx early-returned to `<div class="qs__initial-loading">` → step 8 unmounted → refetch resolved → step 8 remounted → its mount effect fired refetch again → loop. User saw a blank white page with just the loading icon (or nothing depending on timing).

**Fix**
Distinguish "first-ever hydrate" from "subsequent refetch" via a ref-based flag; only early-return to the full-screen loader on the true cold start. Subsequent refetches leave the tree mounted — a shell-level "busy" overlay handles the loading UI without disturbing the current step.

```jsx
const hasHydratedOnceRef = useRef( false );
if ( state.status === 'ready' ) {
    hasHydratedOnceRef.current = true;
}

if (
    state.status === 'idle' ||
    ( state.status === 'loading' && ! hasHydratedOnceRef.current )
) {
    return <FullScreenLoadingIcon />;
}
```

Reference impl: `src/js/quick-setup/App.jsx:hasHydratedOnceRef`.

**Prevention**
- Never early-return to a full-screen loader based on a global `loading` state alone. Always gate on `idle-or-first-load` semantics.
- Global refetch listeners (visibilitychange/focus/popstate) MUST be paired with a "loading overlay renders ON TOP of current UI" pattern, not "loading state replaces current UI".
- Per-step `useEffect(() => refetch(), [])` is a code smell — if the underlying store already has global refetch listeners, delete the per-step refetch. If it doesn't, add them.

**Related**
- Composition partner: full-screen overlay (`StepLayout`'s `busy` prop) that renders above but doesn't replace the current step.
- General principle: React unmount is destructive; if a state transition should NOT lose in-progress work, don't gate rendering on that transition.

---

### 2026-08-19 - B47 / B-WORDPRESS-ORG-DOTFILE-ASSETS-NOT-HTTP-ACCESSIBLE

**Pattern**
Every WordPress plugin ships assets in `.wordpress-org/` (icon.svg, banner-*.png, screenshot-*.png) for the wp.org plugin directory listing. Common assumption: these files are HTTP-accessible via `PLUGIN_URL . '.wordpress-org/icon.svg'`. Wrong. Many hosts (Apache with default `AllowOverride`, most managed WP hosts, WP.com, WP-Engine) block dotfile directories at the web-server level. The files exist on disk (verified via `ls -la`) but 404 via HTTP.

**Affected area**
Any plugin runtime code (JS bootstrap, PHP admin renders, email templates, REST responses) that constructs an HTTP URL to a file in `.wordpress-org/`.

**Symptom**
- Broken-image icon rendered in place of the expected asset (browser DevTools shows 404).
- Silent no-render for background-image CSS references.
- Test on developer machine (LocalWP with default Nginx config) succeeds; production breaks.

**Discovered in**
F069 Phase 9 (2026-08-19). Wizard initial-loading screen referenced `.wordpress-org/icon.svg` via a new `iconUrl` in the `wp_localize_script` bootstrap. LocalWP dev showed the icon fine; production installs would have rendered a broken-image glyph.

**Fix**
- Copy the file into `assets/` (a served-by-default directory in every WP hosting configuration).
- Update bootstrap URL to point to the served copy.
- Add a comment on the served copy noting the `.wordpress-org/` source-of-truth so both stay in sync.

```php
// F069 — Kept at assets/quick-setup/icon.svg (a direct copy of
// .wordpress-org/icon.svg — that dotfile directory is routinely blocked
// at the host / Apache level, so pointing the browser there 404s on real
// installs). When updating the icon, replace BOTH files so the WP.org
// plugin listing and the wizard stay in sync.
'iconUrl' => esc_url_raw( ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'assets/quick-setup/icon.svg' ),
```

**Prevention**
- **Grep gate**: `grep -rn "\.wordpress-org/" --include='*.php' --include='*.js'` — any hit that constructs an HTTP URL needs review.
- **Rule of thumb**: `.wordpress-org/` files are for the plugin directory listing ONLY. Anything the runtime needs, ship in `assets/`.
- **CI check candidate**: a smoke test that HTTP-GETs every image URL emitted from `wp_localize_script` payloads and asserts 200.

**Related**
- General principle: dotfile directories (`.git`, `.github`, `.wordpress-org`, `.ddev`, etc.) are convention-based and NOT part of the served surface. Runtime code must never depend on their HTTP accessibility.

---

### 2026-08-19 - B48 — Count-based test assertions drift from source-of-truth registries

**Status**
Active (F071)

**Why this is durable**
PHPUnit tests that hardcode a count against a plugin-owned source-of-truth constant array (e.g. `AbstractMCPClient::DEFAULT_CLIENT_CLASSES`) drift silently every time the constant grows or shrinks. F071 added 8 new MCP client classes; the registry grew from 8 → 16 and FOUR `assertCount( 8, ... )` calls broke across unrelated-sounding test methods, plus one test method name embedded the literal "Eight". The failure messages ("expected 8, got 16") don't point at the count-hardcoding as the root problem — the reader has to trace back to the registry edit. Same "two artifacts must stay in sync, enforced by PR reviewer memory" pattern as B44, applied to test assertions.

**Pattern**
Any test asserting a count of items produced from a plugin-owned constant array where the intent is "however many there are, they should all show up" (as opposed to "there must be exactly N of these, ever") is a drift hazard the moment the source registry gets modified.

**Affected area**
Registry-enumeration tests — `GetAllRegisteredClientsTest`, and any future `get_all_registered_profiles`, `get_all_registered_connectors`, etc. Especially dangerous when the count is asserted in MULTIPLE tests inside one file (dedup test, invalid-FQN-skip test, bad-slug-reject test all repeat the same hardcoded count).

**Symptom**
- Adding one entry to a `DEFAULT_*_CLASSES` array causes N test failures in test methods with names unrelated to what was added.
- Failure message reads "expected N, got N+1" — no pointer at the hardcoding.
- Test method names may embed the count in their name (e.g. `testDefaultStateReturnsEightBuiltins…`), requiring rename too.

**Fix pattern**
Derive the expected count from the source registry at run time:
```php
// Before
public function testDefaultStateReturnsEightBuiltinsInPriorityOrder(): void {
    $this->assertCount( 8, $clients, 'MUST return exactly 8 built-in clients.' );
}

// After
public function testDefaultStateReturnsBuiltinsInPriorityOrder(): void {
    $this->assertCount(
        count( AbstractMCPClient::DEFAULT_CLIENT_CLASSES ),
        $clients,
        'MUST return every built-in client — count derived from the source registry.'
    );
}
```

**When NOT to apply**
When the count IS the invariant under test — e.g. "there must be exactly 3 core clients regardless of what any filter injects" — the hardcoded count is CORRECT because the whole point of the test is to detect drift in the source registry. Add a comment explaining that intent so a future reader doesn't "helpfully" refactor it to the derived form.

**Prevention grep gate**
```
grep -rEn 'assertCount\(\s*[0-9]+' tests/
```
For every match, PR review MUST ask "would this need updating if the source registry grew by one?". If yes, refactor to `count(SOURCE_ARRAY)`. If no (count IS the invariant), leave the hardcoded number and add a `// intentional: must fail if …` comment.

**Reference impl** (post-fix): `tests/phpunit/MCPClients/GetAllRegisteredClientsTest.php` — F071 bumped 4 assertions from 8 → 16 (still hardcoded — captured as the anti-pattern; follow-up should derive from the constant).

**Related**
- **B44** (BerlinDB Table added but uninstall.php DROP list not updated) — same class of "two artifacts must stay in sync" bug, applied to BerlinDB tables + uninstall.php.
- **PATTERN-COMPILE-TIME-COUPLING-MISSING** — when two artifacts must stay in sync, enforce it via a shared registry OR a test gate that reads the registry at run time. The `count(SOURCE_ARRAY)` fix IS the coupling.
- **D35** (F034 self-contained subsystem contract) — F034 established that consumers of `get_all_registered_clients()` should not hardcode knowledge of specific clients. B48 generalizes: consumers should not hardcode knowledge of the CARDINALITY either.

---

### 2026-08-24 — MCP client "connected" but tool list empty on local dev

**Status**
Retired (Feature 075 shipped the affordance + fix)

**Symptoms**
Operator on Local by Flywheel / MAMP / DDEV / `wp-env` pastes the generated MCP JSON into Claude Desktop / Cursor / VS Code. The client's MCP indicator turns green ("connected"), but `tools/list` returns zero results. No error surface anywhere the operator would look. First impression of the plugin: "it doesn't work."

**Root Cause**
`@automattic/mcp-wordpress-remote` Node proxy establishes a TCP connection to the WP site (proxy-side happy) then Node's TLS layer rejects the site's self-signed HTTPS certificate during the first HTTP call, silently dropping the JSON-RPC bootstrap. The MCP client interprets the live TCP socket as "connected" but never receives a `tools/list` response.

**Future mistake prevented**
Any future "connected but no data" symptom on local dev is likely a self-signed-cert TLS rejection, not an auth / protocol / capability problem. First check: what does `home_url()` scheme return, and does the site have a valid cert? Only after that rule-out should protocol traces or Application Password checks kick in.

**Evidence**
- User report (raftaar1191, 2026-08-24) hitting the symptom on `http://wordpress-7-0.local` and expecting the plugin to surface a fix.
- Automattic's own troubleshooting doc (`https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md`) documents `NODE_TLS_REJECT_UNAUTHORIZED=0` as the last-resort workaround.
- Fix verified: F075 auto-injects the flag on local sites; operator restarts client → tools appear.

**Prevention / Detection**
- Auto-inject `NODE_TLS_REJECT_UNAUTHORIZED: "0"` into every generated MCP client JSON when the site looks local (any scheme). Injection routes through `AbstractMCPClient::build_env()` so every new client subclass inherits it for free — see `DEC-MCPCLIENT-BUILD-ENV-SHARED`.
- Static warning notice above the JSON on both surfaces (per-server MCP Clients tab + Quick Setup Step 11) with a link to Automattic's troubleshooting doc — helps operators whose case is NOT TLS-shaped (stale Application Password, disabled pretty permalinks, npm proxy version drift) find the right fix.
- Detection helper is a pure static function at `Utilities\LocalEnvironment::needs_tls_bypass()` — testable in the WP-free `mcpclients` PHPUnit suite. 100/100 assertions green on merge.

**Where to look next**
- `includes/Utilities/LocalEnvironment.php` — the detection rule.
- `includes/MCPClients/AbstractMCPClient::build_env()` — the injection point.
- `public/Renderers/MCPClientsBlock.php` — admin-side warning.
- `src/js/quick-setup/steps/Step11_ClientDetail.jsx` — wizard-side warning.
- `docs/planings-tasks/075-local-dev-tls-bypass-notice.md` + `specs/075-local-dev-tls-bypass-notice/` — full design trace.

---

### 2026-08-24 — Wizard REST fetch drops admin-URL query params → Step 11 shows DTO metadata instead of config JSON

**Status**
Retired (fixed on the same PR that introduced the surface)

**Symptoms**
Operator deep-links straight to a late wizard step via `?page=…&quick-setup=1&step=11&server=1` in the admin URL. Step 11's Step 4 code block renders the CLIENT METADATA DTO (a JSON object with `category`, `slug`, `icon`, `instructions`, `meta`) instead of the mcpServers configuration JSON with `WP_API_URL` / `WP_API_PASSWORD` / `NODE_TLS_REJECT_UNAUTHORIZED` that the operator is supposed to copy into their MCP client.

**Root Cause** (dual — both ends broken simultaneously)

1. **Backend side**: `QuickSetupController::handle_state()` only attaches the `config` field to each client DTO when the wizard's server-side scratchpad has `server_id > 0`. On a fresh session that hasn't gone through the earlier server-picker step yet, the scratchpad is empty — even though the admin URL carries `?server=1`. `ConnectionMethodRegistry::get_clients()` is then called with no server row, and the DTOs come out without a `config` field entirely.

2. **Frontend side**: The wizard's XHR fetch to `/wp-json/…/quick-setup/state` is a completely SEPARATE URL from the admin URL that hosts the React app. The React state hook (`useWizardState::refetch`) doesn't inherit admin-URL query params into the XHR — it fires a bare `GET /state`. So the backend sees no `server_id` from the browser URL either, even if it wanted to fall back on it. On the client side, `Step11_ClientDetail.jsx` then falls back to `JSON.stringify( activeClient || {}, null, 2 )` when `activeClient.config` is missing — which is what leaks the DTO shape into the UI.

**Future mistake prevented**
Any new wizard step (or any other admin-hosted React surface) that reads a value from the browser URL MUST also explicitly forward that value to its REST state endpoint. The admin URL and the REST endpoint URL are completely independent, and the REST controller cannot see admin-URL params by default. Second-order rule: any React component that renders a value from a DTO field should NOT fall back to `JSON.stringify(dto)` on missing — a backend regression that drops the field should surface as a UX gap ("Not available") not a leak of the DTO's internal shape.

**Evidence**
In-browser verification on `http://wordpress-7-0.local` after F075 shipped — user report showed the DTO in the code block instead of the mcpServers JSON. Fixed both ends in commit `9f98420`:

- Frontend (`src/js/quick-setup/hooks/useWizardState.js::refetch`) now reads `new URLSearchParams(window.location.search).get('server')` and forwards it as `?server_id=N` on the XHR to `/state`.
- Backend (`includes/REST/QuickSetupController.php::handle_state`) accepts `?server_id=N` as an optional `WP_REST_Request` param and uses it as a fallback only when the scratchpad's `server_id === 0`. The scratchpad still wins when populated — it's the authoritative wizard state.

**Prevention / Detection**
- **Wizard-adjacent REST endpoint grep**: for every new wizard step, review its state hook to confirm it forwards required URL params. `grep -RnE 'apiFetch.*state' src/js/quick-setup/hooks/` — every hit MUST either (a) use a URL with no query params (scratchpad-authoritative endpoint) or (b) explicitly forward the required params via `URLSearchParams`.
- **JSX raw-dump antipattern grep**: `grep -RnE 'JSON\\.stringify\\(\\s*active|state[a-zA-Z]+\\s*\\|\\|' src/js/quick-setup/steps/` — every hit MUST have an inline comment explaining why the dump is safe (usually because it's a debug view, never for production UI).
- **New handle_state-like endpoint checklist**: any REST endpoint that reads from the wizard scratchpad AND could reasonably receive the same info via URL param MUST accept the URL param as a fallback when the scratchpad field is empty. Scratchpad wins on collision.

**Where to look next**
- `includes/REST/QuickSetupController::handle_state` — reference implementation of scratchpad-first + request-param-fallback.
- `src/js/quick-setup/hooks/useWizardState.js::refetch` — reference implementation of URL-param forwarding.
- `src/js/quick-setup/steps/Step11_ClientDetail.jsx:141` — the `JSON.stringify` fallback that made the bug visible (arguably the reason the bug was caught quickly rather than staying silent).
- Any future wizard step JSX with a `useMemo(() => activeThing.foo || JSON.stringify(activeThing))` — review for the same class of leak.

---

### 2026-08-24 — Retired-symbol names inside code comments trigger canary-grep false positives

**Status**
Retired (fixed within F076 during implementation)

**Symptoms**
Feature 076's SC-003 canary grep (`grep -RnE 'get_icon\(\)' public/Renderers/MCPClientsBlock.php`) reported one match after the two rendering `printf` calls had been rewritten. Manual inspection showed the "match" was a code comment I had added right above the rewritten `printf` explaining that the `get_icon()` method stays defined on subclasses even though the picker no longer renders it. The comment embedded the retired symbol name verbatim, which the grep then flagged as "still in use."

**Root Cause**
A well-intentioned code comment that referenced the retired symbol by its literal name (`get_icon()`) triggered the very canary grep the spec used to prove the symbol had been retired from the file. The comment was correct English; the grep pattern was correctly written; both were doing their job. The problem is that plain-text `grep` cannot distinguish comments from code.

**Future mistake prevented**
When retiring a symbol from a file and the plan includes a canary grep to prove non-usage, the replacement code comments MUST NOT reference the retired symbol by its literal name. Either:
- Rephrase the comment to describe the retired symbol semantically (e.g., "the abstract client method that returns the per-client emoji" instead of "the `get_icon()` method"), OR
- Split the grep into two — one for the retired usage in code, one for the retired usage in comments — accepting that the second may have hits by design.

Option 1 is simpler and matches the F076 fix.

**Evidence**
- Initial comment in `public/Renderers/MCPClientsBlock.php` sub-nav pill block referenced `get_icon()` verbatim → SC-003 grep reported 1 match.
- Rewrote comment to "the abstract client method that returns the per-client emoji" → SC-003 grep reported zero matches ✓.
- Fixed within F076 implementation phase; caught by running the canary greps from the spec's SC criteria as part of implementation verification, before commit.

**Prevention / Detection**
- **Author checklist**: when writing a canary-grep-verified subtractive change, spot-check the replacement comments for any occurrence of the retired symbol before running the canary.
- **Spec-side prevention**: when writing SC criteria for a subtractive change, prefer grep patterns that anchor on code syntax (e.g., `->methodName(` or `$var = methodName(`) rather than the bare symbol name (`methodName`). Anchored patterns skip comments naturally.
- **Reviewer checklist**: for any PR whose spec's SC criteria include a "grep returns zero" canary, spot-check the touched files for any explanatory comment that names the retired symbol.

**Where to look next**
- Recent PRs' SC canary grep results — if any show a stray match after the change lands, apply the same rephrase-or-anchor fix.
- Future spec-kit specs on subtractive changes — favor `->methodName(` over `methodName` in SC grep patterns.
