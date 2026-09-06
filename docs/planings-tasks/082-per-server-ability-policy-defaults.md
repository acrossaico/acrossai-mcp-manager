# Planning: Per-server Ability Policy Defaults (Feature 082)

> **SEC-001 AMENDMENT (2026-09-05, plan-phase security review)** — The row-only
> method is **renamed** `ExposureResolver::resolve()` → `ExposureResolver::resolve_row_only()`
> per SEC-001 Option A. The name itself now broadcasts the F030-critical
> semantics, adding a fail-loud rename detector on top of the process gates
> (spec FR-007 + SC-005 + regression fence + review-gate comment). Every
> `resolve()` reference in TASK-4 pseudocode, TASK-9 fence pseudocode, and the
> CONSTRAINTS block below is superseded by `resolve_row_only()` for the
> row-only method. `resolve_effective()` (the new three-tier sibling) is
> unchanged. F030's `PermissionOverrideProcessor::should_bypass()` at
> `PermissionOverrideProcessor.php:150` calls `resolve_row_only()`. The
> regression fence test is `test_resolve_row_only_still_row_only_for_f030()`.
> The whole-plugin grep audit in the Evidence Collation Template §6 checks
> for `resolve_row_only(` (single expected call site — F030) instead of
> `resolve(`. See `docs/security-reviews/2026-09-05-082-ability-policy-defaults-plan.md`
> SEC-001 for the full rationale.
>
> **SCOPE AMENDMENT (2026-09-05, governed-implement pre-flight)** — an
> implementation-time grep for `ExposureResolver::resolve(` revealed **5
> production callers**, not the 1-2 this brief originally documented. The
> extra three are: `includes/Database/MCPServer/AbilityDiscovery.php:82`,
> `includes/Abilities/AbilityHelpers.php:79`, and
> `includes/REST/AbilitiesController.php:278 + :284` (was/now snapshots inside
> `post_abilities()`), plus `includes/REST/QuickConnectController.php:788`
> (aliased namespace). Per D24 defense-in-depth (advertisement-time uses
> effective exposure), 4 of the 5 migrate to `resolve_effective()`; only F030
> stays on `resolve_row_only()`. The existing `ExposureResolverTest.php`
> lives at `tests/phpunit/Database/MCPServerAbility/ExposureResolverTest.php`
> (nested path — brief had wrong path) with 6 direct `resolve()` calls; all
> six migrate to `resolve_row_only()` (they test row-only semantics). See
> `specs/082-ability-policy-defaults/tasks.md` T007c for the caller-migration
> sweep and T008a for the test migration.


Move the per-server **Enable All / Disable All** decision from a snapshot of
N per-ability rows into a **single tri-state default policy** stored on
`{prefix}acrossai_mcp_servers` (`abilities_default_policy` — `per-ability` |
`expose` | `hide`). Per-ability rows in `acrossai_mcp_server_abilities` survive
as **explicit overrides only**. Fixes the F017 gap where an operator's
"expose everything on this server" gesture is stored as a snapshot of the
ability registry at that moment, so any ability registered later
(plugin update, mu-plugin, addon activation) silently falls back to its
own `meta.mcp.public` — usually `false` for private abilities. Same problem
inverted for **Disable All**: newly-registered abilities can leak on servers
the operator explicitly wanted locked down. Tracks
[issue #95](https://github.com/acrossaico/acrossai-mcp-manager/issues/95).

The change fills the follow-up gap that F017 (`017-per-server-ability-selection.md`)
left open — F017 assumes "per-server overrides" is the whole policy surface,
but Enable All / Disable All are actually two orthogonal decisions:
"which abilities does this server expose *right now*" (per-row) plus
"what should new abilities inherit *tomorrow*" (server-wide default).
F082 introduces the second axis without touching the first.

The migration is **backwards-compatible with existing data**: existing
servers migrate to `policy='per-ability'` — byte-for-byte behavioural
parity with today. Pre-F082 rows continue to win because
`resolve_effective()` reads row → server policy → `meta.mcp.public` in
that priority order. Data-preservation contract: the new column adds to
the existing `MCPServer\Schema` under BerlinDB `$upgrades` per D28's
three-part reconciler contract, driven by `Main::reconcile_database_schemas()`
on `admin_init@3` — no `ALTER TABLE` on healthy installs beyond the
first `admin_init` after activation.

The **security-critical constraint** is that `ExposureResolver::resolve()`
MUST NOT be widened in place. F030's `PermissionOverrideProcessor::should_bypass()`
at `includes/Abilities/PermissionOverrideProcessor.php:150` calls `resolve()`
with **empty meta** as its "is there an explicit exposed row for this pair?"
row-existence probe. Widening `resolve()` to honour `policy='expose'` would
silently widen the F030 permission-callback bypass to every ability the
moment an operator clicks Enable All. F082 adds a sibling
`ExposureResolver::resolve_effective()` instead; the F030 call site stays
on `resolve()`. This split is the single most important review-gate in the
feature — see the CONSTRAINTS block and the T-NN Evidence Collation
Template's F030 regression fence.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "per-server-ability-policy-defaults"

# 2. Specify
/speckit.specify "Add server-level default policy for per-server ability
exposure. New column abilities_default_policy VARCHAR(16) NOT NULL DEFAULT
'per-ability' on {prefix}acrossai_mcp_servers with values per-ability | expose
| hide. Migration via BerlinDB \$upgrades in
includes/Database/MCPServer/Table.php reconciled by
Main::reconcile_database_schemas() on admin_init@3 per D28. Split
ExposureResolver into two methods:
Includes\\Database\\MCPServerAbility\\ExposureResolver::resolve( int
\$server_id, string \$ability_slug, array \$meta ): bool remains
unchanged (row-only + meta.mcp.public fallback — the F030
PermissionOverrideProcessor::should_bypass() at
includes/Abilities/PermissionOverrideProcessor.php:150 depends on this
exact semantics with empty meta as its row-existence probe). Add sibling
ExposureResolver::resolve_effective( int \$server_id, string
\$ability_slug, array \$meta ): bool with three-tier priority:
row-in-table → server-level policy → meta.mcp.public. Two per-request
static caches keyed by \\\"{\$server_id}:{\$ability_slug}\\\" and
\\\"{\$server_id}\\\" for the server policy; both reset in
_reset_cache_for_tests(). Add REST route POST
/acrossai-mcp-manager/v1/servers/(?P<server_id>\\d+)/abilities/policy
with body { policy: 'expose' | 'hide' | 'per-ability' }, permission_callback =
current_user_can('manage_options'). Handler steps: validate policy string
(400 on invalid); look up server row (404 on missing); snapshot pre-change
effective state; UPDATE acrossai_mcp_servers SET abilities_default_policy
= ? WHERE id = ?; DELETE FROM acrossai_mcp_server_abilities WHERE
server_id = ? to nuke stale overrides; fire new action
acrossai_mcp_server_policy_changed( int \$server_id, string
\$old_policy, string \$new_policy, array \$affected_slugs, int \$user_id );
return { overrides: [], abilities_default_policy, affected_slugs }.
Augment GET /acrossai-mcp-manager/v1/servers/(?P<server_id>\\d+)/abilities
response with top-level abilities_default_policy and per-item is_exposed
computed via resolve_effective() and per-item has_override boolean. Swap
AbilityExposureGate::gate_tool_call_by_exposure() at
includes/MCP/AbilityExposureGate.php:131 from resolve() to
resolve_effective() (one-line change). F015 gate (priority 10) and F020
gate (priority 30) are UNTOUCHED — neither calls the resolver. React
src/js/abilities.js: delete client-side merge at lines 360-362 (client
trusts server-computed is_exposed), swap Enable All handler (currently
lines 878-898) and Disable All handler (899-919) to hit the new policy
endpoint with confirm-modal copy, update counter to reflect resolver
output not row count (currently lines 631 + 734 after the intervening
refactor), add 'overridden' as the fourth option to the exposure filter
(777-807) reading the new has_override field, add a header pill reading
'Default policy: Expose every ability by default' / 'Hide every ability
by default' / 'Use each ability's own default'. Do NOT touch F015 or
F020 gates. Do NOT rename any existing REST route, filter, hook, column,
or PHP method signature — additive only. Do NOT drop the client-side
merge in a way that leaves the pre-migration server showing stale
override values — the merge must be replaced by server-truth in the same
commit as the GET augment. Do NOT touch AbilitiesTab.php (React owns the
tab body). Do NOT seed default rows on migration — the empty-table state
IS the correct backwards-compatible state under policy='per-ability'.
Memory hygiene per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION: F017's
DEC-ABILITY-OVERRIDE-RESOLUTION stays Active and gets a forward-pointer
annotation to the new DEC-SERVER-DEFAULT-POLICY-OVER-SNAPSHOT-ENROLMENT
(D51) and DEC-EFFECTIVE-STATE-COUNTER-OVER-ROW-COUNTER (D52) captures."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration
>    rules (no `add_action` inside constructors), Before Commit Checklist,
>    the loader-only wiring contract in `includes/Main.php`.
> 2. `docs/planings-tasks/017-per-server-ability-selection.md` — the
>    F017 planning doc that this feature extends. F082 layers a new
>    server-level default on top of F017's per-row `acrossai_mcp_server_abilities`
>    table; F017's `ExposureResolver::resolve()` semantics and
>    `MCPServerAbility` module shape are the invariants F082 must not
>    disturb. Read the CONSTRAINTS block in full — every "Do not"
>    listed there also applies to F082.
> 3. `docs/planings-tasks/011-berlindb-migration.md` — canonical
>    BerlinDB module shape and the D28 three-part reconciler contract
>    (`$upgrades` array in the Table subclass, reconciler on
>    `admin_init@3`, silent phantom-version guard). F082's new column
>    lands via this contract.
> 4. `docs/planings-tasks/030-per-server-permission-override.md` — the
>    F030 planning doc, especially its `PermissionOverrideProcessor::should_bypass()`
>    call site. F082's most important design constraint is that F030's
>    call to `ExposureResolver::resolve()` at
>    `includes/Abilities/PermissionOverrideProcessor.php:150` uses
>    empty meta as a row-existence probe — widening `resolve()` to
>    honour `policy='expose'` silently widens the permission-callback
>    bypass. Read F030 in full so the resolver-split rationale is
>    understood, not memorised.
> 5. `vendor/berlindb/core/src/Database/Kern/{Table,Schema}.php` —
>    BerlinDB v3 base classes. Read to understand the `$upgrades`
>    array shape (`[[VERSION, CALLBACK]]`), which is what powers the
>    additive column reconciliation without touching the base DDL.
> 6. The current `includes/Database/MCPServer/{Schema,Row,Table}.php`
>    trio (Schema at lines 1-70, Row at 1-60, Table at 1-230 with
>    `$upgrades` at 69-74 and the D28 3-part upgrade callback at
>    201-226) — read all three in full BEFORE editing. The new
>    `abilities_default_policy` column lands into an existing schema
>    array and an existing upgrader.
> 7. `includes/Database/MCPServerAbility/ExposureResolver.php` — the
>    existing resolver (`resolve()` static at line 40, `$cache` per-request
>    map, `_reset_cache_for_tests()` at line 84). F082 adds
>    `resolve_effective()` sibling; `resolve()` stays untouched.
> 8. `includes/REST/AbilitiesController.php` — the existing controller
>    (GET at 168-199, POST at 209-317, per-pair action
>    `acrossai_mcp_ability_exposure_changed` fired from `post_abilities()`
>    at line 306). F082 augments the GET response and adds a new
>    `post_policy()` handler; the existing per-pair semantics stay.
> 9. `includes/MCP/AbilityExposureGate.php` — the F017 tool-call gate
>    (`gate_tool_call_by_exposure()` at line 131 with its
>    `resolve()` call). F082 changes exactly one line here — the
>    `resolve()` → `resolve_effective()` swap.
> 10. `src/js/abilities.js` — the React app. Client-side merge at
>     `360-362`, counter blocks at `631` + `734`, exposure filter at
>     `777-807`, Enable All handler at `878-898`, Disable All handler
>     at `899-919`.
>
> Every decision — schema column translation, upgrade version bump,
> resolver split shape, REST route contract, React handler refactor —
> must be justified against the above. If a choice is not explicitly
> covered, default to the F017 or F011 shape. Do not write code that
> would fail any Definition-of-Done gate: PHPStan level 8, PHPCS,
> security review, all `__()` calls using the correct text domain
> `'acrossai-mcp-manager'`.
>
> **Public API artifacts to preserve verbatim from this feature forward
> (grep-gate before + after):**
>
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\{Schema,Table,Query,Row}` — no rename, no signature change; column adds only.
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver::resolve()` — signature + semantics **frozen** (F030 depends on this exactly).
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver::resolve_effective()` — **new**, added forever from this merge.
> - `\AcrossAI_MCP_Manager\Includes\REST\AbilitiesController::get_abilities()` — response shape is additive only; keys never removed or renamed.
> - `\AcrossAI_MCP_Manager\Includes\REST\AbilitiesController::post_abilities()` — signature + semantics frozen; still per-pair upsert.
> - REST routes (new):
>   - `POST /acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities/policy` — never renamed after merge.
> - REST routes (unchanged):
>   - `GET  /acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities`
>   - `POST /acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities`
> - PHP action (**new**, never renamed after merge):
>   - `acrossai_mcp_server_policy_changed` — `do_action( 'acrossai_mcp_server_policy_changed', int $server_id, string $old_policy, string $new_policy, array $affected_slugs, int $user_id )`.
> - PHP action (**unchanged**, must still fire per pair from `post_abilities()`):
>   - `acrossai_mcp_ability_exposure_changed` — verbatim from F017 T044.
>
> Pre-flight grep (records the callers whose behavior must be
> unchanged after this feature):
> ```
> grep -rEn '(ExposureResolver::resolve\(|PermissionOverrideProcessor|acrossai_mcp_ability_exposure_changed)' \
>     --include='*.php' \
>     includes/ admin/ public/ tests/ acrossai-mcp-manager.php
> ```
> Every hit under `includes/Abilities/PermissionOverrideProcessor.php`
> MUST still resolve to the **same `resolve()` call** with the same
> empty-meta shape after this feature. Any grep result that shows F030
> now calling `resolve_effective()` is a **blocking regression** — fix
> it before merge.
>
> New column + option map (data-preservation contract):
>
> | Module | Table | Column | Bump | `db_version_key` |
> | --- | --- | --- | --- | --- |
> | MCPServer | `acrossai_mcp_servers` | `abilities_default_policy VARCHAR(16) NOT NULL DEFAULT 'per-ability'` | `$version` → next patch (e.g. `0.0.2`) | `acrossai_mcp_manager_db_version` |
>
> ---
>
> **TASK-1 — Land this planning doc**
>
> Files: `docs/planings-tasks/082-per-server-ability-policy-defaults.md`
> (this file).
>
> Self-referential first task per the F017 TASK-1 convention. The
> spec-kit chain reads this file at `/speckit.specify` time and
> derives spec.md + plan.md + tasks.md from the TASK-2..10 breakdown
> below. If this file is not on disk before the branch is cut, the
> chain has nothing to consume.
>
> No code changes in TASK-1. Merge this file to a feature branch
> `docs/f079-plan` first (or land it as the first commit on the
> feature branch itself), then start TASK-2.
>
> ---
>
> **TASK-2 — Add `abilities_default_policy` column via BerlinDB `$upgrades`**
>
> Files:
> - `includes/Database/MCPServer/Schema.php` (delta — append column def)
> - `includes/Database/MCPServer/Table.php` (delta — bump `$version`, add
>   entry to `$upgrades` at lines 69-74, add upgrade callback per D28
>   3-part contract at lines 201-226)
>
> Read the F011 `$upgrades` pattern in `MCPServer\Table.php` in full
> BEFORE editing. The three parts of the D28 contract are:
>
> 1. New entry in the `$upgrades` array: `[ 'NEW_VERSION', 'upgrade_NEW_VERSION' ]`.
> 2. New protected method `upgrade_NEW_VERSION()` returning `bool`:
>    checks `$this->column_exists( 'abilities_default_policy' )`; if
>    absent, runs `ALTER TABLE {$this->table_name} ADD COLUMN abilities_default_policy VARCHAR(16) NOT NULL DEFAULT 'per-ability'`;
>    returns `true` on success.
> 3. `$version` bump to `NEW_VERSION` — must be a strict semver bump
>    (patch is fine; the D28 reconciler compares as strings but semver
>    ordering must not regress).
>
> Add the column definition to `Schema::$columns`:
> ```php
> array( 'name' => 'abilities_default_policy', 'type' => 'varchar', 'length' => '16',
>        'default' => 'per-ability' ),
> ```
> Place it after the last existing column so the SHOW CREATE TABLE
> diff surfaces it at the bottom (readability during review).
>
> Do NOT add an index — the column is a low-cardinality (3 values)
> per-row lookup that piggybacks on the row PK; a secondary index
> would harm write cost with no read benefit.
>
> `Main::reconcile_database_schemas()` at `includes/Main.php:254`
> (hooked at `admin_init@3` at line 381) picks up the new upgrade on
> the first `admin_init` after activation. No changes needed in
> `Main.php` beyond confirming the existing reconciliation still calls
> `MCPServerTable::instance()->maybe_upgrade()`.
>
> ---
>
> **TASK-3 — Expose the column on `Row` + query surface**
>
> Files: `includes/Database/MCPServer/Row.php` (delta — add public
> property + `to_array()` entry).
>
> Add public property:
> ```php
> public $abilities_default_policy = 'per-ability';
> ```
> Add the same key to the `to_array()` return array. Do NOT teach
> `Query` a new filter — the column is always read via `Row` from a
> server-id lookup, never queried as a filter predicate.
>
> ---
>
> **TASK-4 — Resolver split: add sibling `resolve_effective()`**
>
> Files: `includes/Database/MCPServerAbility/ExposureResolver.php`
> (delta — add second static method + second static cache).
>
> **The single most important rule of this feature: `resolve()` is
> not touched.** Its signature, body, cache, and semantics are frozen
> from this feature onward. F030's `PermissionOverrideProcessor::should_bypass()`
> at `includes/Abilities/PermissionOverrideProcessor.php:150` calls
> `resolve()` with **empty meta** as a "does a row exist with is_exposed=1?"
> probe; widening `resolve()` to consult the server policy silently
> widens the F030 permission-callback bypass to every ability on any
> `policy='expose'` server.
>
> Add a review-gate comment ABOVE the call site at
> `PermissionOverrideProcessor.php:150`:
> ```php
> // F082 review-gate: this MUST stay on ExposureResolver::resolve(),
> // NOT resolve_effective(). F030's bypass is a row-existence probe;
> // widening it to honour server policy would silently widen the
> // permission-callback bypass to every ability the moment an
> // operator clicks Enable All on a server. See
> // docs/planings-tasks/082-per-server-ability-policy-defaults.md
> // (CONSTRAINTS + TASK-9 F030 regression fence).
> ExposureResolver::resolve( $server_id, $slug, array() );
> ```
> The comment IS part of the deliverable — future contributors who
> "clean up" the call to use `resolve_effective()` are the exact
> hazard this feature must prevent.
>
> Add the new method:
> ```php
> /** @var array<string, bool> per-request cache keyed by "{server_id}:{slug}" */
> private static array $effective_cache = array();
>
> /** @var array<int, string> per-request cache keyed by server_id */
> private static array $policy_cache = array();
>
> public static function resolve_effective(
>     int $server_id,
>     string $ability_slug,
>     array $meta
> ): bool {
>     $key = "{$server_id}:{$ability_slug}";
>     if ( array_key_exists( $key, self::$effective_cache ) ) {
>         return self::$effective_cache[ $key ];
>     }
>
>     // Priority 1: explicit row wins over everything.
>     $rows = \AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query::instance()->query( array(
>         'server_id'    => $server_id,
>         'ability_slug' => $ability_slug,
>         'number'       => 1,
>     ) );
>     if ( ! empty( $rows ) ) {
>         return self::$effective_cache[ $key ] = (bool) $rows[0]->is_exposed;
>     }
>
>     // Priority 2: server-level policy.
>     $policy = self::server_policy( $server_id );
>     if ( 'expose' === $policy ) {
>         return self::$effective_cache[ $key ] = true;
>     }
>     if ( 'hide' === $policy ) {
>         return self::$effective_cache[ $key ] = false;
>     }
>
>     // Priority 3: per-ability meta fallback (same as resolve()).
>     return self::$effective_cache[ $key ] = ! empty( $meta['mcp']['public'] );
> }
>
> private static function server_policy( int $server_id ): string {
>     if ( array_key_exists( $server_id, self::$policy_cache ) ) {
>         return self::$policy_cache[ $server_id ];
>     }
>     $rows = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query( array(
>         'id'     => $server_id,
>         'number' => 1,
>     ) );
>     $policy = 'per-ability';
>     if ( ! empty( $rows ) && ! empty( $rows[0]->abilities_default_policy ) ) {
>         $candidate = (string) $rows[0]->abilities_default_policy;
>         if ( in_array( $candidate, array( 'expose', 'hide', 'per-ability' ), true ) ) {
>             $policy = $candidate;
>         }
>     }
>     return self::$policy_cache[ $server_id ] = $policy;
> }
> ```
> Update `_reset_cache_for_tests()` at line 84 to reset **both** new
> caches alongside the existing `$cache`.
>
> ---
>
> **TASK-5 — Swap the tool-call gate to `resolve_effective()`**
>
> Files: `includes/MCP/AbilityExposureGate.php` (delta — one line at
> line 131).
>
> Change the resolver call inside `gate_tool_call_by_exposure()`:
> ```php
> // Before
> $is_exposed = ExposureResolver::resolve( (int) $server_id, (string) $tool_name, $meta );
> // After
> $is_exposed = ExposureResolver::resolve_effective( (int) $server_id, (string) $tool_name, $meta );
> ```
> Do NOT touch anything else in the gate — priority is still 20, F015
> deny is still short-circuited by the `is_wp_error( $result )` check
> at the top, fail-open on unresolved `$server_id` still applies.
>
> **Do not swap the F015 gate (priority 10)** — it does not call the
> resolver at all; its logic is F015 AccessControl-driven.
>
> **Do not swap the F020 gate (priority 30)** — it does not call the
> resolver at all; its logic is F020 tool-curation-driven.
>
> **Do not swap the F030 processor** — see TASK-4 review-gate comment
> above.
>
> ---
>
> **TASK-6 — REST: new `POST /abilities/policy` + augment `GET /abilities`**
>
> Files: `includes/REST/AbilitiesController.php` (delta —
> `register_routes()` gets a third `register_rest_route()`;
> `get_abilities()` body at 168-199 gets two new fields;
> `post_abilities()` at 209-317 UNCHANGED; new `post_policy()`
> handler).
>
> `register_routes()` — add:
> ```php
> register_rest_route(
>     self::NAMESPACE,
>     '/servers/(?P<server_id>\d+)/abilities/policy',
>     array(
>         'methods'             => 'POST',
>         'callback'            => array( $this, 'post_policy' ),
>         'permission_callback' => array( $this, 'permission_check' ),
>         'args'                => array(
>             'server_id' => array(
>                 'type'              => 'integer',
>                 'required'          => true,
>                 'sanitize_callback' => 'absint',
>             ),
>             'policy'    => array(
>                 'type'     => 'string',
>                 'required' => true,
>                 'enum'     => array( 'per-ability', 'expose', 'hide' ),
>             ),
>         ),
>     )
> );
> ```
>
> `post_policy()` handler:
> 1. `$server_id = (int) $req['server_id'];` and `$policy = (string) $req['policy'];`.
> 2. Look up server row via `MCPServer\Query::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )`.
>    Return `WP_Error( 'acrossai_mcp_server_not_found', ..., array( 'status' => 404 ) )` on empty.
> 3. Snapshot pre-change effective state — iterate `wp_get_abilities()`,
>    compute `is_exposed` via `resolve_effective()` for each, collect
>    the slug/is_exposed map. This is the **before** state used to
>    populate `$affected_slugs` in the fired action.
> 4. `$old_policy = $server_row->abilities_default_policy ?? 'per-ability';`.
> 5. `MCPServer\Query::instance()->update_item( $server_id, array( 'abilities_default_policy' => $policy ) );`.
> 6. `MCPServerAbility\Query::instance()->delete_where( array( 'server_id' => $server_id ) );`
>    (nukes stale overrides so the new policy is the sole source of truth).
>    If BerlinDB's Query does not expose `delete_where`, use a raw
>    prepared `$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE server_id = %d", $table, $server_id ) )`.
> 7. Reset `ExposureResolver::_reset_cache_for_tests()` (rename to
>    `_reset_cache()` if the test-only naming is confusing to production
>    callers, but keep the test alias intact — F017 tests depend on it).
> 8. Compute post-change effective state; diff against snapshot to
>    populate `$affected_slugs`.
> 9. `do_action( 'acrossai_mcp_server_policy_changed', $server_id, $old_policy, $policy, $affected_slugs, get_current_user_id() );`.
> 10. Return the same shape as GET plus `affected_slugs`:
>    ```php
>    array(
>        'has_abilities_api'         => true,
>        'abilities'                 => $abilities,
>        'abilities_default_policy'  => $policy,
>        'affected_slugs'            => $affected_slugs,
>    );
>    ```
>
> `get_abilities()` augment (168-199):
> - Add top-level response key `abilities_default_policy` (from the
>   server row).
> - For every registered ability (not just those with rows), populate:
>   - `is_exposed` via `resolve_effective()` (was `resolve()` before).
>   - `has_override` — boolean, `true` iff a row exists in
>     `acrossai_mcp_server_abilities` for this `(server_id, slug)` pair.
>
> `post_abilities()` at 209-317 — **UNCHANGED**. Per-pair upserts
> still hit this endpoint; the existing `acrossai_mcp_ability_exposure_changed`
> per-pair action at line 306 still fires exactly as before. F082
> adds a new sibling action; it does not replace the pair-level one.
>
> ---
>
> **TASK-7 — React `src/js/abilities.js`: remove client merge, swap handlers, add pill + filter**
>
> Files: `src/js/abilities.js` (multiple deltas).
>
> **Delta 1 — delete client-side merge at 360-362:**
> ```js
> // BEFORE (lines 360-362 today):
> const isExposed = override ? override.is_exposed : !! mcpMeta.public;
> // AFTER:
> const isExposed = !! row.is_exposed; // server-computed via resolve_effective()
> ```
> The client MUST trust `row.is_exposed` as returned by the augmented
> GET response. Leaving the merge in place makes the tab lie on
> `policy='expose'` servers — the row is absent, `mcpMeta.public` is
> false, but the server-truth is `true`. This is a **required** part
> of TASK-7 and must ship in the same commit as TASK-6's GET augment.
>
> **Delta 2 — Enable All handler (878-898):**
> ```js
> // Replace saveMany( decoratedItems, true ) with:
> const confirmed = window.confirm( __(
>     'Expose every ability on this server by default? Any per-ability overrides will be cleared and future abilities will be exposed automatically.',
>     'acrossai-mcp-manager'
> ) );
> if ( ! confirmed ) { return; }
> return apiFetch( {
>     path: `/${ config.namespace }/servers/${ config.serverId }/abilities/policy`,
>     method: 'POST',
>     data: { policy: 'expose' },
> } )
>     .then( ( res ) => {
>         setItems( res.abilities || [] );
>         setPolicy( res.abilities_default_policy );
>     } )
>     .catch( ( e ) => setError( e.message ) );
> ```
>
> **Delta 3 — Disable All handler (899-919):** same shape with
> `{ policy: 'hide' }` and confirm-modal copy "Hide every ability on
> this server by default? Any per-ability overrides will be cleared
> and future abilities will be hidden automatically."
>
> **Delta 4 — counter (631 + 734):** replace the row-count-driven
> counter with resolver-output-driven copy. Example:
> ```js
> const exposedCount = items.filter( ( i ) => i.is_exposed ).length;
> const overrideCount = items.filter( ( i ) => i.has_override ).length;
> const counterCopy = 'expose' === policy
>     ? sprintf( _n( 'All %d exposed — default policy: expose. %d override.',
>                    'All %d exposed — default policy: expose. %d overrides.',
>                    overrideCount, 'acrossai-mcp-manager' ),
>                items.length, overrideCount )
>     : 'hide' === policy
>         ? sprintf( _n( 'All hidden — default policy: hide. %d override.',
>                        'All hidden — default policy: hide. %d overrides.',
>                        overrideCount, 'acrossai-mcp-manager' ),
>                    overrideCount )
>         : sprintf( _n( '%d of %d ability exposed.',
>                        '%d of %d abilities exposed.',
>                        items.length, 'acrossai-mcp-manager' ),
>                    exposedCount, items.length );
> ```
>
> **Delta 5 — exposure filter (777-807):** add `'overridden'` as the
> fourth option:
> ```js
> { value: 'overridden', label: __( 'Only overridden', 'acrossai-mcp-manager' ) },
> ```
> Filter logic: `filtered = items.filter( ( i ) => i.has_override );`.
> The other three options (`all`, `exposed`, `hidden`) unchanged.
>
> **Delta 6 — header pill (new):** render above the DataViews table:
> ```jsx
> const policyPill = 'expose' === policy
>     ? __( 'Default policy: Expose every ability by default', 'acrossai-mcp-manager' )
>     : 'hide' === policy
>         ? __( 'Default policy: Hide every ability by default', 'acrossai-mcp-manager' )
>         : __( 'Default policy: Use each ability\'s own default', 'acrossai-mcp-manager' );
> ```
> Style with the F017 `.mcp-tab-panel` sibling class conventions.
>
> ---
>
> **TASK-8 — Preserve per-pair action; add sibling server-level action**
>
> Files: `includes/REST/AbilitiesController.php` (verify + document).
>
> The existing `acrossai_mcp_ability_exposure_changed` per-pair action
> at line 306 (fired from `post_abilities()`) **must still fire on
> every single-pair upsert** after F082. Companion plugins that
> subscribe to this action (e.g. audit-log integrations) rely on the
> per-pair granularity for row-level audit trails.
>
> The new `acrossai_mcp_server_policy_changed` action fired from
> `post_policy()` is **additive** — a subscriber that only cares
> about bulk transitions can listen to the new action; a subscriber
> that cares about per-pair diffs can still listen to the existing
> one.
>
> Grep-gate for this task: after all deltas ship,
> `grep -rEn 'acrossai_mcp_ability_exposure_changed' includes/REST/`
> MUST still return exactly one `do_action(...)` call site — the
> existing one at `AbilitiesController.php:306`.
>
> ---
>
> **TASK-9 — Tests: extend existing suites + add F030 regression fence**
>
> Files:
> - `tests/phpunit/Database/ExposureResolverTest.php` (delta — extend
>   existing 66 LOC file with `resolve_effective()` cases; ADD F030
>   regression fence as a separate `test_resolve_still_row_only_for_f030()`
>   method).
> - `tests/phpunit/REST/AbilitiesControllerTest.php` (delta — extend
>   existing 123 LOC file with a `test_post_policy_expose_clears_overrides()`
>   + `test_get_abilities_augments_with_policy_and_has_override()` +
>   `test_post_policy_invalid_string_returns_400()` +
>   `test_post_policy_nonexistent_server_returns_404()`).
> - `tests/phpunit/Database/PolicyReconcilerTest.php` (new — asserts
>   the D28 3-part contract: `$upgrades` array contains the new entry,
>   the upgrade callback returns `true` and creates the column, running
>   the upgrade twice is idempotent, the `$version` bump is a strict
>   semver forward).
>
> **F030 regression fence — this is the merge-blocker test:**
> ```php
> public function test_resolve_still_row_only_for_f030(): void {
>     // Server with policy='expose' and NO row for slug X.
>     $server_id = $this->create_server_with_policy( 'expose' );
>
>     // F030 uses resolve() with empty meta as a row-existence probe.
>     // If someone widens resolve() to honour server policy, this test
>     // starts returning true and the F030 bypass silently widens.
>     $this->assertFalse(
>         ExposureResolver::resolve( $server_id, 'core/example-slug', array() ),
>         'F082 review-gate: ExposureResolver::resolve() MUST remain row-only. '
>         . 'F030 depends on this exact behaviour. Do not widen resolve() to '
>         . 'consult server policy — add or update resolve_effective() instead. '
>         . 'See docs/planings-tasks/082-per-server-ability-policy-defaults.md CONSTRAINTS.'
>     );
>
>     // Sanity: the effective resolver, WITH server policy, DOES flip.
>     $this->assertTrue(
>         ExposureResolver::resolve_effective( $server_id, 'core/example-slug', array() ),
>         'resolve_effective() must honour policy=expose.'
>     );
> }
> ```
> The assertion message IS the deliverable — a future developer who
> "cleans up" the resolver split will see this exact message in the
> CI failure output and understand why the fence exists.
>
> ---
>
> **TASK-10 — Docs + memory captures**
>
> Files:
> - `README.txt` (delta — new `= Unreleased =` bullet).
> - `docs/memory/DECISIONS.md` (new D51 + D52).
> - `docs/memory/WORKLOG.md` (Feature 082 milestone).
> - `docs/memory/INDEX.md` (D51 + D52 rows + WORKLOG row).
> - `docs/planings-tasks/README.md` (append F082 row).
> - `docs/planings-tasks/017-per-server-ability-selection.md` (delta —
>   append forward-pointer annotation to F017's
>   `DEC-ABILITY-OVERRIDE-RESOLUTION` per
>   PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION).
>
> `README.txt` — add an `= Unreleased =` bullet:
> ```
> * New — Per-server default ability policy. Each MCP server now
>   carries a tri-state default (Use each ability's own default /
>   Expose every ability / Hide every ability). Enable All and
>   Disable All flip this default, so abilities registered by
>   later plugin updates inherit the operator's intent
>   automatically. Backwards-compatible — existing servers land
>   on the "Use each ability's own default" policy with no
>   behaviour change.
> ```
>
> `DECISIONS.md` — add two Active entries:
>
> - **DEC-SERVER-DEFAULT-POLICY-OVER-SNAPSHOT-ENROLMENT (Active — Feature 082, D51)**:
>   When an "Enable All" operator gesture must survive future ability
>   registrations, store a per-owner default policy and use presence
>   rows as overrides. Do NOT lazily enrol rows on registration — the
>   registration-time snapshot is exactly the state we're moving
>   away from. Applies to every future admin surface where "apply to
>   everything, including future stuff" is a user-visible affordance.
>
> - **DEC-EFFECTIVE-STATE-COUNTER-OVER-ROW-COUNTER (Active — Feature 082, D52)**:
>   UI counters over a resolver-backed subsystem MUST reflect resolver
>   output, not row counts, or they lie to operators. F082's
>   abilities counter reads `resolve_effective()` for every registered
>   ability; on a `policy='expose'` server with zero rows, the count
>   is "All N exposed", not "0 exposed."
>
> `WORKLOG.md` — add a Feature 082 milestone entry (Why durable /
> Future mistake prevented / Evidence / Where to look). Highlight the
> durable lesson: **any "apply to everything" bulk operator gesture
> that must include future registrations belongs behind a policy
> flag, not a snapshot of rows at the moment of the click.**
>
> `INDEX.md` — new rows under Active Decisions for D51 + D52 + a
> WORKLOG row for Feature 082.
>
> `017-per-server-ability-selection.md` — append at the end of the
> `DEC-ABILITY-OVERRIDE-RESOLUTION` section:
> ```markdown
> > **F082 forward-pointer (2026-09-xx)**: F082 extends this
> > decision — the row-only resolver here (`resolve()`) is now the
> > second tier of a three-tier resolution: row → server policy
> > → meta. `ExposureResolver::resolve_effective()` implements the
> > full three-tier path; `resolve()` remains the row-only probe
> > that F030 depends on. See D51.
> ```
>
> `docs/planings-tasks/README.md` — append a row for
> `082-per-server-ability-policy-defaults.md`.
>
> ---
>
> **CONSTRAINTS**
>
> - **F030 hazard — do NOT widen `ExposureResolver::resolve()` in
>   place.** `PermissionOverrideProcessor::should_bypass()` at
>   `includes/Abilities/PermissionOverrideProcessor.php:150` calls it
>   with empty meta as a row-existence probe — widening it to honour
>   `policy='expose'` silently widens the permission-callback bypass
>   to every ability the moment an operator clicks Enable All. This
>   is a security regression, not a UX change. The resolver split
>   with a sibling `resolve_effective()` is the only correct fix.
> - **Do NOT touch F015 gate (priority 10) or F020 gate (priority 30).**
>   Neither calls the resolver; both stay untouched. F082 changes
>   exactly the F017 gate at priority 20.
> - **Do NOT rename, drop, or change the signature of any existing
>   public REST route, filter, hook, column, or PHP method.** Additive
>   only. `resolve()` frozen. `post_abilities` frozen.
>   `acrossai_mcp_ability_exposure_changed` still fires per pair.
> - **Do NOT remove the client-side merge at `abilities.js:360-362`
>   in a commit that doesn't also augment `GET /abilities` with
>   server-computed `is_exposed`.** The two changes ship together —
>   either both or neither.
> - **Do NOT seed default rows on migration.** Existing servers
>   migrate to `policy='per-ability'` and their existing rows continue
>   to win via `resolve_effective()`'s priority-1 tier. Row seeding
>   is the exact problem F082 is fixing.
> - **Do NOT add an index on `abilities_default_policy`.** Three-value
>   low-cardinality column read only by per-server-id lookups; an
>   index would harm write cost with no read benefit.
> - **Do NOT touch `AbilitiesTab.php`.** React owns the tab body from
>   F017; F082 changes React only.
> - **Do NOT introduce generic React libraries** — `react-query`,
>   `@tanstack/*`, `redux`, `mobx`, `react-table`, MUI,
>   styled-components remain forbidden per F017 CONSTRAINT.
> - **Do NOT `__return_true` on any REST permission_callback.** The
>   new `post_policy` route gates on `current_user_can( 'manage_options' )`
>   via the shared `permission_check()` helper.
> - **REST namespace is `acrossai-mcp-manager/v1`** — do not shorten
>   (constitution key rule 9).
> - **Every task must leave PHPStan level 8 + PHPCS individually
>   green before moving to the next.** Constitution §VII per-task
>   gating applies.
> - **Grep after every task** (updated post-SEC-001 rename):
>   `grep -rEn 'ExposureResolver::resolve_row_only\(' includes/Abilities/`
>   MUST still return exactly ONE match — the F030 call site with empty-meta
>   arguments. Additionally `grep -rEn 'ExposureResolver::resolve\(' includes/`
>   MUST return **zero matches** across the whole codebase (the old method
>   name is fully retired by SEC-001 Option A). Any hit that shows F030
>   calling `resolve_effective()` or the old `resolve()` name is a blocking
>   regression.
> - **Do NOT delete or rename `_reset_cache_for_tests()`.** F017
>   tests depend on it. Extend it to reset both new caches
>   alongside the existing one.
> - **Do NOT fire `acrossai_mcp_server_policy_changed` on
>   no-op transitions** — if the requested policy equals the current
>   policy, skip the DELETE and skip the action fire. Prevents audit
>   log spam.
> - **Do NOT rename the new hook `acrossai_mcp_server_policy_changed`
>   after merge.** It's the observability contract with companion
>   plugins from this feature forward.

```markdown
# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan
npm run build
npm run lint:js

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — Planning doc landed
- [ ] `docs/planings-tasks/082-per-server-ability-policy-defaults.md` exists on
      the feature branch (this file).
- [ ] `docs/planings-tasks/README.md` will get its F082 row in TASK-10 (not now
      — landing the row here would conflict with TASK-10's memory-hygiene diff).

### TASK-2 — `abilities_default_policy` column
- [ ] `includes/Database/MCPServer/Schema.php` includes the
      `abilities_default_policy` column definition after the last existing
      column.
- [ ] `includes/Database/MCPServer/Table.php` `$upgrades` array (currently
      lines 69-74) gains a new entry pointing at `upgrade_NEW_VERSION`.
- [ ] `$version` in `Table.php` is bumped to the new value (strict semver
      forward from the current value).
- [ ] `upgrade_NEW_VERSION()` protected method exists, is idempotent
      (checks `column_exists()` before ALTER), returns `bool`.
- [ ] Fresh activation: `SHOW COLUMNS FROM wp_acrossai_mcp_servers LIKE
      'abilities_default_policy'` returns one row with default
      `'per-ability'`.
- [ ] Existing install with pre-F082 tables: after `admin_init@3`
      reconciliation runs once, the column exists and every existing row
      has `abilities_default_policy='per-ability'` (backfill via column
      default).
- [ ] `wp option get acrossai_mcp_manager_db_version` returns the new
      `$version` after upgrade.
- [ ] Reactivation is a no-op (`SHOW WARNINGS` empty; `debug.log` silent).

### TASK-3 — Row surface
- [ ] `MCPServer\Row` exposes `abilities_default_policy` as a public
      property and includes it in `to_array()`.
- [ ] A `Query::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )`
      lookup returns a `Row` whose `abilities_default_policy` matches the DB.

### TASK-4 — Resolver split
- [ ] `ExposureResolver::resolve()` signature is byte-for-byte unchanged
      from pre-F082 — no new arguments, no new return values, no new
      cache lookups. Diff shows no changes to the body of this method.
- [ ] `ExposureResolver::resolve_effective()` exists as a new static
      method with the three-tier priority body.
- [ ] Two new static caches (`$effective_cache`, `$policy_cache`) exist
      alongside the original `$cache`.
- [ ] `_reset_cache_for_tests()` resets all three caches.
- [ ] Given `policy='expose'` server + no row for slug X + no
      `meta.mcp.public`: `resolve()` returns `false` (row-only + meta
      fallback); `resolve_effective()` returns `true` (server policy
      wins).
- [ ] Given `policy='hide'` server + no row + `meta.mcp.public=true`:
      `resolve()` returns `true` (meta fallback); `resolve_effective()`
      returns `false` (server policy wins).
- [ ] Given `policy='per-ability'` server + no row + `meta.mcp.public=true`:
      both methods return `true`.
- [ ] Given ANY policy + `is_exposed=1` row for slug: both methods
      return `true` (row wins in both).
- [ ] Second call in the same request returns the cached value (verify
      no duplicate `Query::instance()->query()` via `wp shell` or Xdebug).
- [ ] Review-gate comment lives above the `resolve()` call at
      `PermissionOverrideProcessor.php:150`.

### TASK-5 — Gate swap
- [ ] `AbilityExposureGate::gate_tool_call_by_exposure()` at line 131
      calls `resolve_effective()` (not `resolve_row_only()`, not the old `resolve()`).
- [ ] `grep -rEn 'ExposureResolver::resolve_row_only\(' includes/MCP/`
      returns **zero matches** (the swap correctly uses `resolve_effective`, not `resolve_row_only`).
- [ ] `grep -rEn 'ExposureResolver::resolve\(' includes/` returns **zero
      matches** (the old method name is fully retired by the SEC-001 rename).
- [ ] F015 (priority 10) and F020 (priority 30) gate call sites in
      `Main.php` are unchanged.
- [ ] Tool-call smoke test: on a `policy='expose'` server with a
      newly-registered ability (no row), the ability is callable via
      an MCP tool call (no 403).

### TASK-6 — REST endpoints
- [ ] `curl -X POST -H 'X-WP-Nonce: <nonce>' -H 'Content-Type: application/json'
      -d '{"policy":"expose"}' .../wp-json/acrossai-mcp-manager/v1/servers/1/abilities/policy`
      returns 200 with `{ has_abilities_api, abilities, abilities_default_policy: 'expose',
      affected_slugs: [...] }`.
- [ ] Invalid policy string (e.g. `"policy":"garbage"`) → 400.
- [ ] Non-existent server_id → 404 with `acrossai_mcp_server_not_found`.
- [ ] Unauthenticated POST → 403.
- [ ] After `policy='expose'` POST: `SELECT COUNT(*) FROM wp_acrossai_mcp_server_abilities WHERE server_id = 1`
      returns 0 (overrides nuked).
- [ ] After `policy='expose'` POST: subsequent GET response includes
      `abilities_default_policy: 'expose'` at the top level and every
      per-item `is_exposed: true`.
- [ ] `POST /abilities` (per-pair upsert) at 209-317 is byte-for-byte
      unchanged; the same client call succeeds identically.
- [ ] GET response gains `has_override` boolean per item.

### TASK-7 — React deltas
- [ ] `npm run build` succeeds; `build/js/abilities.js` and
      `build/js/abilities.asset.php` exist.
- [ ] Client-side merge at line 360-362 is DELETED — grep for the old
      `override ? override.is_exposed : !!mcpMeta.public` pattern returns
      zero matches.
- [ ] Enable All click shows the confirm modal with the copy from the
      TASK-7 delta; on confirm, hits `POST /abilities/policy` with
      `{policy:'expose'}`; UI reflects the new counter + pill.
- [ ] Disable All click shows the confirm modal; on confirm, hits
      `POST /abilities/policy` with `{policy:'hide'}`.
- [ ] Header pill reads "Default policy: Expose every ability by default"
      / "Hide every ability by default" / "Use each ability's own default"
      according to the current server policy.
- [ ] "Only overridden" is the fourth option in the exposure filter
      dropdown; selecting it shows only rows with `has_override=true`.
- [ ] Per-row toggle, Expose selected, Hide selected, Clear, Select all
      still hit the existing `POST /abilities` endpoint (unchanged).
- [ ] Cancel on the confirm modal is a true no-op — no fetch, no state
      change.

### TASK-8 — Actions preserved + additive
- [ ] `grep -rEn 'acrossai_mcp_ability_exposure_changed' includes/REST/`
      returns exactly one `do_action(...)` call site (the existing one
      at `AbilitiesController.php:306`).
- [ ] Single-pair POST still fires `acrossai_mcp_ability_exposure_changed`
      once per changed pair (register a temporary subscriber, click a
      per-row toggle, verify it fires once).
- [ ] `POST /abilities/policy` fires `acrossai_mcp_server_policy_changed`
      with `$affected_slugs` populated correctly (a subscriber can iterate
      the slugs and see the exact set that flipped).
- [ ] No-op policy transition (POST `{policy:'expose'}` when the server
      is already `policy='expose'`) does NOT fire the action a second
      time and does NOT re-DELETE the (empty) overrides table.

### TASK-9 — Tests
- [ ] `vendor/bin/phpunit tests/phpunit/Database/ExposureResolverTest.php`
      passes with the new `resolve_effective()` cases.
- [ ] The F030 regression fence test
      (`test_resolve_still_row_only_for_f030`) passes AND its assertion
      message text is present verbatim (grep the test file).
- [ ] `vendor/bin/phpunit tests/phpunit/REST/AbilitiesControllerTest.php`
      passes with the new `post_policy` cases.
- [ ] `vendor/bin/phpunit tests/phpunit/Database/PolicyReconcilerTest.php`
      passes.

### TASK-10 — Docs + memory
- [ ] `README.txt` `= Unreleased =` bullet is present.
- [ ] `docs/memory/DECISIONS.md` contains D51 (`DEC-SERVER-DEFAULT-POLICY-OVER-SNAPSHOT-ENROLMENT`)
      and D52 (`DEC-EFFECTIVE-STATE-COUNTER-OVER-ROW-COUNTER`) as Active
      entries.
- [ ] `docs/memory/INDEX.md` lists D51 + D52 under Active Decisions and
      has a WORKLOG row for F082.
- [ ] F017's `DEC-ABILITY-OVERRIDE-RESOLUTION` section carries the F082
      forward-pointer annotation.
- [ ] `docs/planings-tasks/README.md` lists the F082 row.

### Final full-repo audit (blocker before merge)

```bash
grep -rEn 'ExposureResolver::resolve_row_only\(' \
    --include='*.php' \
    includes/ admin/ public/
```
- [ ] Grep returns exactly ONE match: the F030 call site at
      `includes/Abilities/PermissionOverrideProcessor.php:150`, with
      empty meta as the third argument, and the F082 review-gate
      comment immediately above it. Any other hit is a blocking
      regression.

```bash
grep -rEn 'ExposureResolver::resolve\(' \
    --include='*.php' \
    includes/ admin/ public/
```
- [ ] Grep returns **zero matches**. The old method name is fully retired
      by the SEC-001 Option A rename. Any hit means the F030 call site
      was left calling the old name (silent no-op — the old method no
      longer exists) or a new consumer was introduced against the retired
      name.

```bash
grep -rEn 'ExposureResolver::resolve_effective\(' \
    --include='*.php' \
    includes/ admin/ public/
```
- [ ] Grep returns matches ONLY inside
      `includes/MCP/AbilityExposureGate.php` and
      `includes/REST/AbilitiesController.php`. Any hit inside
      `includes/Abilities/` is a blocking regression.

```bash
grep -rEn 'override \? override\.is_exposed : !!' \
    --include='*.js' \
    --include='*.jsx' \
    src/js/
```
- [ ] Grep returns **zero matches** — the client-side merge is gone.

```bash
grep -rEn 'react-query|@tanstack|redux|mobx|react-table|styled-components|@mui/' \
    --include='*.js' \
    --include='*.jsx' \
    src/js/
```
- [ ] Grep returns **zero matches** — WP-packages-only invariant from
      F017 still holds.

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors on
      `includes/Database/MCPServer/**`,
      `includes/Database/MCPServerAbility/ExposureResolver.php`,
      `includes/REST/AbilitiesController.php`,
      `includes/MCP/AbilityExposureGate.php`.
- [ ] PHPCS — zero errors on the same files.
- [ ] `npm run build` — succeeds with `build/js/abilities.js` +
      `build/js/abilities.asset.php` emitted.
- [ ] `npm run lint:js` — zero errors on `src/js/abilities.js`.
- [ ] `composer dump-autoload` — succeeds with zero warnings.
- [ ] Full PHPUnit suite passes (`vendor/bin/phpunit`).

---

## Pre-flight Attestation (SEC-082-001 / T001)

**Captured**: 2026-09-04 during F082 planning-doc authoring.

**Attestation**: No site outside `~/local-sites/` runs this plugin
against real MCP server data or real OAuth-issued tokens. The plugin
is dev/local only; no live install has a populated
`wp_acrossai_mcp_server_abilities` table with rows F082 would delete
on a `policy='expose'` transition.

**Basis for**: The `DELETE FROM wp_acrossai_mcp_server_abilities WHERE server_id = ?`
in `post_policy()` (TASK-6 step 6). On a fresh operator gesture, this
is the correct semantics — the server policy replaces the row-level
snapshot. Any live install with production overrides would need a
before-transition warning modal that lists the exact overrides about to
be nuked; the current confirm-modal copy suffices for dev-only use.

**Attesting user**: raftaar1191@gmail.com

**Validity window**: 2026-09-04 → Feature 082 merge. Any new
production install between attestation and merge invalidates the
override-nuke premise and requires a pre-nuke "you are about to delete
N overrides" modal.

---

## T-NN Evidence Collation Template (fill in after TASK-2 + TASK-6 + TASK-9)

This section is the merge-gate evidence pack. Every check below must
be filled in before Feature 082 can merge to `main`. Empty checkboxes
= blocking.

### 1. TASK-2 — Fresh-install column reconciliation smoke

**Preconditions**:
- Plugin deactivated.
- `ALTER TABLE wp_acrossai_mcp_servers DROP COLUMN IF EXISTS abilities_default_policy;`
- `DELETE FROM wp_options WHERE option_name = 'acrossai_mcp_manager_db_version';`

**Steps**:
1. Activate the plugin.
2. Trigger `admin_init` by loading any admin page.
3. Verify no PHP fatal in `wp-content/debug.log`.
4. Run the WP-CLI verifications below.

**Evidence (paste output here)**:
```
$ wp db query "SHOW COLUMNS FROM wp_acrossai_mcp_servers LIKE 'abilities_default_policy'"
<paste — expected: 1 row, type varchar(16), Null=NO, Default=per-ability>

$ wp db query "SELECT id, server_slug, abilities_default_policy FROM wp_acrossai_mcp_servers"
<paste — expected: every existing row has abilities_default_policy='per-ability'>

$ wp option get acrossai_mcp_manager_db_version
<paste — expected: the new bumped version>

$ wp db query "SHOW WARNINGS"
<paste — expected: empty>
```

**Timestamp**: `<YYYY-MM-DD HH:MM UTC>`
**Verifier**: `<user email>`
**Result**: [ ] PASS  [ ] FAIL

### 2. TASK-6 — End-to-end "Enable All" money case

**Preconditions**: One MCP server with `policy='per-ability'`, at least
one ability whose `meta.mcp.public=false` and no row in
`wp_acrossai_mcp_server_abilities`.

**Steps**:
1. Load the Abilities tab; note current counter ("X of Y exposed",
   where X = count of abilities with `meta.mcp.public=true`).
2. Click **Enable All**; confirm the modal.
3. Verify pill flips to "Default policy: Expose every ability by
   default"; counter flips to "All Y exposed — 0 overrides".
4. Register a new ability via a mu-plugin `wp_register_ability` call
   with `meta.mcp.public=false`.
5. Reload the Abilities tab.
6. **Money case**: the newly-registered ability is toggled ON in the
   UI and counter reads "All Y+1 exposed — 0 overrides".
7. Invoke the newly-registered ability from a connected MCP client
   (e.g. Claude Desktop) — the call must **succeed**. Before F082 it
   would 403.

**Evidence (paste output here)**:
```
$ wp db query "SELECT abilities_default_policy FROM wp_acrossai_mcp_servers WHERE id = <id>"
<paste — expected: expose>

$ wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_server_abilities WHERE server_id = <id>"
<paste — expected: 0>

$ curl ... call the newly-registered ability via the MCP endpoint ...
<paste — expected: 200 with the ability's normal response, NOT 403>
```

**Timestamp**: `<YYYY-MM-DD HH:MM UTC>`
**Verifier**: `<user email>`
**Result**: [ ] PASS  [ ] FAIL

### 3. TASK-6 — End-to-end "Disable All" money case

**Preconditions**: One MCP server with `policy='per-ability'`, several
abilities exposed (either via meta or via rows).

**Steps**:
1. Note the currently-exposed set on the Abilities tab.
2. Click **Disable All**; confirm the modal.
3. Verify pill flips to "Default policy: Hide every ability by default";
   counter flips to "All hidden — 0 overrides".
4. Register a new ability via a mu-plugin with `meta.mcp.public=true`.
5. Reload the Abilities tab.
6. **Money case**: the newly-registered ability is toggled OFF in the
   UI (despite its `meta.mcp.public=true`).
7. Attempt to invoke the newly-registered ability from a connected MCP
   client — the call must return 403 with
   `acrossai_mcp_ability_not_exposed`.

**Timestamp**: `<YYYY-MM-DD HH:MM UTC>`
**Verifier**: `<user email>`
**Result**: [ ] PASS  [ ] FAIL

### 4. TASK-9 — F030 security-regression fence

**Preconditions**: Server with `policy='expose'` and NO row in
`wp_acrossai_mcp_server_abilities` for slug `core/example-slug`.

**Command**:
```
cd /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager
vendor/bin/phpunit --filter test_resolve_still_row_only_for_f030
```

**Expected output**: OK (1 test, 2 assertions).

**Evidence (paste output here)**:
```
$ vendor/bin/phpunit --filter test_resolve_still_row_only_for_f030
<paste full output — expected:
PHPUnit ...

.                                                                   1 / 1 (100%)

Time: ..., Memory: ...

OK (1 test, 2 assertions)>
```

**Result**: [ ] PASS  [ ] FAIL — if FAIL, F030's row-only bypass has been
silently widened and the merge is blocked until the resolver split is
restored.

### 5. TASK-9 — Full PHPUnit sweep

**Command**:
```
vendor/bin/phpunit tests/phpunit/Database/ tests/phpunit/REST/
```

**Expected**: zero failures; new test methods from TASK-9 are all
represented in the count.

**Evidence (paste output here)**:
```
$ vendor/bin/phpunit tests/phpunit/Database/ tests/phpunit/REST/
<paste — expected: OK (N tests, M assertions)>
```

**Result**: [ ] PASS  [ ] FAIL

### 6. Whole-plugin grep audits (blockers)

```
$ grep -rEn 'ExposureResolver::resolve_row_only\(' includes/ admin/ public/
<paste — expected: exactly one match at includes/Abilities/PermissionOverrideProcessor.php:150
 with empty meta as the third arg and the F082 review-gate comment above it>

$ grep -rEn 'ExposureResolver::resolve\(' includes/ admin/ public/
<paste — expected: empty (old method name fully retired by SEC-001 rename)>

$ grep -rEn 'ExposureResolver::resolve_effective\(' includes/ admin/ public/
<paste — expected: matches ONLY under includes/MCP/AbilityExposureGate.php
 and includes/REST/AbilitiesController.php>

$ grep -rEn 'override \? override\.is_exposed : !!' src/js/
<paste — expected: empty>

$ grep -rEn 'acrossai_mcp_ability_exposure_changed' includes/REST/
<paste — expected: exactly one do_action(...) call at AbilitiesController.php:306>

$ grep -rEn 'acrossai_mcp_server_policy_changed' includes/REST/
<paste — expected: exactly one do_action(...) call in post_policy() handler>
```

**Result**: [ ] PASS  [ ] FAIL

### 7. Quality-gate summary

| Gate | Status | Evidence link |
|---|---|---|
| TASK-2 fresh-install reconciliation | [ ] PASS / [ ] FAIL | § 1 above |
| TASK-6 Enable All money case | [ ] PASS / [ ] FAIL | § 2 above |
| TASK-6 Disable All money case | [ ] PASS / [ ] FAIL | § 3 above |
| TASK-9 F030 regression fence | [ ] PASS / [ ] FAIL | § 4 above |
| TASK-9 full PHPUnit sweep | [ ] PASS / [ ] FAIL | § 5 above |
| Whole-plugin grep audits | [ ] PASS / [ ] FAIL | § 6 above |
| PHPCS + PHPStan L8 whole-plugin | [ ] PASS / [ ] FAIL | CI job link |
| `npm run build` + `npm run lint:js` | [ ] PASS / [ ] FAIL | CI job link |

**Merge decision**: [ ] APPROVE  [ ] BLOCK — signature + date required
when all pending gates are green.
