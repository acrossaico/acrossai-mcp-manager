# Phase 1 Data Model: Tables That Repair Themselves

This feature introduces no new table and changes no column definition. It reconciles the shape of
tables that already exist, and stores two small pieces of bookkeeping. The entities below are
therefore mostly *conceptual* — they describe the values the reconciliation reasons about.

---

## Declared schema

**What it represents**: what the code says a table should contain. The authority for what may be
added.

**Source**: each module's `Schema` class, reached through the table object's schema instance.

**Fields used**: per column — name, type, length, nullability, default, and any extra attribute such
as auto-increment. The complete DDL fragment is obtained from the column itself rather than
reassembled from these parts.

**Validation rules**:
- A declared column is a candidate for addition only if it passes the addability rule (R5).
- The declared set is read fresh each pass; it is never cached across requests beyond the fingerprint.

---

## Live schema

**What it represents**: what the table actually contains right now.

**Source**: library introspection of the live table, which returns the same column type as the
declaration, so the two are directly comparable.

**Validation rules**:
- An empty live set is ambiguous — it means either "no columns" (impossible) or "table absent". It
  MUST be treated as "do nothing", never as "everything is missing".
- Length is only comparable when both sides report one, because modern MySQL omits integer display
  width (R11).

---

## Drift

**What it represents**: the difference between declared and live, partitioned by what the feature is
willing to do about it.

**Partitions**:

| Partition | Definition | Action |
|---|---|---|
| Addable | Declared, not live, passes the addability rule | Added |
| Unaddable | Declared, not live, fails the addability rule | Reported only |
| Divergent | Present in both but type or width differs | Reported only |
| Extraneous | Live, not declared | Ignored entirely — ownership cannot be established |

**State transitions**: Addable → added → (on the next pass) no longer drift. Unaddable and Divergent
persist across passes by design and are reported each time the pass actually runs.

---

## Created-column report

**What it represents**: the columns a single pass actually added, keyed by table.

**Why it is an entity rather than an implementation detail**: it is the *only* licence the value pass
has to write anything. A column in this report provably did not exist moments ago, so it cannot have
carried an operator preference. A column absent from it must never be written (R8). The report's
lifetime is a single request; it is never persisted.

**Validation rules**:
- Empty report ⇒ the value pass makes no write at all.
- The report reflects columns that were *successfully* added, not attempted.

---

## Reconciliation fingerprint

**What it represents**: a record of the declared column set most recently reconciled, so an unchanged
declaration costs a single read.

**Storage**: one non-autoloaded option.

**Composition**: the plugin version, plus per table the table name, the declared schema version, and
the sorted declared column definitions. Including the plugin version guarantees one full pass per
release. Including the column definitions catches a column added without a version change — which is
the second defect, and the reason a version-only fingerprint would be self-defeating (R6).

**Validation rules**:
- Written **last**, and **only** when no schema change failed during the pass.
- Deleting it forces a full re-check. This is the documented recovery action.

---

## Reconciliation lock

**What it represents**: mutual exclusion for the duration of one pass, so two simultaneous
administrative requests cannot attempt the same change.

**Storage**: one short-lived transient, released in a `finally`.

**Validation rules**:
- A held lock means "skip this pass", never "wait".
- The library's own upgrade lock is a different lock and does not protect this pass (R11).

---

## Relationships

```text
Declared schema ──┐
                  ├──► Drift ──► Addable ──► [ALTER] ──► Created-column report
Live schema ──────┘              │                              │
                                 │                              ▼
                                 └──► Unaddable / Divergent     Value pass
                                          │                     (server type first,
                                          ▼                      then tool flags)
                                   Reported via extension point
```

The value pass consumes only the created-column report. It never consults the drift partitions
directly, which is what keeps the generic reconciler free of any knowledge about servers, types or
tools.

---

## Existing entities this feature reads (unchanged)

- **Server row** — its type determines which tool values are correct. The type column may itself be
  one of the columns just created, which is why it is corrected before it is read (R7).
- **Server type** — supplies the declared tool list. A type that declares nothing falls back to the
  legacy list, which is why an unknown or empty type must be skipped rather than trusted (R8).
- **Curated tool rows** — read-only for this feature. They are never replaced, which is the reason
  the existing type-defaults helper is not reused (R7).
