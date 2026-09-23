# Phase 0 Research: Tables That Repair Themselves

All findings below were verified against vendored source, repository history, or a live production
site. No NEEDS CLARIFICATION markers remain.

---

## R1 — Root cause is two defects, not one

**Decision**: Treat the phantom version stamp and the callback-less schema column as separate
defects with separate fixes, and say so in the decision record.

**Rationale**: `vendor/berlindb/core/src/Database/Kern/Table.php:1021` returns no pending upgrades
when the recorded version is empty, and `:982` then stamps the declared version and returns success
having run nothing. Separately, `create()` at `:601` is a raw `CREATE TABLE` invoked only from
`install()` when the table is absent, so nothing reconciles a declaration against a populated table.
The first strands sites that already existed; the second strands columns that were never migrated.
A fix for either alone leaves real sites broken.

**Alternatives considered**: Rewinding the recorded version so the existing callbacks re-run — fixes
only the first defect, cannot reach columns that have no callback at all (the four authentication-log
columns), and replays destructive callbacks unnecessarily. Rejected.

---

## R2 — The damage is a contiguous prefix, not the whole chain

**Decision**: Document the affected population by upgrade path rather than as "old installs".

**Rationale**: Migrations run contiguously in ascending order, so the stamp takes the value of
whatever release the site lands on and later migrations then run normally from that false baseline.
Declared version per release, read from every tag:

| Releases | Declared version | Migration map | A pre-BerlinDB site landing here |
|---|---|---|---|
| v0.0.6 – v0.1.2 | 1.0.0 → 1.1.0 | empty | stamped harmlessly; everything later runs. Safe. |
| v0.1.3 | 1.1.1 | 1 entry | loses 1.1.1 |
| v0.1.4 – v0.1.8 | 1.1.2 | 2 | loses 1.1.1–1.1.2 |
| v0.1.9 – v0.3.2 | 1.1.4 | 4 | loses 1.1.1–1.1.4 |
| v0.3.3 | 1.1.5 | 5 | loses 1.1.1–1.1.5 |
| v0.3.4 – v0.3.6 | 1.1.7 | 7 | loses everything, server type included |

The live evidence corroborates this exactly: the affected site has the 1.1.5 and 1.1.6 columns but
not the 1.1.1 and 1.1.2 ones, which places its stamp in [1.1.2, 1.1.4] and reconstructs its history.

**Consequence for design**: a site making this jump *today* lands on the last row and loses the
server-type column too — which is what makes R7 a live correctness requirement rather than a
hypothetical.

**Alternatives considered**: An initial reading attributed the stamp to a database import. Rejected
once the per-release version map showed a simpler explanation that also predicts the exact surviving
column set.

---

## R3 — Write no DDL by hand

**Decision**: Build each `ADD COLUMN` from the Column object's own `get_create_string()`.

**Rationale**: `Column::get_create_string()` (`Column.php:1692`) is public and emits precisely the
fragment that follows `ADD COLUMN`. `Schema::from_table()` (`Schema.php:63`) introspects a live table
and returns a Schema of the same Column type, so declared-versus-live is a comparison of two objects
of one type. Hand-written DDL can disagree with the declaration; DDL generated from the declaration
cannot. This mechanically reproduces all seven existing migration callbacks.

**Alternatives considered**: `dbDelta()` — it does add missing columns, but it also rewrites types and
is notoriously sensitive to statement formatting; BerlinDB deliberately does not use it anywhere.
Rejected. Hand-written `ALTER` strings per column — the status quo, and the source of the drift.
Rejected.

---

## R4 — Additive only

**Decision**: Never drop, never modify. Report both.

**Rationale**: A column present in the table but absent from the declaration cannot be distinguished
from one added by another plugin or by the operator, so dropping risks destroying data the plugin
does not own. Narrowing or retyping an existing column can truncate stored values. Adding a column
cannot lose anything. That asymmetry is the entire safety argument for relaxing the existing
three-part migration contract, so it must hold absolutely — which is why a gate asserts the
reconciler's source contains no destructive DDL keyword.

**Alternatives considered**: Also correcting width drift, which is real — the authentication-log
migration exists precisely because widths drifted. Deferred to a later increment and recorded as an
open question, because reporting is safe and correcting is not.

---

## R5 — Not every declared column is safely addable

**Decision**: A column is addable if and only if its create string is non-empty, contains no
`auto_increment`, and does not contain `not null` without also containing `default`. Everything else
is reported.

**Rationale**: Derived from the create string rather than a maintained list, so it stays correct as
columns are added. It excludes, without enumerating them: every primary key (auto-increment without
`PRIMARY KEY` is invalid DDL, errno 1075); the authentication-log hash column (`char(64)`, not null,
under a uniqueness constraint — the second existing row would violate it); and every creation
timestamp (not-null `datetime` whose synthesised default is the zero date, which fails where
`NO_ZERO_DATE` is enforced).

**Alternatives considered**: An explicit exclusion list. Rejected under Constitution VI — it is a
second place the schema is described, and it drifts.

---

## R6 — Recurrence control: fingerprint the declared column set

**Decision**: Skip the whole pass when a stored fingerprint matches. Fingerprint the declared
**column set** plus the plugin version — not the declared schema versions.

**Rationale**: A one-shot done-flag, as used by the existing row backfills, burns its single
opportunity on whichever page load happens to come first and then disables the guarantee forever;
drift can arrive in any future release. Running the full inspection on every administrative page load
is an unbounded tax. A fingerprint gives one full pass per release at a steady-state cost of one
non-autoloaded option read.

Fingerprinting the declared *versions* would have been wrong in a way that matters: the second defect
is precisely "a column added with no version change", so a version-only fingerprint would have
skipped the four orphaned authentication-log columns forever — reproducing the bug inside its own
fix.

The fingerprint is written last and only when no schema change failed, so a transient failure retries
on the next page load rather than being recorded as success. Deleting the option forces a full
re-check, which follows the documented recovery idiom of the existing cleanup routine.

**Alternatives considered**: Done-flag (above). Every-request inspection (above). A transient rather
than an option — rejected because expiry would make the guarantee depend on cache behaviour.

---

## R7 — Restoring columns is not sufficient, and order is load-bearing

**Decision**: After the reconciler creates columns, a value pass corrects the server type first, then
derives tool values from it — and only for columns created in that same pass.

**Rationale**: The three protocol-flag columns are declared with a default of enabled, and the
schema file says why: the original migration intentionally backfilled every pre-existing row with all
three enabled. That was correct when one server type existed. It is wrong for the managed type, so a
schema-only repair restores the columns and leaves the site still offering three tools its operator
never chose.

Worse, on a site stamped at the latest version the server-type column is itself missing. The
reconciler creates it, every row lands on the placeholder default, and the 1.1.6 migration's
corrective update is gated inside a callback that will never run again. A value pass that read the
type at that moment would resolve the placeholder to the legacy tool set and write all three flags
enabled — reproducing the exact symptom the feature exists to remove, and mistyping the server
permanently. Hence: correct the type, then read it.

**Alternatives considered**: Calling the existing `ToolPolicy::apply_type_defaults()`, which already
performs almost exactly this derivation. **Rejected** — its second half replaces the curated tool
rows, which would erase every operator selection on every affected server. Only its column half is
reused, via `split_payload()`, matching `DefaultServerSeeder::write_declared_tools()`.

---

## R8 — The licence to write values is narrow by construction

**Decision**: Write only to columns created in the same pass; never to a column that already existed.

**Rationale**: This is the doctrine already recorded on the seeded-tools repair — an operator is
entitled to their choices, and a repair that re-asserts a value fights them on every page load. A
column that did not physically exist cannot have carried a preference, so writing it once cannot
overwrite intent. Anything already stored is untouched.

A second guard is required: `ServerTypes::declared_tools()` falls back to the legacy type when a type
declares no tools of its own, and the legacy tool list *is* the three protocol tools. A third-party
type registered with an empty list would therefore have all three flags enabled. The pass must skip
any server whose type is unknown or declares nothing.

---

## R9 — Honest failure returns, but not everywhere

**Decision**: Migrations that drop or modify return failure when their statement fails. Migrations
that only add columns keep returning success, with corrected documentation.

**Rationale**: Every callback currently returns success unconditionally, while its own documentation
promises the opposite — so this closes a documented-versus-actual gap. But making all of them honest
would regress: the upgrade lock is released in a `finally`, so a permanently failing statement
retries on every administrative page load *and* blocks every later migration behind it. A site whose
database user cannot alter tables would freeze at an early version forever.

The split follows what the reconciler can heal. It is the retry for additions, so an addition need
not stall the chain. It deliberately never performs drops or modifications, so for those the
unstamped version is the only retry that exists.

**Alternatives considered**: A failure counter to bound retries — rejected, since an option write per
failed request costs more than the failed statement. Reporting through an extension point is
sufficient.

---

## R10 — Reach, and the limitation that comes with it

**Decision**: Run from the existing `admin_init` handler and from activation. Document the gap
rather than engineer around it.

**Rationale**: This matches all three existing self-healing routines. `admin_init` does not fire on
public requests, REST requests, cron, or the command line — so a site whose operator never opens the
admin area is never repaired, which is precisely the profile of an affected site. The plugin exposes
no command-line surface, so no manual escape hatch exists either. Stating this plainly is more honest
than implying universal coverage.

Two consequences must reach the release notes: the repair happens on the first administrative page
load after updating, and an already-connected client keeps showing its original tool list until it is
reconnected, because that list is fixed when the client connects and cannot be refreshed.

**Alternatives considered**: Running on every request via the boot path that already constructs the
tables — rejected, because it would issue schema changes during public and API requests.

---

## R11 — Implementation traps confirmed in vendored source

**Decision**: Encode each as an explicit guard, and assert the assumptions that cannot be guarded.

| Trap | Guard |
|---|---|
| Reading the declared version off the table object returns the *installed* version, because the magic accessor prefers the getter | Read declared values by reflection, as the existing phantom-version test already does |
| The magic accessor works for the schema object and table name only because no getter shadows them | Assert in a test that no such getter exists, so a library upgrade fails loudly rather than silently |
| Live introspection returns an empty schema when the table is absent | Guard on table existence *and* a non-empty live column list, or the pass would alter a table that is not there |
| MySQL 8.0.19+ no longer reports integer display width, so every `tinyint(1)` looks narrowed | Compare lengths only when both sides report one |
| The library's upgrade lock does not cover this pass | Take a short lock of its own, released in a `finally` |
| `ADD COLUMN IF NOT EXISTS` is MariaDB-only | Never use it; check existence first and treat a lost race as success |
| `$wpdb->query()` returns `true` for DDL, not a row count | Compare strictly against `false` |
| The test suite fails on stray output and warnings | Suppress database errors around the statement and restore afterwards |
| Committed static-analysis config is level 5 while CI enforces level 8 | Verify at level 8 explicitly before pushing |

---

## R12 — A one-shot repair already consumed its chance

**Decision**: Clear the server-guide repair's completion flag whenever the reconciler creates the
server-type column.

**Rationale**: That repair selects on the server-type column. On a table lacking it the query errors,
returns nothing, the loop does nothing — and the completion flag is written anyway. So on exactly the
sites this feature targets, it has already recorded success without doing its work. Restoring the
column is not enough; its flag has to be cleared so it can run.
