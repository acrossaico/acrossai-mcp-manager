# Quickstart — verifying Server Types

> **Retracted, 2026-09-18 — the coarse tool rule is gone.** Anything below that
> describes `tools_default_policy` (the `expose` / `hide` standing rule, its
> column, and `POST /servers/{id}/tools/policy`) was built, merged to `main` in
> schema `1.1.6`, then removed again in schema `1.1.7` before any release.
> `MCP\ToolExposureGate` gates `tools/call` on curated presence rows alone and
> never read the column, so an `expose` server advertised tools it then refused.
> What a server serves is now decided by its curated selection alone; the Tools
> tab's **Enable All** / **Disable All** write that selection directly. The
> `server_type` half of this feature stands as specified. `abilities_default_policy`
> (F082, Abilities tab) is a different feature and is unaffected.


Manual verification on a local install. Assumes `wordpress-7-0.local` with both
`acrossai-mcp-manager` and `acrossai-abilities-manager` present.

> **Database access on Local**: `wp-cli` cannot reach Local's MySQL socket (its bundled PHP
> uses a different default). Use the socket directly:
>
> ```bash
> SOCK="$HOME/Library/Application Support/Local/run/<id>/mysql/mysqld.sock"
> mysql --socket="$SOCK" -uroot -proot local -e "SELECT …"
> ```
>
> Find `<id>` with `lsof -U | grep mysql`.

---

## 1. Migration

```bash
# BEFORE — expect 1.1.5 and neither column
mysql --socket="$SOCK" -uroot -proot local -e "
  SELECT option_value FROM wp_options
   WHERE option_name='acrossai_mcp_servers_db_version';
  SHOW COLUMNS FROM wp_acrossai_mcp_servers
   WHERE Field IN ('server_type','tools_default_policy');"
```

Load any wp-admin page (the reconcile runs on `admin_init`), then:

```bash
mysql --socket="$SOCK" -uroot -proot local -e "
  SELECT id, server_slug, server_type, tools_default_policy
    FROM wp_acrossai_mcp_servers ORDER BY id;"
```

**Expect**: version `1.1.6`; every pre-existing row `mcp-adapter`; the `acrossai-mcp-server`
row `acrossai`; both policies `per-tool`.

### 1a. The regression that matters

```bash
mysql --socket="$SOCK" -uroot -proot local -e "
  UPDATE wp_acrossai_mcp_servers SET server_type='mcp-adapter'
   WHERE server_slug='acrossai-mcp-server';
  DELETE FROM wp_options
   WHERE option_name='acrossai_mcp_servers_db_version';"
```

Reload wp-admin. **Expect**: version back to `1.1.6` AND the row **still** `mcp-adapter`.
If it flipped back to `acrossai`, the corrective UPDATE is not gated on column creation and
will silently revert operators who used the switch-type escape hatch.

> **Do not rely on PHPUnit rollback for schema tests.** DDL implicitly COMMITs and escapes
> `WP_UnitTestCase`'s per-test transaction (B53); restore explicitly.

---

## 2. No fatal on a real page load

```bash
composer run phpcs && composer run phpstan
```

Then **load wp-admin in a browser** and check:

```bash
tail -20 wp-content/debug.log | grep -i fatal
```

This step is not optional. During pre-planning a `private column_exists()` override
white-screened the site while PHPCS and PHPStan both passed clean.

---

## 3. Reset restores the right tools *(the actual defect)*

1. Open a server's **Tools** tab.
2. Add and remove a few tools.
3. Press **Reset**.

**Expect**: an `mcp-adapter` server returns to the three protocol tools; an `acrossai`
server (with the sibling active) returns to its toolsets — **not** the three.

---

## 4. Requirement enforcement

Deactivate `acrossai-abilities-manager`, then:

| Check | Expect |
|---|---|
| Servers list → Enable on the AcrossAI server | refused, names the add-on |
| Bulk Enable, mixed selection | eligible enabled; skipped ones named with reasons |
| Quick Connect enable step | refused via `WP_Error`, surfaced in the wizard |
| **Disable** on any of the three | always succeeds |
| Create a new server | `AcrossAI` not offered in the type field |
| Overview tab | shows BOTH remedies — install, or switch type |
| Quick Connect step 4 | not skippable for an `acrossai`-type server |

---

## 5. Runtime diagnostic

With a server enabled FIRST, then the sibling deactivated:

```bash
curl -sI http://wordpress-7-0.local/wp-json/acrossai/mcp   # expect 401, NOT 404
```

Connect an MCP client and list tools. **Expect** exactly one entry naming the required
plugin; invoking it returns the same message. Reactivate the sibling → the entry disappears,
toolsets return, and any curated selection is byte-identical to before.

---

## 6. Bulk tool actions

1. Tools tab → **Add All**.
2. Activate a plugin that contributes a new tool-level ability.
3. Reload.

**Expect**: the new tool is included with no further action — the rule is standing, not a
snapshot. Switching type while `all` is in force resets the rule to `per-tool` (FR-012a).
