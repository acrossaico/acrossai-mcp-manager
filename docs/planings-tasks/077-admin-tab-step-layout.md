# Planning: Numbered STEP layout on the per-server MCP Clients admin tab (Feature 077)

## In plain English

The Quick Setup wizard's Step 11 renders the client-configuration walkthrough as five numbered blocks:

> **STEP 1** — Generate the password
> **STEP 2** — Open the config file
> **STEP 3** — Locate the top-level key
> **STEP 4** — Copy this config and paste it under the top-level key
> **STEP 5** — Restart the MCP client

Each block has a small pill-badge ("STEP 1", "STEP 2", …) next to a bold sub-heading, then a body area with the content.

The per-server **MCP Clients** admin tab (`?page=acrossai_mcp_manager&action=edit&server=1&tab=clients`) renders the same content but as a flat layout — Generate button, then "Config File" label + value row, then "Top-Level Key" label + value row, then "Configuration JSON" heading + warning + textarea + Copy button, then "Restart:" callout, then Instructions + Access Control paragraph. Same information, no numbered scaffold.

Feature 077 wraps every existing section of the admin tab's `render_client_details()` output in the same `.qs-step` / `.qs-step-heading` / `.qs-step-heading__num` / `.qs-step-body` shell the wizard uses. Same content, same buttons, same JSON, same warnings — reorganized under numbered STEP headers so both surfaces read identically. Zero back-end behavior change; PHP printf edits + CSS additions to the admin stylesheet + `npm run build` for the admin CSS bundle.

## Context

- User reported (2026-08-24): "here we are not able to see the steps like we see it in quick setup" — pointing at the admin tab vs. Step 11 of the wizard.
- The wizard's step layout is stable and desirable (F073 + F075 follow-up shipped it). Reusing the exact class names on both surfaces prevents drift.
- No behavioral change — every button, warning notice, restart hint, and Access Control paragraph that renders today keeps rendering. Only the *visual scaffolding around them* changes.

## Authoritative sources

- **Wizard step markup**: `src/js/quick-setup/steps/Step11_ClientDetail.jsx:203–369` — canonical shape.
- **Wizard step CSS**: `src/scss/quick-setup.scss:681–714` — the four `.qs-step*` rules to port.
- **Admin renderer to refactor**: `public/Renderers/MCPClientsBlock.php::render_client_details()` — around lines 175–290 post-F076.

## Final scope

Retained:
- 5-part refactor of `render_client_details()`: each existing content section wrapped in a `<div class="qs-step"><h3 class="qs-step-heading"><span class="qs-step-heading__num">Step N</span> <title></h3><div class="qs-step-body">…existing content…</div></div>` scaffold.
  - **STEP 1 — Generate the password**: existing `passwords_generate_button()` call + description paragraph.
  - **STEP 2 — Open the config file**: existing Config File readonly row → move the value into `qs-step-body` (readonly input, wizard-style).
  - **STEP 3 — Locate the top-level key**: existing Top-Level Key readonly row → same treatment.
  - **STEP 4 — Copy this config and paste it under the top-level key**: existing local-dev warning notice + Configuration JSON textarea + Copy button, all inside the step body.
  - **STEP 5 — Restart the MCP client**: existing "Restart:" callout content → the client-specific restart text (`get_restart_step_text()`) becomes the step body.
- Port the 4 CSS rules (`.qs-step`, `.qs-step-heading`, `.qs-step-heading__num`, `.qs-step-body`) from `src/scss/quick-setup.scss` into `src/scss/backend.scss` so the admin bundle picks them up.
- Regenerate `build/css/backend.css` + `.asset.php` via `npm run build`.
- Trailing Access Control notice unchanged — stays as the closing paragraph after Step 5.
- One `README.txt` `= Unreleased =` bullet.

Not in scope:
- **No JSX changes** — the wizard already ships the numbered layout.
- **No CSS-token refactor** — the wizard's step rules reference `$qs-text` and `$qs-primary` SCSS vars. Port with literal colors that match (avoid pulling in the wizard's whole variable file into the admin bundle).
- **No `get_icon()` re-render** — F076's icon removal stays in force; the STEP headings don't reintroduce emoji.
- **No REST/DTO changes** — feature is purely presentational.
- **No PHPUnit changes** — no test asserts on the flat layout markup.

## Durable lesson

**When two admin surfaces render the same domain object (per-client walkthrough here — F073's DTO producer and F075 follow-up's `restartStep` field both landed with cross-surface parity in mind), the visual scaffolding should ALSO be shared, not just the data.** F077 completes the pattern by aligning the outer markup (`.qs-step*`) across the wizard and the admin tab. Candidate for a `DEC-` companion to D43 (cross-surface DTO producer unification) — "cross-surface VISUAL parity via shared markup contract".

## Reference code

**Wizard step scaffold** (existing, from `Step11_ClientDetail.jsx`):

```jsx
<div className="qs-step">
    <h3 className="qs-step-heading">
        <span className="qs-step-heading__num">
            { __( 'Step 1', 'acrossai-mcp-manager' ) }
        </span>
        { __( 'Generate the password', 'acrossai-mcp-manager' ) }
    </h3>
    <div className="qs-step-body">
        { /* button + description */ }
    </div>
</div>
```

**Admin PHP equivalent** (F077 target shape):

```php
echo '<div class="qs-step">';
printf(
    '<h3 class="qs-step-heading"><span class="qs-step-heading__num">%1$s</span>%2$s</h3>',
    esc_html__( 'Step 1', 'acrossai-mcp-manager' ),
    esc_html__( 'Generate the password', 'acrossai-mcp-manager' )
);
echo '<div class="qs-step-body">';
// existing generate-button + description block
echo '</div></div>';
```

Repeat for Steps 2–5, each wrapping its existing content untouched.

## Speckit workflow

```markdown
# 1. Branch
/speckit.git.feature "admin-tab-step-layout"

# 2. Specify — paste the "Final scope" + FR-001..FR-006 from spec.md.

# 3. Clarify (likely no critical ambiguities — visual refresh).

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

Added: 2026-08-24 on branch `077-admin-tab-step-layout`.
