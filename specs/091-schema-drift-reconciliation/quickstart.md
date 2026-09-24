# Quickstart: Reproduce the Drift, Verify the Repair

How to see the bug on a local site, and how to confirm the fix removes it. Every step is reversible.

> **Back up first.** Every command here writes to your database.

---

## 1. Reproduce the exact production state

The affected site had the server table missing the columns added by schema versions 1.1.1 and 1.1.2,
while the recorded version claimed the current version. Recreate that:

```sql
ALTER TABLE wp_acrossai_mcp_servers
  DROP COLUMN tool_discover_abilities,
  DROP COLUMN tool_get_ability_info,
  DROP COLUMN tool_execute_ability,
  DROP COLUMN override_abilities_permission;
```

```sql
UPDATE wp_options
   SET option_value = '1.1.7'
 WHERE option_name = 'acrossai_mcp_servers_db_version';
```

Also clear the reconciliation bookkeeping so the pass is not skipped:

```sql
DELETE FROM wp_options WHERE option_name = 'acrossai_mcp_schema_fingerprint';
```

**Confirm the bug is present** before fixing anything:

- The managed server now advertises three tools its operator never selected. Reconnect an MCP client
  and count them — the managed server should offer 14, and will offer 17.
- Open the server's Tools tab, uncheck those three, save. It will report success. Reload: they are
  back. That is the write silently failing against columns that do not exist.

### For the harder case — the one a site updating today would hit

Drop the type column as well, which is what a site stamped at the latest version is missing:

```sql
ALTER TABLE wp_acrossai_mcp_servers DROP COLUMN server_type;
```

This is the regression case worth testing by hand, because a repair that restores columns without
correcting the type first will re-enable all three tools and mistype the managed server permanently.

---

## 2. Verify the repair

Load **any** wp-admin page. Nothing else. No reactivation, no settings visit, no prompt.

Then check:

```sql
SHOW COLUMNS FROM wp_acrossai_mcp_servers;
```

Expected — all dropped columns are back with their declared definitions, and:

```sql
SELECT server_slug, server_type,
       tool_discover_abilities, tool_get_ability_info, tool_execute_ability
  FROM wp_acrossai_mcp_servers;
```

| Server | Expected type | Expected flags |
|---|---|---|
| the managed server | `acrossai` | `0, 0, 0` |
| the default server | `mcp-adapter` | `1, 1, 1` |

The managed server reading `mcp-adapter` with `1, 1, 1` is the regression this feature exists to
prevent — see research R7.

**Confirm the operator's choices survived**: any tool rows curated before the repair must still be
present. The repair writes column values only; it must never touch curated rows.

**Then reconnect the MCP client.** The corrected list appears only after reconnecting — a connected
client's tool list is fixed when it connects and cannot be refreshed. Before reconnecting it will
still show 17, and that is expected, not a failed repair.

---

## 3. Verify it does not fight the operator

With the columns present, set them deliberately:

```sql
UPDATE wp_acrossai_mcp_servers
   SET tool_discover_abilities = 1, tool_get_ability_info = 1, tool_execute_ability = 1
 WHERE server_type = 'acrossai';
```

```sql
DELETE FROM wp_options WHERE option_name = 'acrossai_mcp_schema_fingerprint';
```

Load an admin page. The values MUST be unchanged. The repair only writes columns it created in that
same pass; these already existed, so they are not its business.

---

## 4. Verify the steady state is cheap

Load several admin pages in a row after a successful repair. Only the first should inspect the
schema; the rest should short-circuit on the fingerprint. Confirm with a query log or by adding a
temporary subscriber to the drift action.

---

## 5. Run the suite

```bash
# one-time scaffolding, if not already installed
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest true

# the suites this feature touches
vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite database

# full gates
composer phpcs
vendor/bin/phpstan analyse --level=8 --memory-limit=4G   # committed config says 5; CI enforces 8
bash bin/verify-f021-gates.sh
```

---

## 6. Restore your site

If you reproduced the drift on a site you care about, the repair itself is the restore — the columns
come back. To also clear the bookkeeping:

```sql
DELETE FROM wp_options WHERE option_name = 'acrossai_mcp_schema_fingerprint';
```

The stale connector columns and the orphaned pre-BerlinDB version options are left alone by design;
they are inert.

---

## Acceptance, end to end

The real acceptance test is not local. After release, the affected production site must update,
have an operator open wp-admin once, and return to offering exactly the selected tools — with no
manual SQL at any point. That site is deliberately being left unrepaired so it can serve as that
unaided verification.
