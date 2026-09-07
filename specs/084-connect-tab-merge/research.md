# Research: Merge the five connection tabs into one "Connect" tab (Feature 084)

Every finding below was read out of the current working tree on branch `084-connect-tab-merge`, not
inferred from the planning brief. Line numbers are as of 2026-09-07.

---

## Decision 1 — Extract entry *normalization* to `includes/Utilities/`; keep *hydration* in the admin layer

**Decision**: `Includes\Utilities\RegistryEntryNormalizer` (final, static-only) owns
`normalize()` and the parameterised `doing_it_wrong()` wrapper. `Registry::hydrate()` does **not**
move there; its shared loop body becomes
`Admin\Partials\ServerTabs\FilteredServerTab::hydrate_entries( array $entries, array $builtin_map )`.

**Rationale**: `Registry::normalize_entries()` (`admin/Partials/ServerTabs/Registry.php:284-344`) is
pure array work — `sanitize_key()` on slug, label/callable validation, `priority` coercion,
`capability` defaulting, and slug-keyed last-wins dedup. Nothing in it touches WordPress admin state,
so it is context-neutral and A9-eligible.

`Registry::hydrate()` (`:357-372`) is the opposite: it builds a `$builtin_map` from `all_tabs()` and
wraps every non-built-in entry in `new FilteredServerTab( $entry )`. Both are admin-layer types, and
the Architecture & UI Standards rule is explicit that classes in `includes/` "MUST NOT contain
admin-specific logic". Moving hydration into the normalizer would trade a §VI win for an A3 breach.

**Alternatives considered**:
- *Copy the validation into `MethodRegistry`.* Rejected — a straight §VI violation, and it would
  become a **hard** memory conflict rather than a soft one, because D41's slug-keyed last-wins dedup
  is what lets the companion's real Connectors method replace the built-in promo card. Two
  independently-maintained dedup implementations is exactly the divergence bug §VI exists to prevent.
- *Move both `normalize` and `hydrate` into the utility.* Rejected on A3, as above.
- *Add a fourth new class, `ServerTabs\EntryHydrator`.* Rejected — twelve lines do not justify a new
  file when `FilteredServerTab` is already the class that performs the wrapping.

---

## Decision 2 — `ConnectTab::method_url()` returns a raw, unescaped string by contract

**Decision**: the builder returns the output of `add_query_arg()` over `admin_url( 'admin.php' )`
with no escaping. Every caller escapes at its own output site.

**Rationale**: `public/Renderers/MCPClientsBlock.php:146` does

```php
$url = add_query_arg( 'client', $slug, (string) $context['submit_target_url'] );
```

— it chains a further query argument onto the value the tab supplied. If the builder pre-escaped, the
`&` would arrive as `&#038;` and every one of the sixteen level-3 client links would break. The same
contract already governs `AbstractServerTab::server_edit_url()` (`:499`), which this builder sits
beside; keeping them consistent avoids a trap where two adjacent URL helpers have opposite
escaping semantics.

Security constraint **S5** (`admin_url()` is filterable and must be `esc_url()`-wrapped before HTML
output) is therefore satisfied at the *output* site. This is stated in the method docblock and pinned
by a test that asserts the returned string contains a bare `&`.

**Alternatives considered**: escaping inside the builder and having `MCPClientsBlock` decode before
chaining. Rejected — it inverts the normal direction of escaping and adds a decode step whose only
purpose is to undo the builder.

---

## Decision 3 — Only two `server_edit_url()` call sites need repointing

**Finding**: a repo-wide grep returns exactly three call sites outside the definition:

| File | Line | Tab argument | Migrating? |
|------|------|--------------|-----------|
| `admin/Partials/ServerTabs/ClientsTab.php` | 75 | `'clients'` | **yes** → `ConnectTab::method_url( $server, 'clients' )` |
| `admin/Partials/ServerTabs/NpmTab.php` | 73 | `'npm'` | **yes** → `ConnectTab::method_url( $server, 'npm' )` |
| `admin/Partials/ServerTabs/AccessControlTab.php` | 93 | `'access-control'` | no — not a connection method; stays put |

`WpCliTab` and `AIConnectorsPromoTab` build no form target at all, so they need only their
`priority()` re-slotted. The two `open_form()` overrides that exist (`UpdateServerTab:94`,
`DangerZoneTab:97`) belong to non-migrating tabs.

**Consequence**: `AbstractServerTab::server_edit_url()` stays — it still serves `AccessControlTab`
and every third-party tab. It is **not** deprecated by this feature.

---

## Decision 4 — The built-in tab count is 11 → 8, enumerated not estimated

**Finding**: `Registry::all_tabs()` (`:123-147`) returns, in source order: `OverviewTab`, `NpmTab`,
`ClientsTab`, `AIConnectorsPromoTab`, `WpCliTab`, `ToolsTab`, `AbilitiesTab`, `AccessControlTab`,
`McpTrackerTab`, `UpdateServerTab`, `DangerZoneTab` — **11**. Removing four and adding `ConnectTab`
gives **8**, matching SC-001.

`EmbedsTab` (priority 90) and `WidgetsTab` are present as classes but explicitly commented out of
`all_tabs()` since 0.2.10, so they are in neither count and are out of scope, as the spec assumes.

**Consequence for tests**: `RegistryTest` asserts the built-in count as a literal. Per bug pattern
**B48**, the fix is to derive the expectation from `all_tabs()` rather than change `11` to `8` —
otherwise the same assertion breaks again the next time a tab is added or removed.

---

## Decision 5 — Extend the existing `$legacy_slug_map`; do not build a parallel mechanism

**Finding**: `Settings::render_edit_page()` already performs in-place legacy tab-slug rewriting at
`admin/Partials/Settings.php:663-669`:

```php
$legacy_slug_map = array(
    'general'        => 'overview',
    'access_control' => 'access-control',
);
if ( isset( $legacy_slug_map[ $tab ] ) ) {
    $tab = $legacy_slug_map[ $tab ];
}
```

**Decision**: the five connection slugs join this same map, mapping to `'connect'`. The method that
should open is *not* threaded through from here — `AbstractServerTab::render()` is `final` and takes
only `array $server`, so there is no parameter to thread it on. Instead
`ConnectTab::resolve_active_method()` re-reads the **pre-rewrite** `?tab=` value — i.e. before
`Settings` normalized its own copy to `'connect'` — and consults the same
`ConnectTab::LEGACY_TAB_METHODS` constant. One constant, two readers, no duplication.

**Pre-rewrite is not unsanitized.** The read is
`sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) )`, identical to the existing read shown above, with
the same scoped `WordPress.Security.NonceVerification.Recommended` suppression. Security constraint
**C5** reserves the word *raw* for the URL-escaping contract (Decision 2) so the two senses cannot be
confused.

**Rationale for no redirect** (FR-009): `admin_enqueue_scripts` fires before `render_edit_page()`,
and the companion gates its JS/CSS on exact `?tab=ai-connectors` / `?tab=n8n` string matches. A
redirect would rewrite the address *before* those gates ever saw it, leaving the companion's panels
unstyled with inert buttons — the precise failure the matched-pair release is otherwise designed to
avoid. In-place normalization keeps the requested address intact for anything keyed to it, and the
address heals on the operator's first click on the new navigation.

---

## Decision 6 — Companion detects the host by capability probe, not version string

**Decision**: the companion's `HostCapabilities::has_connect_tab()` asks
`class_exists( ConnectTab::class ) && method_exists( ConnectTab::class, 'method_url' )` and registers
on the method filter when true, the tab filter when false.

**Rationale**: this is the **F040** pattern already proven in this codebase for the AI Connectors
migration — a capability probe with zero data migration. Version-string comparison would need the
companion to track host release numbers it has no other reason to know, and would break the moment a
host version is backported or a pre-release is installed.

This is what makes FR-015 cheap: the reverse pairing (companion updated first, host not) costs one
`class_exists()` call and the screen behaves exactly as it does today.

---

## Non-decisions (already settled upstream, recorded so they are not re-opened)

- **No compatibility layer** for an un-migrated companion — settled in spec Clarifications
  (2026-09-07). `Registry::for_server()` keeps its straight-line shape, the tab filter fires exactly
  once per render, and the level-2 nav emits exactly one URL shape.
- **"Method" is the level-2 noun** — settled in spec Clarifications.
- **Local detection reuses `LocalEnvironment::needs_tls_bypass()` verbatim** — decision **D46**: the
  detection helper is the sole gate for "is this site local"; no second notion is introduced and no
  admin toggle is added.
- **Out-of-scope vocabularies** — `Public\Discovery\ConnectionMethodRegistry`,
  `QuickConnectController::VALID_METHODS`, the `ClientRendererController` renderer map, the embed
  transport keys, and the Quick Connect wizard's JS method keys all contain identical-looking strings
  (`'npm'`, `'clients'`, `'connectors'`) and are untouched (FR-020, SC-008).
