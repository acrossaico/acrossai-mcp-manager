# Phase 1 Contracts: Extension Points

This feature adds no REST route, no admin screen, and no shortcode. Its only external surface is two
WordPress action hooks, published so that unrepairable drift and failed schema changes are observable
without a database session.

Both follow Constitution V (extensibility without core modification) and the fail-open observability
precedent already set by the legacy cleanup routine, which reports what it declined to do rather than
passing over it silently.

---

## `acrossai_mcp_schema_drift_detected`

Fired at most once per reconciliation pass, and only when the pass found drift it will not repair.
Not fired when everything was healed, and not fired when the pass was skipped.

| Parameter | Type | Description |
|---|---|---|
| `$unhealed` | `array<string, string[]>` | Table name → declared columns that are missing but were not added, either because they failed the addability rule or because their statement failed. |
| `$divergent` | `array<string, array<string, array{declared: string, live: string}>>` | Table name → column name → the declared and live descriptions that differ. Width and type drift only; never acted on. |

**Contract guarantees**:
- Table names are un-prefixed stems, so a subscriber need not know the site's table prefix.
- Both arrays are non-empty when the action fires; a subscriber need not guard for the empty case.
- Purely informational. Nothing in the plugin subscribes to it; no return value is read.

**Intended use**: support diagnostics, and a future operator-facing surface should one be wanted
(recorded as an open question — the plugin has no health screen today).

---

## `acrossai_mcp_schema_upgrade_failed`

Fired by a migration callback whose statement failed, immediately before it returns failure. Only the
callbacks that drop or modify report this way; callbacks that only add columns do not, because the
reconciler is their retry (R9).

| Parameter | Type | Description |
|---|---|---|
| `$table` | `string` | Un-prefixed table stem. |
| `$version` | `string` | The schema version whose callback failed. |
| `$error` | `string` | The database error message, as reported by `$wpdb`. |

**Contract guarantees**:
- Fired before the callback returns, so a subscriber observes the failure in the same request.
- Because the callback returns failure, the recorded version does **not** advance and the migration
  retries on the next administrative page load. A subscriber will therefore see this fire repeatedly
  on a persistently failing site — that is intended, and is the signal that operator intervention is
  required.

**Security note**: `$error` may contain database identifiers. It is passed to subscribers only and is
never rendered by this plugin; database errors are suppressed around the statement precisely so
nothing reaches a page.

---

## Non-contracts

Deliberately **not** published, to avoid freezing internals as public API:

- The reconciliation fingerprint option name is an implementation detail, documented in the README as
  a recovery action rather than guaranteed as an interface.
- The created-column report is request-scoped and never exposed.
- No filter is offered to alter which columns are added. The declaration is the single authority; a
  filter here would reintroduce the possibility of the table disagreeing with the declaration, which
  is the whole class of bug being fixed.
