# Planning: Remove client emoji from picker surfaces (Feature 076)

## In plain English

The MCP client picker on two admin surfaces currently shows an emoji next to each client's name — 🍰 Claude Desktop, 📄 Claude Code, ⚡ Cursor, and so on. You asked for the emoji removed from the UI. The `get_icon()` method on each of the 16 client classes stays put — Claude Desktop's method still returns `'🍰'`, Cursor still returns `'⚡'`, etc. — so any third-party renderer or companion plugin that reads the value still gets it. Only the two visible pickers stop rendering it.

The two surfaces are the same picker rendered by different code:

1. **Per-server MCP Clients tab** — pill sub-nav at the top ("Claude Desktop | Claude Code | VS Code | …") plus the `<h2>` heading below.
2. **Quick Setup wizard Step 11** — grid of buttons on the "Choose your client" screen.

Zero back-end logic change. Two PHP edits + one JSX conditional deletion + `npm run build`.

## Context

- User request: "remove the icon from the UI but keep that in classes where it has been defined."
- Confirmed via two Explore agents that `get_icon()` is rendered in exactly three code paths — two in `public/Renderers/MCPClientsBlock.php` (pill sub-nav + detail heading) and one in `src/js/quick-setup/steps/Step11_ClientDetail.jsx` (picker button). Every other admin/wizard surface that Explore checked (Step 7 method grid, Step 10 connectors detail, Step 12 npm detail, embeds.js) either uses a different icon system or doesn't render any icon.
- No test coverage on rendered emoji strings — the golden fixtures in `tests/phpunit/MCPClients/fixtures/` assert on the copy-paste JSON output, not on the picker markup.

## Authoritative sources

- Explore report — `MCPClientsBlock.php` render sites at lines 144 / 149–155 (pill sub-nav) and 173 / 180–184 (detail heading).
- Explore report — `Step11_ClientDetail.jsx` render site at lines 187–191 inside the `<button>` body.
- `AbstractMCPClient::get_icon()` at `includes/MCPClients/AbstractMCPClient.php:97` — the abstract-base default (`return '';`), with 16 concrete overrides across `includes/MCPClients/*Client.php`. Unchanged.
- `ConnectionMethodRegistry::get_clients()` at `public/Discovery/ConnectionMethodRegistry.php:218` — DTO field `'icon' => $client->get_icon()`. Unchanged (defensive: third-party JSX may still consume it).

## Final scope

Retained:
- Two PHP printf rewrites in `MCPClientsBlock.php` — pill sub-nav loses the `<span class="acrossai-client-tab-icon">` fragment; heading loses the leading emoji.
- One JSX conditional removed in `Step11_ClientDetail.jsx` — the `{ c.icon && (<span>{ c.icon }</span>) }` block goes away entirely.
- `npm run build` to refresh the wizard bundle.
- One `= Unreleased =` bullet in `README.txt`.

Not in scope:
- **No change to any `get_icon()` method definition** on the abstract base or any of the 16 concrete subclasses.
- **No change to the `icon` field on the ConnectionMethodRegistry DTO** — future third-party renderers or companion plugins may still consume it, and removing the DTO field would be a subtle shape break for a purely cosmetic UI change.
- No CSS follow-up — the `acrossai-client-tab-icon` class disappears with the emoji span; the pill's outer flex/gap handles the remaining layout. If the empty class survives in `backend.scss` it becomes dead but not harmful; a future cleanup can remove it.
- No PHPUnit changes — no existing test asserts on the picker markup.
- Other icon surfaces (Embeds tab in `src/js/embeds.js`; Step 7 method grid using SVG React components) are unaffected — different icon systems.

## Durable lesson

**When retiring UI usage of a per-subclass display property, keep both the source-of-truth method AND the DTO field intact.** Third-party renderers, companion plugins, or a future in-house consumer may still want the value. Removing the DTO field is a shape break; removing the method is worse. Only the DIRECT UI usage in the touched renderers goes away. Candidate for a `DEC-` entry after implementation.

## Reference code

Rewrite target in `MCPClientsBlock.php` (pill sub-nav):

```php
// Before
$emoji = $client->get_icon();
printf(
    '<a href="%1$s" class="%2$s"><span class="acrossai-client-tab-icon">%3$s</span><span>%4$s</span></a>',
    esc_url( $url ),
    esc_attr( $css_class ),
    esc_html( $emoji ),
    esc_html( $client->get_client_name() )
);

// After
printf(
    '<a href="%1$s" class="%2$s"><span>%3$s</span></a>',
    esc_url( $url ),
    esc_attr( $css_class ),
    esc_html( $client->get_client_name() )
);
```

Rewrite target in `MCPClientsBlock.php` (detail heading):

```php
// Before
$emoji = $client->get_icon();
printf(
    '<h2>%1$s %2$s</h2>',
    esc_html( $emoji ),
    esc_html( $client->get_client_name() )
);

// After
printf(
    '<h2>%s</h2>',
    esc_html( $client->get_client_name() )
);
```

Rewrite target in `Step11_ClientDetail.jsx` (picker button):

```jsx
// Before
{ c.icon && (
    <span style={ { marginRight: 6 } }>{ c.icon }</span>
) }
{ c.name || c.slug }

// After
{ c.name || c.slug }
```

## Speckit workflow

```markdown
# 1. Branch
/speckit.git.feature "remove-client-picker-icons"

# 2. Specify — paste the "Final scope" + FR-001..FR-005 from spec.md.

# 3. Clarify (likely no critical ambiguities — the ask is precise).

# 4. Plan + tasks
/speckit.plan
/speckit.tasks

# 5. Implement + quality checks
/speckit.implement
composer run phpcs
composer run phpstan
composer run test -- --testsuite mcpclients
npm run build

# 6. Review + memory + commit
/speckit.analyze
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

Added: 2026-08-24 on branch `076-remove-client-picker-icons`.
