# Contract: Connect method registration (Feature 084)

The public interface this feature exposes. Two consumers depend on it: the paid companion
`acrossai-pro`, and any third-party plugin adding a connection method (FR-012). Everything below is
**stable API** — changing it is a breaking change for both.

---

## 1. Extension point — `acrossai_mcp_manager_connect_methods`

```php
/**
 * Filter the level-2 connection-method list inside the Connect tab.
 *
 * @param array<int, array<string, mixed>> $methods Normalized entry arrays.
 *                                                 Built-ins carry `_builtin => true`.
 * @param array<string, mixed>             $server  Server row array (id, name, slug, …).
 */
$raw = apply_filters( 'acrossai_mcp_manager_connect_methods', $seeded, $server );
```

Applied in exactly one place: `Connect\MethodRegistry::collect()` (private). `ConnectTab` consumes
the registry through `visible_methods()` and **never re-fires the filter** — one canonical
enumeration path (decision D35).

### Entry shape

Mirrors the level-1 `acrossai_mcp_manager_server_tabs` contract key-for-key, so a developer who knows
one knows the other.

| Key | Type | Required | Default | Notes |
|-----|------|----------|---------|-------|
| `slug` | `string` | **yes** | — | Passed through `sanitize_key()`. Empty after sanitizing → entry dropped. Becomes the `?method=` value. |
| `label` | `string` | **yes** (non-built-in) | — | Missing → dropped with `_doing_it_wrong()` under `WP_DEBUG`. |
| `render_callback` | `callable` | **yes** (non-built-in) | — | Not callable → dropped with `_doing_it_wrong()` under `WP_DEBUG`. Receives `array $server`. |
| `priority` | `int` | no | `100` | Ascending sort. See the reserved scale below. |
| `capability` | `string` | no | `manage_options` | `sanitize_key()`; empty → the default. Checked **before** the method's content is produced (FR-007). |
| `visible_callback` | `callable\|null` | no | `null` | Non-callable → coerced to `null`. Receives `array $server`, returns `bool`. |
| `_builtin` | `bool` | internal | `false` | Set by the registry's own seeding. Third parties MUST NOT set it. |

### Dedup semantics — last-wins, by slug

Entries are keyed by slug during normalization, so a **later** registration with an existing slug
**replaces** the earlier one; the winner keeps the earlier insertion index so priority tie-breaks stay
stable. This is decision **D41**, and it is exactly the mechanism by which the companion's real
Connectors method replaces the built-in promotional card (spec US5 scenario 4).

Both this registry and `ServerTabs\Registry` obtain this behaviour from the single shared
`Includes\Utilities\RegistryEntryNormalizer::normalize()`. They MUST NOT carry independent
implementations — divergence here silently breaks the promo→real swap.

### Reserved priority scale

| Priority | Method slug | Supplier |
|----------|-------------|----------|
| 10 | `ai-connectors` | this plugin (promo card) → replaced by `acrossai-pro` |
| 20 | `clients` | this plugin |
| 30 | `npm` | this plugin |
| **40** | `n8n` | **reserved — `acrossai-pro` only** |
| 50 | `wp-cli` | this plugin |

Third parties should register at ≥ 60 to sit after the built-ins, or claim a slot deliberately.

### Capability and visibility filtering (constraint **C2**)

> **Naming, deliberately NOT mirrored from the sibling.** In `ServerTabs\Registry`, `for_server()` is
> the **unfiltered** accessor (`Registry.php:158`) and `visible_tabs()` is the filtered one
> (`:198`) — and `render()` dispatches off the *unfiltered* list (`:223-235`). Copying that naming
> here would make `MethodRegistry::for_server()` read as "unfiltered" to anyone who knows the sibling,
> while C2 requires the opposite. The method registry therefore exposes:
>
> ```php
> public function visible_methods( array $server ): array   // THE only public read path — filtered
> private function collect( array $server ): array          // fires the filter, normalizes, sorts
> ```
>
> **No public accessor returns the resolved, filter-applied list unfiltered.** `visible_methods()`
> is the sole path to that list, and `ConnectTab` calls it for the navigation, for resolution, and
> for dispatch — one list, one meaning.
>
> `all_methods()` is public but is **not** that accessor: it returns only the four built-in
> instances used to seed the filter, never third-party contributions and never a resolved list.
> It mirrors `Registry::all_tabs()`, which is public for the same reasons (tests, introspection,
> administrative tooling reading ground truth). Corrected 2026-09-07 — an earlier draft claimed
> "no public unfiltered accessor" without that qualification, which the implementation contradicts
> and which would trip a future C2 audit as a false positive.

`visible_methods()` returns a set already narrowed by `current_user_can( $entry['capability'] )` and
by `visible_callback`, so no resolution step (§4), *including the fallback*, can name a method the
current user may not see. A filtered-out method's `render_callback` is never invoked.

### Error containment (FR-013, constraint **C4**)

A `render_callback` that throws is caught at the method boundary; an inline error is emitted in place
of that method's content and the navigation plus the rest of the screen keep working. A throwing
method does not remove itself from the navigation.

The catch is on **`\Throwable`**, not `\Exception` — a `TypeError` from a mis-registered third-party
callback is the likeliest real failure and would otherwise escape containment and white-screen the
page, defeating FR-013 exactly when it matters.

The emitted error is a **fixed, translated, escaped** string naming only the failing method slug.
It MUST NOT contain the exception message, file path, class name, or stack trace — those go to
`error_log()` behind a `WP_DEBUG` guard, matching `Registry::doing_it_wrong()`'s development-only
signalling.

---

## 2. URL builder — `ConnectTab::method_url()`

```php
/**
 * @return string RAW, UNESCAPED admin URL. Callers MUST escape at output.
 */
public static function method_url( array $server, string $method ): string
```

Produces `admin.php?page=acrossai_mcp_manager&action=edit&server=N&tab=connect&method=<method>`.

**Raw-return guarantee.** The value is deliberately not `esc_url()`-wrapped, because callers chain
onto it — `public/Renderers/MCPClientsBlock.php:146` appends `&client=<slug>` via `add_query_arg()`.
Pre-escaping turns the separator into `&#038;` and breaks every level-3 link. Security constraint
**S5** is satisfied at each output site instead. This mirrors the existing
`AbstractServerTab::server_edit_url()` contract.

Public and static so the companion can call it cross-plugin without instantiating the tab. That wider
reach is exactly why the escaping obligation is enumerated rather than assumed (**B6**, **B8**,
constraint **C1**).

The docblock MUST state the raw contract **and** name `MCPClientsBlock:146` as the reason, so a
future tidy-up does not "fix" the missing `esc_url()` by adding it inside the builder.

#### Output-site inventory (constraint **C1**)

Every place a `method_url()` value reaches HTML, and the escaper that MUST appear there. Escaping at
the output site is required even where it looks redundant — `esc_*` is idempotent, and
"escaped upstream" reasoning is rejected by **B8**.

| Output site | Context | Required escaper | Verified |
|-------------|---------|------------------|----------|
| `ConnectTab::render_method_nav()` | `<a href="…">` per method | `esc_url()` | ✅ `ConnectTab.php:245` |
| `MCPServerListTable` — Connectors row shortcut | `<a href="…">` | `esc_url()` | ✅ shared `printf` at `MCPServerListTable.php:~321` |
| `MCPServerListTable` — MCP Clients row shortcut | `<a href="…">` | `esc_url()` | ✅ same `printf` |
| `ClientsTab` → `submit_target_url` → `MCPClientsBlock::render_subnav()` | `<a href="…">` after `add_query_arg( 'client', … )` at `:146` | `esc_url()` | ✅ `MCPClientsBlock.php:155` — pre-existing, preserved |
| `acrossai-pro` — `AIConnectorsTab::panel_url()` | `<a href="…">` after chaining `&panel=` | `esc_url()` | ⬜ companion PR |
| `acrossai-pro` — `N8nTab::panel_url()` | `<a href="…">` after chaining `&panel=` | `esc_url()` | ⬜ companion PR |

**Correction (implementation, 2026-09-07):** an earlier draft of this table listed
`NpmTab → submit_target_url → form action` as a seventh output site. It is not one.
`NpmClientBlock` neither emits a `<form>` nor reads `submit_target_url` — the value `NpmTab` passes
is currently **unconsumed**, and was equally unconsumed before F084 when it passed
`server_edit_url()`. `NpmTab` was still repointed, so the two sibling tabs stay consistent and a
future `NpmClientBlock` that does consume the value gets the correct one; but it is not an escaping
obligation today. Listing a site that cannot be verified would weaken the table.

Any new consumer added later joins this table. `quickstart.md` carries the canary grep that fails the
build when a `method_url()` output site lacks an escaper.

---

## 3. Legacy address mapping — `ConnectTab::LEGACY_TAB_METHODS`

```php
public const LEGACY_TAB_METHODS = array(
    'ai-connectors' => 'ai-connectors',
    'clients'       => 'clients',
    'npm'           => 'npm',
    'n8n'           => 'n8n',
    'wp-cli'        => 'wp-cli',
);
```

The single source of truth for backwards compatibility, with two readers and no duplication:

- `Settings::render_edit_page()` rewrites `$tab` to `'connect'` **in place** when the requested tab is
  a key here, so dispatch finds `ConnectTab` and the strip highlights Connect.
- `ConnectTab::resolve_active_method()` reads the **pre-rewrite** `?tab=` value against the same
  constant to decide which method opens.

Both reads are `sanitize_key( wp_unslash( $_GET[…] ?? '' ) )` with a scoped
`WordPress.Security.NonceVerification.Recommended` suppression, matching `Settings.php:658`.
*Pre-rewrite* means "before `Settings` normalized its copy", never "unsanitized" — the word **raw** in
this contract refers exclusively to §2's escaping guarantee (constraint **C5**).

**Never a redirect** (FR-009). `admin_enqueue_scripts` fires before render, and the companion gates
its assets on the requested address; redirecting would rewrite the address before those gates saw it.

Any deeper selection the legacy address carries (`&client=`, `&panel=`) is untouched and stays active.

---

## 4. Active-method resolution order

Evaluated once per render against the **capability- and visibility-filtered** set returned by
`MethodRegistry::visible_methods()`. First match wins:

1. `?method=` — present, `sanitize_key()`-clean, and in the filtered set.
2. The pre-rewrite `?tab=` is a `LEGACY_TAB_METHODS` key → its mapped method, if in the filtered set.
3. `LocalEnvironment::needs_tls_bypass()` is `true` and `clients` is in the filtered set → `clients`.
4. First method in the filtered set, in priority order.

Every step — **including the step-4 fallback** — draws from the filtered set, so a restricted user can
never land on a method they may not see. Checking capability after resolution would make step 4 a
bypass: the first-in-order method is `ai-connectors`, a paid one (constraint **C2**).

Unknown, removed, and permission-excluded all converge on **one** code path, so their observable
behaviour is identical and withheld methods cannot be enumerated (FR-007). The fallback is
**silent**: the requested value is never rendered, echoed into a notice, or surfaced in any
user-visible log (constraint **C3**).

Step 3 reuses the existing local-environment helper verbatim — the sole gate for "is this site local"
(decision **D46**); no second notion of local and no admin toggle.

---

## 5. Navigation rendering contract

- Suppressed entirely when fewer than two methods are visible (FR-018) — no lonely single control.
- Zero visible methods → a plain explanatory message, not an empty panel.
- Exactly **one** URL shape is emitted, always `method_url()`. There is no legacy-shaped branch; per
  spec Clarifications no accommodation is built for an un-migrated companion, and reviewers reject
  any reintroduction of a second shape.
- The active method carries `aria-current="page"`; all methods are keyboard-reachable in visual order
  (FR-019).
- Level-2 uses core's **`.nav-tab` classes scaled one step down**, and level 3 restates the same
  idiom one step smaller again under its own classes (FR-017) — one family, told apart by scale;
  three visually
  identical stacked rows read as a rendering fault.

---

## 6. Companion-side surface (`acrossai-pro`)

Not part of this plugin, specified here because it is the other half of the matched pair.

```php
HostCapabilities::has_connect_tab(): bool           // class_exists + method_exists probe (F040)
HostCapabilities::method_url( array $server, string $method ): string
HostCapabilities::is_connect_method_request( string $method ): bool  // matches BOTH address forms
```

- `has_connect_tab()` is a **capability probe**, never a version-string comparison.
- When true → register `ai-connectors` (10) and `n8n` (40) on
  `acrossai_mcp_manager_connect_methods`. When false → register on
  `acrossai_mcp_manager_server_tabs` exactly as today, which is what satisfies FR-015.
- `is_connect_method_request()` matches both the new (`tab=connect&method=n8n`) and legacy
  (`tab=n8n`) forms, so the two enqueue gates keep firing on either address.

---

## 7. What this contract does **not** touch (FR-020 / SC-008)

These subsystems contain identical-looking strings and are explicitly out of scope. A search for each
must return the same results before and after the change:

- `AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry` — a different layer entirely.
  `Connect\MethodRegistry`'s docblock cross-references it, and vice versa, so neither is grepped in
  mistake for the other.
- `QuickConnectController::VALID_METHODS`
- the `ClientRendererController` renderer map
- embed transport keys
- the Quick Connect wizard's JS method keys

Audit greps for the five retired slugs MUST be anchored on code syntax (`'tab' => '…'`), never on
bare slugs — the new code legitimately names all five inside `LEGACY_TAB_METHODS` and in docblocks
(bug pattern **B51**).
