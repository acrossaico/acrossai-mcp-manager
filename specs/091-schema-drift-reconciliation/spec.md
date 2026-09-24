# Feature Specification: Tables That Repair Themselves

**Feature Branch**: `091-schema-drift-reconciliation`
**Created**: 2026-09-24
**Status**: Draft
**Input**: User description: "Tables that repair themselves — BerlinDB schema drift reconciliation. A site can end up with database tables missing columns the code declares, permanently and silently, causing the MCP connector to advertise tools the operator never chose and cannot remove."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A drifted site heals itself (Priority: P1)

A site administrator updates the plugin. Their site's server table has been missing columns for months — a state they never caused and cannot see. On the next visit to any admin page, the missing columns are restored and the site begins behaving as its settings always said it should. The administrator does nothing, is asked nothing, and sees no error.

**Why this priority**: This is the entire feature. Every other story is a safeguard around it. Without this, affected sites stay broken forever, because no version bump can ever reach them.

**Independent Test**: On a site with a table missing declared columns and a version stamp claiming it is current, load any wp-admin page and confirm the columns exist afterwards, with no manual database work and no operator prompt.

**Acceptance Scenarios**:

1. **Given** a server table missing the three protocol-flag columns and the permission-override column, and a version stamp reading the current version, **When** an administrator loads any admin page, **Then** all four columns exist and carry their declared definitions.
2. **Given** an authentication-log table missing four columns for which no migration has ever existed, **When** an administrator loads any admin page, **Then** those columns exist.
3. **Given** a site whose tables already match their declarations, **When** an administrator loads any admin page, **Then** no schema change is made and no measurable work is repeated on subsequent page loads.
4. **Given** a repaired site, **When** the administrator reconnects the MCP client, **Then** the client is offered only the tools the operator actually selected.

---

### User Story 2 - The repair respects what the operator chose (Priority: P1)

An administrator who has deliberately curated which tools their server offers finds that choice intact after the repair. The repair corrects values only where the operator could not possibly have expressed a preference — because the setting did not physically exist until the repair created it.

**Why this priority**: Equal to P1 because a repair that overwrites operator intent is worse than the bug. Restoring the columns alone is not enough: a restored column carries a default that is correct for one server type and wrong for another, so the repair must also decide values — and that is precisely where it could do harm.

**Independent Test**: With the columns already present and a server's flags deliberately set, run the repair and confirm the stored values are unchanged.

**Acceptance Scenarios**:

1. **Given** a server whose tool settings are already stored, **When** the repair runs, **Then** those values are not modified.
2. **Given** a server whose type was also missing and is restored by the repair, **When** the repair sets its tool values, **Then** it reads the corrected type, not the placeholder default — so a managed server is never mistyped and never silently re-enabled.
3. **Given** a server whose type declares no tools of its own, **When** the repair runs, **Then** it makes no value change to that server rather than guessing.

---

### User Story 3 - A missing column can no longer ship (Priority: P2)

A developer adds a column to a table declaration. Either it reaches every existing site automatically, or the build fails and tells them exactly what is required. There is no third outcome in which the change silently reaches only new installations.

**Why this priority**: Prevention rather than repair. Shipping it in the same release is what stops this recurring — the four authentication-log columns show the failure mode is already live and repeating.

**Independent Test**: Add a column to a declaration with no migration, run the test suite, and confirm the change is either healed automatically or blocked with an actionable message.

**Acceptance Scenarios**:

1. **Given** a newly declared column that can be added safely, **When** the suite runs, **Then** it passes and the column reaches existing sites.
2. **Given** a declared column that is removed, narrowed, or retyped, **When** the suite runs, **Then** it fails naming the change and the migration required.
3. **Given** any table, **When** its declaration is compared against a freshly installed instance, **Then** the two agree.

---

### User Story 4 - What cannot be repaired is visible (Priority: P3)

Drift the repair deliberately will not touch — a narrowed column, a changed type, a column unsafe to add to a populated table — is reported rather than passed over in silence, so support can see it without a database session.

**Why this priority**: Valuable but not blocking. Silence is the existing house convention for self-healing, and the repair is correct without this; it only shortens diagnosis.

**Independent Test**: Introduce a width difference, run the repair, and confirm it is reported and not altered.

**Acceptance Scenarios**:

1. **Given** a column narrower than declared, **When** the repair runs, **Then** it is reported and the column is left unchanged.
2. **Given** a schema change that fails to apply, **When** the repair runs, **Then** the failure is reported and the repair retries on the next admin page load rather than recording success.

---

### Edge Cases

- **The table does not exist at all.** The repair must make no change, rather than treating every declared column as missing and attempting to alter a table that is not there.
- **A declared column cannot be safely added to a table that already has rows** — a required column with no default, an auto-numbering key, or a column under a uniqueness constraint. These must be reported, never forced.
- **Two administrators load admin pages simultaneously.** The repair must not attempt the same change twice or fail the request.
- **The database user cannot alter tables.** The repair must not record success, must not block unrelated migrations behind the failure, and must report it.
- **A server type declares no tools.** The repair must leave that server's values alone rather than falling back to a default that would re-enable tools.
- **An MCP client is already connected.** Its tool list was fixed when it connected and cannot be refreshed, so the corrected list appears only after reconnecting. This must be stated in the upgrade notes.
- **The operator never opens wp-admin.** The repair does not run. This is an accepted limitation of this increment and must be documented rather than implied.
- **A subsite on a multisite network that nobody visits.** Same limitation, multiplied per subsite.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST compare every declared table column against the live table and add any that are missing, without operator action.
- **FR-002**: The system MUST NOT remove, narrow, retype, or otherwise modify an existing column. A column present in the table but absent from the declaration MUST be left untouched, because it cannot be distinguished from one added by another plugin or by the operator.
- **FR-003**: The system MUST NOT attempt to add a column that cannot be safely added to a populated table — specifically one requiring a value with no default available, an auto-numbering key, or a primary key. These MUST be reported instead.
- **FR-004**: The system MUST make no change when the table itself is absent.
- **FR-005**: The system MUST run on the first administrative page load after an update, without requiring reactivation.
- **FR-006**: The system MUST run before any routine that writes to these tables during activation, so that no write silently discards data destined for a column that does not yet exist.
- **FR-007**: After adding columns, the system MUST set values for those columns only, and only on the tables and rows where the operator cannot have expressed a preference — that is, only for columns created in that same pass.
- **FR-008**: When a server's type is itself restored by the repair, the system MUST correct the type before deriving any value that depends on it.
- **FR-009**: The system MUST make no value change for a server whose type is unknown or declares no tools.
- **FR-010**: The system MUST NOT overwrite tool selections that were already stored.
- **FR-011**: Repeat runs MUST make no further changes once a table matches its declaration, and the steady-state check MUST be cheap enough to run on every administrative page load.
- **FR-012**: The recurrence check MUST be sensitive to a change in the declared set of columns, not only to a declared version number — a column added without a version change is the exact failure being fixed.
- **FR-013**: The system MUST NOT record a successful pass when any schema change failed, so that the next page load retries.
- **FR-014**: Schema changes that this feature deliberately does not perform MUST continue to require an explicit migration, and those migrations MUST report failure rather than recording success.
- **FR-015**: Migrations that only add columns MUST NOT block later migrations behind a failure they cannot resolve, because the reconciliation described here is their retry.
- **FR-016**: Drift the system will not repair, and failures it encounters, MUST be observable through an extension point rather than passed over silently.
- **FR-017**: The build MUST fail when a declared column is removed, narrowed, or retyped without an accompanying migration.
- **FR-018**: The build MUST verify that a freshly installed table matches its declaration exactly.
- **FR-019**: Concurrent administrative requests MUST NOT apply the same schema change twice.
- **FR-020**: A one-shot repair whose single opportunity was consumed while the schema was broken MUST be allowed to run again once the schema is corrected.

### WordPress Requirements

**PHP Version**: PHP 8.1+
**WordPress Version**: 7.0+
**Multisite**: Single-site only — tables are per-site, and reaching every subsite is out of scope for this increment (see Assumptions)
**Required Plugins / Packages**: `berlindb/core` ^3.0 (already a dependency)
**Optional Integrations**: `acrossai-abilities-manager` — the repair must behave correctly whether or not it is active, because the tool vocabulary it contributes may be absent when the repair runs

### Module Placement

**PHP Class(es)**:
- `includes/Database/SchemaReconciler.php` → namespace `AcrossAI_MCP_Manager\Includes\Database` — context-neutral, sibling to the existing `LegacyOAuthCleanup` repair
- `includes/Database/MCPServer/CreatedColumnBackfill.php` → namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServer` — the value pass for just-created columns

Both are stateless static-only services under the A11 pure-service exemption, matching the existing repair classes. Neither renders UI or handles a request.

**Hook Registration**: The reconciliation is invoked from the existing `Main::reconcile_database_schemas()` handler already wired on `admin_init`, and from the activation routine. No new hook registration is introduced.

### Database / Storage

**Custom DB tables** (existing, not introduced here): all five plugin tables are in scope —
`{wpdb->prefix}acrossai_mcp_servers`, `…_server_tools`, `…_server_abilities`, `…_servers_meta`, `…_cli_auth_logs`.

**WordPress options API**:
- `acrossai_mcp_schema_fingerprint` — records the declared column set most recently reconciled, so an unchanged declaration costs a single non-autoloaded read. Deleting it forces a full re-check, which is the documented recovery recipe.
- A short-lived lock guarding concurrent reconciliation.

**Justification**: no new table is created. This feature only reconciles the shape of tables that already exist.

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [ ] All DB queries use `$wpdb->prepare()` — with the schema-change statements documented as the sanctioned exception, since identifiers cannot be prepared and both the table name and the column definition are plugin-owned values, never user input
- [ ] No user input is accepted by this feature at any point — it takes no parameters from any request
- [ ] No output is rendered, so no escaping surface exists
- [ ] Database errors are suppressed around schema changes so that no database detail can reach a rendered page
- [ ] No capability check is required because the feature exposes no action a user can invoke; it runs as part of an already-authenticated administrative page load

*Not applicable*: nonce verification (no form or AJAX handler), REST permission callbacks (no routes added), token storage, file uploads.

### Key Entities *(include if feature involves data)*

- **Declared schema**: what the code says a table should contain — the authority for what may be added.
- **Live schema**: what the table actually contains.
- **Drift**: the difference between the two, split into what can be safely added and what cannot.
- **Created-column report**: the list of columns a pass actually added, which is the only licence for the value pass to write anything.
- **Reconciliation fingerprint**: a record of the declared column set last reconciled, used to skip repeat work.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors — note the committed configuration is level 5 while CI enforces 8, so verify at 8 explicitly
- [ ] PHPUnit tests written and passing for all new PHP logic
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — none in class constructors
- [ ] No code duplication — shared logic extracted rather than repeated
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_`
- [ ] `npm run validate-packages` passes
- [ ] Governance gate script passes, including the new checks this feature adds

*Not applicable*: ESLint (no JavaScript), DataForm/DataViews (no admin UI).

### Measurable Outcomes

- **SC-001**: A site carrying the known drift returns to offering exactly the tools its operator selected after a single administrative page load, with no manual database work — verified end to end on a real affected site after release.
- **SC-002**: All five tables match their declarations on a freshly installed site, verified automatically on every build.
- **SC-003**: A table missing any safely-addable column is restored to matching its declaration by one pass, for every table, verified automatically.
- **SC-004**: A second pass over an already-correct table makes zero changes.
- **SC-005**: An operator's stored tool selections are unchanged by the repair in 100% of cases where the relevant setting already existed.
- **SC-006**: Removing, narrowing, or retyping a declared column without a migration fails the build.
- **SC-007**: A site whose schema is already correct incurs no repeated schema inspection on subsequent administrative page loads.
- **SC-008**: A failed schema change never results in the pass being recorded as complete.

---

## Assumptions

- The repair runs on administrative page loads and at activation. It does not run on public requests, API requests, scheduled tasks, or command-line invocations — so a site whose operator never opens the admin area is not repaired. This matches every existing self-healing routine in the plugin and is accepted for this increment; it must be stated in the release notes rather than implied.
- Multisite networks are reached one site at a time, as each site's admin area is visited. Network-wide repair is out of scope, consistent with the plugin's existing behaviour.
- Columns present in a table but absent from its declaration are left in place indefinitely. They are inert, and removing something the plugin did not create is a different and riskier feature.
- Orphaned version-tracking options left by the pre-BerlinDB installer are not removed; they are already covered by the existing uninstall sweep.
- An MCP client that is already connected continues to show its original tool list until it is reconnected, because that list is fixed at connection time and cannot be refreshed.
- Width and type differences are reported but not corrected in this increment.
- The reconciliation is expected to run after routine migrations, so it only handles what those could not.
