# Feature Specification: MCP Activity Log

**Feature Branch**: `096-mcp-activity-log`
**Created**: 2026-10-16
**Status**: Draft
**Input**: User description: "Add MCP logging to acrossai-mcp-manager in ONE table: an activity log holding both MCP JSON-RPC requests and ability executions as a parent/child tree, plus a React DataViews admin screen for it, modelled on the WordPress AI plugin's Tools > AI Request Logs page." (full input recorded in `docs/planings-tasks/096-mcp-activity-log.md`)

## Clarifications

### Session 2026-10-16

- Q: How should the log handle the personal data it stores (source address + user id)? → A: Store the full source address and register WordPress core's personal-data exporter and eraser
- Q: How should the log bound its worst-case size, beyond the 30-day retention? → A: Retention plus a configurable maximum retained-event count, trimmed oldest-first; no sampling
- Q: What overhead may recording add to a request, so SC-005 becomes testable? → A: At most 5% of the call's own duration, measured against real traffic in Phase 0
- Q: What should the child events be called in the UI and in translatable strings? → A: "Ability" — matching WP_Ability, the wp_ability_* hooks and the sibling abilities-manager plugin
- Q: How should the log handle time zones across storage, filtering and display? → A: Store UTC (as CliAuthLog already does); convert to site time for display, filter input, the period picker and the retention cutoff

### Session 2026-10-22

Opened after Phase 2 benchmarked decision D-1 (one table vs two) empirically rather than by
argument. The measurements are in `research.md` D-14..D-17; the questions below are the
ambiguities they exposed in this spec.

- Q: Is the retained-event ceiling ONE global bound across all event types, or a separate cap per event type? → A: One global bound across all event types; the newest N survive regardless of kind, and the settings copy is corrected so "the maximum" cannot be read as per-half
- Q: Should the spec require that a schema change verifiably reaches an already-installed table, given BerlinDB silently skips migrations when the version option is absent? → A: Yes — a new functional requirement plus a success criterion covering both the prior-version upgrade and the version-absent case
- Q: Should the spec carry a measurable performance target for filtered list queries, beyond SC-005 (recording overhead) and SC-016 (deep paging)? → A: Yes, as an index-usage criterion asserted from the query plan — every offered filter resolves through a key keyed for it — rather than a millisecond figure, which would not have caught the defect
- Q: The ceiling ships defaulting to 0 (no limit). What scale should the spec record as the design point, and should that default change? → A: Record the benchmarked design point (~10,000 events, sanity-checked at 150,000) and keep the ceiling defaulting to no limit — retention bounds the ordinary case, and a shipped non-zero ceiling would delete history the operator never chose to lose **(SUPERSEDED 2026-10-23: the premise was logging being off by default; see that session)**

### Session 2026-10-23

Recording now starts before anyone chooses, which changes what every bound is for. Safe to
introduce as a default rather than an opt-in ONLY because 096 has never shipped: no existing
install's operator has declined this and would be switched on by an update. That reasoning
expires at release.

- Q: Which toggles default to on — both, or only MCP request logging? → A: Both, with ability runs capped harder than requests
- Q: How many days should ability runs be kept, given requests stay at 30? → A: 7 — ability rows were ~68% of benchmarked volume and carry the recorded arguments, so the noisier half ages out four times faster
- Q: Now that logging defaults to on, should the retained-event ceiling still default to 0 (no limit)? → A: No — ship 50,000, revising the 2026-10-22 answer. A plugin that records without being asked should bound without being asked; retention bounds growth in time, the ceiling bounds it independently of traffic
- Q: Should the spec require that a BROKEN log is distinguishable from a quiet one, and if so where is that said? → A: Yes — the Activity Log screen must report that recording is failing instead of showing an empty list, and a Site Health check must report it too
- Q: The adoption fix caps remembered children at 500 per request. What should happen to ability rows beyond that cap? → A: They stay unlinked (`parent_id` 0), recorded in the spec as an accepted limit — losing a link is recoverable, exhausting memory inside the recorder is not
- Q: Every storage bound depends on the daily cron firing. What should happen on a site where WP-Cron never runs? → A: Detect and report it — Site Health reports an overdue sweep, and the spec states that the bounds are conditional on cron; no private fallback scheduler

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See what AI clients asked my servers to do (Priority: P1)

A site administrator has connected Claude, ChatGPT or an npm bridge to one of their MCP
servers. Something is wrong — a connector that worked yesterday returns errors, or a
client reports "the tool isn't there". Today the administrator has nothing to look at:
they can see *that* a client connected, but not what it asked for, whether it worked,
how long it took, or which of their servers answered.

This story gives them a list. One row per request a client made, newest first, each
naming the server that answered, how the client got in (an AI connector, an application
password, the browser), which method and tool was called, whether it succeeded, and how
long it took. They can filter by server, by connection type, by status and by date, and
search by tool name.

**Why this priority**: This is the whole point of the feature and it stands alone. With
only this story shipped, a broken connector becomes diagnosable for the first time —
which is the single most common support question this plugin generates.

**Independent Test**: Enable request logging in Settings, connect a client, invoke one
tool, then open the Activity screen and confirm the call appears with the correct server,
connection type, tool name, status and duration.

**Acceptance Scenarios**:

1. **Given** request logging is enabled and an AI connector is connected, **When** the
   client invokes a tool, **Then** exactly one request row appears naming that server,
   with connection type "AI connector", the connecting client's name, a success status
   and a non-empty duration.
2. **Given** a client connected through the npm bridge with an application password,
   **When** it lists tools, **Then** the row records connection type "application
   password".
3. **Given** a tool call fails, **When** the administrator opens the row, **Then** the
   status reads error and an error reason is shown.
4. **Given** rows exist for several servers, **When** the administrator filters by one
   server, **Then** only that server's rows are listed.
5. **Given** request logging is disabled, **When** a client makes calls, **Then** no rows
   are recorded and the site's MCP responses are unaffected.

---

### User Story 2 - See what actually ran behind a tool call (Priority: P2)

The administrator finds the failing call from Story 1, but the row only names the
dispatcher the client addressed — for example a content toolset — not the specific
ability that ran behind it. That is the layer where the failure actually happened.

This story adds that layer as children of the request row. Expanding a request reveals
the abilities that ran behind it, each with its own input, result, status and duration.
The same list also captures abilities run with no AI client involved at all — over the
site's REST API, through WP-CLI, or from the admin screens — which appear without a
parent and record how they were invoked.

**Why this priority**: It converts a vague "the content tool failed" into "this specific
ability failed with this input". It depends on Story 1 for the parent rows, so it
cannot ship first, but it is what makes the log genuinely diagnostic.

**Independent Test**: Enable both logging toggles, invoke one toolset tool from a
connected client, expand that row on the Activity screen and confirm the abilities that
ran behind it are listed with their inputs and results. Then run the same ability over
the REST API and confirm it appears with no parent and an invocation source of "REST".

**Acceptance Scenarios**:

1. **Given** both toggles are enabled, **When** a client invokes a toolset dispatcher,
   **Then** one request row is recorded and at least one ability row is recorded beneath
   it, linked to that request.
2. **Given** such a pair exists, **When** the administrator expands the request row,
   **Then** the abilities are shown without navigating away or re-querying a second
   screen.
3. **Given** an ability is run over the site's REST API, **When** it completes, **Then**
   a row is recorded with no parent and an invocation source naming REST.
4. **Given** an ability fails, **When** the row is recorded, **Then** its status reads
   error — a failure is never recorded as a success or silently omitted.
5. **Given** an ability is refused for lack of permission on a WordPress version that
   reports refusals, **When** the row is recorded, **Then** its status reads denied.
6. **Given** ability logging is disabled but request logging is on, **When** a client
   invokes a tool, **Then** the request row is recorded and no ability rows are.

---

### User Story 3 - Find which tool is eating the client's context (Priority: P3)

The administrator's AI client keeps running out of room mid-conversation, or responses
degrade after connecting. The cause is usually one fat tool result, or a tool catalogue
so large that merely announcing it consumes a sizeable share of the client's context on
every new conversation. Nothing in WordPress tells them which.

This story records how much the server handed back on each call and presents it as a size
with an approximate context cost beside it. The list can be sorted by that size, the
period header totals it, and the per-call detail shows it. Every such figure is labelled
as an approximation.

**Why this priority**: It answers a question that has no other answer today and is cheap
to add once Story 1 exists. It is not required for the log to be useful, so it ranks
below the two diagnostic stories.

**Independent Test**: With logging enabled, invoke a tool returning a large result and
connect a fresh client. Confirm both the tool call and the catalogue announcement record
a response size, that sorting by size ranks them, and that every token figure is shown as
approximate.

**Acceptance Scenarios**:

1. **Given** logging is enabled, **When** a client invokes a tool, **Then** the row
   records the size of both what was sent and what was returned.
2. **Given** a client connects and the server announces its tool catalogue, **Then** that
   announcement records a response size — even though no ability ran behind it.
3. **Given** rows with differing response sizes, **When** the administrator sorts by
   size, **Then** the largest responses are ranked first.
4. **Given** any context figure is displayed, **Then** it is presented as an approximation
   and never as an exact or billable token count.
5. **Given** no response size could be determined for a row, **Then** the display shows an
   absence rather than a fabricated number.

---

### User Story 4 - Run the log without it becoming a liability (Priority: P3)

Logging every call has a real cost: database growth, a write on every request, and tool
arguments that may contain personal or secret data. An administrator who cannot control
that will not switch it on.

This story makes the log safe to own. It is off when the plugin is installed. The two
halves can be enabled independently, because the ability log is far noisier than the
request log. Each half ages out on its own schedule so the noisy half cannot force its
retention onto the quiet one. Everything can be purged on demand. Sensitive values are
redacted before storage and full payload bodies are never stored.

**Why this priority**: Without it the feature is a hazard rather than a tool. It is
ranked P3 only because Stories 1 and 2 must exist before there is anything to govern.

**Independent Test**: Confirm both toggles are off on a fresh install and no rows accrue.
Enable one, confirm only that half records. Set a retention period, age a row past it, run
the scheduled cleanup and confirm it is removed while the other half's rows survive.

**Acceptance Scenarios**:

1. **Given** a fresh install, **When** clients make calls, **Then** no rows are recorded
   until an administrator opts in.
2. **Given** only one half is enabled, **When** calls occur, **Then** only that half's
   rows are recorded.
3. **Given** different retention periods for each half, **When** the scheduled cleanup
   runs, **Then** each half is pruned on its own cutoff.
4. **Given** both retention periods are set to keep rows indefinitely, **Then** no cleanup
   is scheduled.
5. **Given** a call whose arguments contain a credential-like value, **When** the row is
   recorded, **Then** that value is redacted.
6. **Given** the administrator purges the log, **When** the purge completes, **Then** the
   rows are gone and the count shown reflects what was removed.
7. **Given** the plugin is uninstalled with data removal opted in, **Then** the log's
   storage is removed.

---

### Edge Cases

- **The MCP adapter is absent or a different version.** The log depends on data the
  adapter reports. A renamed or missing field must leave one column empty, never fail a
  request or a page load.
- **The administrator lacks `manage_options`.** Every read and every purge must be
  refused, and the screen must not be reachable.
- **A recording failure.** The recorders run inside the live path of real client traffic
  and of every ability on the site. A failure to write a row must never surface to the
  AI client, break a tool call, or abort an ability.
- **The WordPress version does not report ability failures.** On versions that only
  report successes for non-MCP callers, client-originated calls are still fully recorded;
  the shortfall applies only to REST, WP-CLI and admin callers and must be stated on the
  screen rather than implied away.
- **The site's time zone changes, or daylight saving shifts.** Stored times are unaffected
  because they are UTC; only presentation moves. Ordering across the change must stay correct.
- **A server is deleted after its calls were logged.** Historical rows must remain
  readable and still name the server that answered.
- **One call recorded twice.** Where two capture paths observe the same ability, exactly
  one row must result.
- **An ability that throws.** The attribution context must be released, so later work in
  the same request is not misattributed to an ability that already ended.
- **A request dies before its normal cleanup, on a long-lived process.** Under a
  persistent runtime the holders would otherwise carry one request's identity into the
  next, attributing a client's calls to a different client. The shutdown stage exists for
  this case and is the one most easily left out.
- **A third-party MCP logging plugin is also active.** Both may record; the screen should
  say so rather than leave the duplication unexplained.
- **A very large result.** Stored previews must be truncated, and sizes recorded, without
  the stored row growing with the payload.
- **The ceiling is reached.** The oldest events are shed first; the screen must not imply
  it is showing a complete history further back than it holds. Because the ceiling is global
  (FR-035), the eviction crosses event types: an operator watching only the request view can
  see its history recede while they are generating ability traffic. The screen must not
  present that as data loss, and the settings copy must make the shared bound plain before
  it happens.
- **No rows yet.** An empty screen must distinguish "logging is switched off" from
  "nothing has happened in this period" — and from "recording is failing" (FR-013a), which
  looks identical to both and means the opposite of the second.

---

## Requirements *(mandatory)*

### Functional Requirements

**Recording — requests**

- **FR-001**: System MUST record one row per MCP JSON-RPC request a managed server
  answers, capturing the method, the component invoked, the outcome, the duration, the
  answering server, the acting user and the source address.
- **FR-002**: System MUST record how the client authenticated, as one of: AI connector,
  bare OAuth client, application password, browser session, or unknown.
- **FR-003**: System MUST record the connecting client application's name and version
  where the client reported them.
- **FR-004**: System MUST record the session and request identifiers so one conversation's
  calls can be grouped.
- **FR-005**: System MUST record the size of the request payload and of the response
  payload, including for calls that have no child abilities.
- **FR-006**: System MUST NOT store raw request or response bodies; only redacted,
  truncated previews.

**Recording — abilities**

- **FR-007**: System MUST record one row per ability execution, capturing its name,
  category, input, result, outcome, duration and how it was invoked.
- **FR-008**: System MUST link an ability executed on behalf of an MCP request to that
  request's row, and MUST record abilities invoked outside MCP with no such link.
- **FR-009**: System MUST record failed, refused and invalid-input ability runs as such, and
  MUST NOT record a failure as a success or omit it.
- **FR-010**: System MUST produce exactly one row when more than one capture path observes
  the same ability run.
- **FR-011**: System MUST release the attribution context even when an ability throws.
- **FR-012**: System MUST expose the attribution context as a readable, independently
  armable seam, so Feature 097 can attach to it without re-instrumenting the same code.
  Its lifecycle obligations are FR-016 and FR-017.
- **FR-012a**: Ability events MUST be linked to the MCP request that ran them, and the link
  MUST be made AFTER the fact. An ability event is written while the request is in flight;
  the request's own row is written only at the end, once its duration is known — so at the
  moment a child is stored its parent does not exist and has no id. Setting the parent at
  insert time is therefore impossible, not merely unimplemented. The context collects the
  ids it wrote and the request event adopts them when it lands.
  `invoked_via` MUST be corrected in the same pass: it is derived from the parent id, which
  is 0 at write time, so an MCP-originated ability would otherwise be labelled `rest`.
  Adoption MUST consume the collected ids, so a second request event in the same cycle
  cannot re-adopt them, and the request lifecycle (FR-017) MUST discard any left unadopted —
  otherwise the next request inherits the previous one's abilities.
- **FR-012b**: The number of children remembered for adoption MUST be bounded, at 500 per
  request. Beyond it, further ability events are recorded normally but stay unlinked
  (`parent_id` 0). An **accepted limit, stated rather than discovered**: this collection sits
  inside a logging path, which is the one place that must never be able to exhaust memory,
  and a request running more than 500 abilities is already far outside anything observed —
  a real tool call runs single digits. Losing a link is recoverable; running out of memory
  while recording is not. The cap deliberately does NOT feed FR-013a: one abnormal request is
  not a broken log, and conflating them would send the operator after the wrong thing.

**Safety**

- **FR-013**: Recording MUST NOT throw, and a recording failure MUST NOT alter the
  response an AI client receives or the outcome of an ability.
- **FR-013a**: A log that is FAILING MUST be distinguishable from a log that is QUIET. The
  screen MUST report that recording is failing rather than render an empty list, and a Site
  Health check MUST report the same, so the condition is discoverable remotely without the
  operator opening the screen.

  This is the same reasoning that rejected sampling — an operator cannot tell a sampled log
  from a quiet one — applied to the case the feature actually hits. FR-013 requires recording
  to swallow its failures so a client's call is never broken; that is correct and must stay,
  but it is exactly what makes a failure invisible. Swallowing the error and reporting
  nothing are two different decisions, and only the first one is required.

  Not hypothetical: a defect that made every write fail silently shipped and was found only
  by querying the table directly, with the screen showing a plausible empty list throughout.
  The failure counter this reads already exists and is already maintained — it is capped so a
  failing write path cannot amplify itself — so this is a read-side obligation only.
- **FR-013b**: A schema upgrade that fails MUST be surfaced the same way, not only signalled.
  `acrossai_mcp_schema_upgrade_failed` is fired by the storage layer and currently has no
  listener, so a migration that could not apply is as silent as a failed write — and across
  many installs, silence is indistinguishable from success.
- **FR-014**: System MUST redact credential-like values before storage using the single
  shared pattern list, not a second list maintained in parallel.
- **FR-015**: Recorders MUST NOT record their own writes, and MUST NOT recurse.
- **FR-016**: Every request-scoped holder this feature introduces — the authentication
  context and the attribution context alike — MUST follow the project's established
  three-stage lifecycle: capture before dispatch, clear on the normal path, **and clear
  again on request shutdown as a safety net**. The shutdown stage is not optional.
- **FR-017**: Captured context MUST NOT survive into a subsequent request. Under a
  persistent runtime, a request that dies before its normal cleanup MUST still leave the
  holder empty, so no call is ever attributed to the wrong client.

**Reading**

- **FR-018**: System MUST provide an administrative screen listing requests newest first,
  with their abilities revealed on expansion rather than on a separate screen.
- **FR-019**: System MUST allow the list to be narrowed by server, connection type,
  invocation source, status, method, component, user and date range, and searched by
  component name.
- **FR-020**: System MUST allow the list to be viewed as abilities rather than requests.
- **FR-021**: System MUST show period totals for volume, average duration, success rate,
  distinct active clients, and total context returned.
- **FR-022**: System MUST allow sorting by response size.
- **FR-023**: System MUST present each row's detail, including the context cost, with any
  token figure labelled as an approximation and a derivation that site code can override
  or decline.
- **FR-024**: System MUST NOT present a figure as LLM token usage, and MUST NOT store one.
- **FR-025**: System MUST offer the same list filtered to a single server from that
  server's own management screen.
- **FR-026**: Paging MUST remain responsive on deep pages.
- **FR-027**: All reads and the purge MUST be refused to users without the required
  capability.

**Governance**

- **FR-028**: Request recording and ability recording MUST each have an independent
  enable control, both off on installation. The ability control's description MUST state that
  ability arguments are recorded — key-name redaction cannot cover free-text values, so the
  operator needs to know before switching it on.
- **FR-029**: When request recording is off, the system MUST incur no per-request
  recording cost — the recording path MUST NOT be reached at all.
- **FR-029a**: Both halves MUST record by default, and the shipped bounds MUST make an
  untouched install self-limiting: requests kept 30 days, ability runs 7, and a ceiling of
  50,000 events across both. An operator who never opens the settings form gets a useful
  screen AND a log that cannot grow without limit. The asymmetry between the halves is
  deliberate — ability events are several rows per call, are produced by REST, WP-CLI and
  admin callers too, and are the rows carrying recorded arguments.
- **FR-029b**: Recording on by default is sound ONLY while this feature is unreleased. Once
  096 ships, changing either default is a retroactive behaviour change on live sites over a
  table holding source addresses and recorded ability arguments, and MUST be treated as one.
- **FR-030**: Each half MUST have its own retention period and MUST be pruned on its own
  cutoff.
- **FR-031**: Scheduled cleanup MUST exist only while at least one retention period is
  finite, or a retained-event ceiling is set — and MUST be reconciled on an ordinary boot,
  not only on activation and on a settings save. WordPress skips activation hooks on a plugin
  UPDATE, and an operator content with the shipped defaults never saves the form, so both
  pre-existing callers can be missed. While recording was off by default that was harmless:
  nothing was written, so a missing pruning job pruned nothing. With FR-029a it means a site
  records from its first request with nothing ever deleting a row.
- **FR-031a**: Every storage bound in this feature — both retention periods and the
  retained-event ceiling — is enforced by the scheduled sweep ALONE, and therefore holds only
  while WordPress cron runs. The system MUST report when the sweep is scheduled but overdue,
  so a site whose cron is not firing learns that its log is no longer bounded instead of
  discovering it as unexplained growth.

  The system MUST NOT build a fallback scheduler of its own, and MUST NOT prune from the
  recording path. A site whose cron is dead has a site-wide fault — scheduled posts, updates
  and every other plugin's jobs are equally broken — and a private workaround would hide a
  problem the operator has to fix anyway, while making this plugin's behaviour diverge from
  every other one on the site. Reporting it is the correct scope; fixing cron is not this
  feature's job.

  This qualifies FR-030, FR-031 and FR-035, which otherwise read as unconditional guarantees.
- **FR-031b**: The one-table design MUST remain **splittable**. One table is the decision
  (D61) and is not under review, but splitting into separate request and ability tables is a
  live option, and these invariants are what keep that migration cheap. Each is free to hold now
  and expensive to retrofit once the table carries real history:

  1. **Every row MUST be classifiable from its own columns.** `event_type` does this. No row may
     depend on a sibling row, a join or external state to know which table it would belong to.
  2. **`event_type` MUST stay a closed set, and every value MUST have a declared destination.**
     Today that set is `request`, `ability` and `removal` — and `removal` is the one a
     request/ability split has no obvious home for. **Its destination is decided now: removal
     records follow the requests table.** They are operator-facing audit events about the log as
     a whole, not about any ability, and A24 requires the remaining history to open with them —
     which means they must live wherever the history an operator browses lives.

     **Consequence, recorded so a migration does not rediscover it:** a removal row reuses three
     columns for its own meanings — `component_name` holds the reason, `input_preview` holds the
     count removed, and `invoked_via` holds `cli` or `admin`. `invoked_via` is empty on every
     genuine request row, so a split that trimmed it from the requests table would silently lose
     who triggered each removal. The requests table MUST retain it.
  3. **Any reference to a row from outside this table MUST record the row's KIND alongside its
     id.** An id alone is ambiguous after a split: the same number could address either table.
     This binds Feature 097, whose `activity_id` points at an ability row *or* a request row —
     it carries `activity_type` for exactly this reason.
  4. **A split migration MUST preserve row ids.** `parent_id` and every external reference are
     plain integers; re-numbering on copy silently breaks all of them, and nothing would fail
     loudly at the time.

  These are **constraints on future work**, not tasks for this feature. Nothing here changes the
  shipped behaviour.
- **FR-032**: System MUST allow an administrator to purge the log, reporting how much was
  removed.
- **FR-033**: Uninstalling with data removal opted in MUST remove the log's storage.
- **FR-034**: The screen MUST state, where it applies, that non-MCP callers are recorded
  success-only on WordPress versions that report nothing else.
- **FR-035**: System MUST enforce a configurable ceiling on the number of retained events,
  removing the oldest first once it is exceeded, so worst-case storage is bounded
  independently of traffic volume. The ceiling is a **single global bound across all event
  types**, not a cap per type: the newest N events survive regardless of kind, so a burst of
  ability events CAN evict older request events. This is deliberate — it is what makes
  worst-case storage one number rather than the sum of per-type caps — and it is the
  requirement the rejected two-table design could satisfy only by abandoning the global
  guarantee (`research.md` D-15). Retention, by contrast, remains per-type (FR-030/FR-031),
  so the two controls differ in scope and the settings copy MUST not blur them.
- **FR-036**: System MUST NOT sample. Every event that the enabled toggles cover is either
  recorded in full or not at all, so counts, success rate and size rankings remain facts
  rather than estimates.

**Privacy**

- **FR-037**: System MUST register a personal-data exporter so a subject-data request
  returns that user's recorded activity, including the source addresses recorded against
  them.
- **FR-038**: System MUST register a personal-data eraser so a subject-data erasure
  request **de-identifies** that user's recorded activity: the acting identity and the source
  address are cleared, the event itself — its timing, outcome and server — is retained. The
  eraser MUST NOT delete events. Deleting them would turn a privacy mechanism into an
  evidence-destruction path reachable without administrative capability.
- **FR-039**: The source address MUST be recorded in full, and MUST be treated as personal
  data everywhere it is stored, displayed or exported.

**Performance**

- **FR-040**: When recording is on, it MUST add no more than 5% to the duration of the
  call it observes, and MUST perform a single write with no joins on that path.

**Time**

- **FR-041**: Event times MUST be stored in UTC, consistent with the plugin's existing log
  table.
- **FR-042**: Displayed times, the period picker, date-range filter input and the retention
  cutoff MUST all be interpreted in the site's configured time zone, so a stated period
  means the same thing to the administrator reading it and to the job pruning it.

**Accountability** *(added from the plan security review, 2026-10-16)*

- **FR-043**: Every path that removes events — the administrator purge, the retention job and
  the retained-event ceiling — MUST record that it ran, naming who or what triggered it, when,
  and how many events went. The record MUST be written **after** the removal, so it survives as
  the first entry of the remaining history. Without this, the destruction of the log is the one
  action the log cannot show.
- **FR-044**: A filter MUST allow a site to suppress or further scrub recorded input previews
  without disabling the log, so an installation handling regulated data is not forced to choose
  between no diagnostics and recording argument content.
- **FR-045**: The name shown for a logged call MUST be the one that matches the rest of the
  system's vocabulary, so an operator can search for it, and Feature 097 can join to it. Where
  the client used a different spelling of that name, both MUST be recorded — an operator
  diagnosing "my client says this tool does not exist" needs the exact string the client sees.

- **FR-046**: Recorded values originate outside the site and MUST be treated as untrusted when
  displayed. The screen renders them inert: no value reaches the page as markup, and none is
  interpolated into a script context by an HTML-context escaper. The audience for this screen is
  an administrator, which makes it the highest-value target on the install.

**Schema change safety**

- **FR-047**: A change to the log's storage shape MUST actually reach a table that already
  exists, not merely be declared in the schema. Declaring it is not sufficient and the
  difference is invisible:
  - Nothing in the storage layer compares a live table's **indexes** against the declared
    schema — `dbDelta` leaves an existing index alone, and the plugin's own reconciler
    handles columns only. A re-keyed index therefore keeps its old key under its new name
    while every version check reports agreement. Queries stay CORRECT and only get slower,
    so neither a test nor an operator notices. Mitigation: a changed index MUST be RENAMED,
    so the name itself distinguishes the old shape from the new.
  - When the stored version option is **absent but the table exists**, the storage layer
    runs no migration callbacks and then stamps the current version regardless — so the
    table keeps its old shape, reports itself current, and every later boot agrees. A
    migration skipped this way is unrecoverable without manual intervention. Mitigation: a
    missing version MUST be treated as the oldest shipped version, not as "current".
  Both failure modes are silent in the same way: the declared state and the physical state
  disagree while every check that exists compares the declared state to itself. Discovered
  by the 1.1.0 index re-key (`research.md` D-16, D-17) and recorded against all of the
  plugin's storage modules, not this one alone.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (plugin header `Requires PHP: 8.1`; floor is pinned by the
BerlinDB dependency — it is not reducible to 8.0 or 7.4)
**WordPress Version**: 6.9+ (plugin header `Requires at least: 6.9`). Richer ability
capture is available on 7.1+ and MUST be feature-detected, never required.
**Multisite**: Single-site storage per site (per-site table prefix). Network-wide
aggregation is out of scope for this increment.
**Required Plugins / Packages**: `wordpress/mcp-adapter` (already a dependency — supplies
the request-observability seam and the result filters); BerlinDB (already vendored).
**Optional Integrations**: the third-party `mcp-tracker` plugin — if active, both log
independently; the screen notes the overlap. No dependency either way.

### Module Placement

**PHP Class(es)**:
- `includes/Database/ActivityLog/{Schema,Table,Row,Query,Recorder}.php` → namespace
  `AcrossAI_MCP_Manager\Includes\Database\ActivityLog` — context-neutral storage
- `includes/Observability/{ActivityObservabilityHandler,ConnectionContext,AuthContextHolder,ResponseSizeProbe}.php`
  → namespace `AcrossAI_MCP_Manager\Includes\Observability` — context-neutral capture
- `includes/Abilities/AbilityActivityRecorder.php` → namespace
  `AcrossAI_MCP_Manager\Includes\Abilities` — context-neutral capture
- `includes/Observability/CaptureContext.php` → namespace
  `AcrossAI_MCP_Manager\Includes\Observability` — context-neutral; the seam Feature 097
  attaches to. **Not** `includes/History/`: that name describes Feature 097's domain, and a
  directory owned jointly by two features at different maturity satisfies neither "singular
  purpose" nor "replaceable without affecting siblings". 097 creates `includes/History/` for
  its own files when it ships. Together with the authentication holder it follows the project's established
  request-scoped holder lifecycle (FR-016), whose canonical implementation is
  `includes/Abilities/CurrentServerHolder.php` — including the shutdown stage, whose
  omission is a known recurring defect in ad-hoc versions of this pattern
- `includes/REST/ActivityLogController.php` → namespace
  `AcrossAI_MCP_Manager\Includes\REST`
- `admin/Partials/ActivityLogPage.php` → namespace
  `AcrossAI_MCP_Manager\Admin\Partials` — registers the submenu and enqueues assets

**Hook Registration**: All `add_action`/`add_filter` calls for this feature MUST be wired
in `includes/Main.php` via `define_admin_hooks()`, with **no exceptions** — including the
three holder-lifecycle hooks, the WordPress 7.1 ability hooks, the five adapter result
filters, and the cleanup cron. The `try`/`finally` bracket in
`Base_Toolset_Ability::do_execute()` and `Abilities\Execute` is inline code, not hook
registration, so it is not an exception to this rule and must not be described as one.

### Admin UI Requirements

**New screen** (created after constitution ratification):
- Lists/tables MUST use `DataViews` (`@wordpress/dataviews`) — no custom table HTML
- No forms are introduced; the settings controls extend the existing Settings screen
- `@wordpress/dataviews`, `@wordpress/components`, `@wordpress/element`,
  `@wordpress/api-fetch` and `@wordpress/data` are already project dependencies — no new
  package is required

The screen is mounted twice from one component: a new top-level page under the existing
AcrossAI menu (not under Tools), and inside the existing per-server `Logs` tab
(slug `mcp-log`), pre-filtered to that server with its server column hidden. That tab
currently only advertises the third-party `mcp-tracker` plugin; its body is replaced.

### REST API Contract

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/wp-json/acrossai-mcp-manager/v1/activity` | `manage_options` | List rows, filtered and paginated |
| `GET` | `/wp-json/acrossai-mcp-manager/v1/activity/summary` | `manage_options` | Period aggregates for the stat tiles |
| `GET` | `/wp-json/acrossai-mcp-manager/v1/activity/filters` | `manage_options` | Distinct values for the filter controls |
| `GET` | `/wp-json/acrossai-mcp-manager/v1/activity/{id}` | `manage_options` | One row's detail |
| `GET` | `/wp-json/acrossai-mcp-manager/v1/activity/{id}/children` | `manage_options` | The abilities recorded beneath one request |
| `DELETE` | `/wp-json/acrossai-mcp-manager/v1/activity` | `manage_options` | Purge, returning the number removed |

**`permission_callback` rule**: `__return_true` is permitted ONLY on public read-only
routes. Every route above is administrative and MUST check capability via a
`permission_check` method, matching the pattern in the plugin's existing controllers. The
capability is `manage_options`: verified as the house convention for admin controllers, and
there is no `AccessControl` class governing admin screens — `AccessControlTab` governs MCP
*client* access per server, which is a different concern.

### Database / Storage

**Custom DB table** (options/meta cannot fit the model — justified):
- Table: `{wpdb->prefix}acrossai_mcp_activity_log`
- Justification: unbounded append-only event rows, queried by range and ordered by time,
  with nine indexes and keyset paging. Options and post meta offer no indexing on these
  access paths and would load the whole set to answer one filtered page. This matches the
  existing `cli_auth_logs` precedent in the same plugin.
- One table, not two: request rows and ability rows are the same shape, discriminated by
  an event-type column and related by a self-referencing parent column. Change history is
  deliberately **not** a third type here — it is a different shape and belongs to Feature
  097 with its own table.
- The one-table choice is **measured, not asserted** (`research.md` D-14..D-16). Two tables
  is in fact 1.2x-2.4x faster on most single-kind queries, but every difference is under
  1.5 ms at ten thousand rows, while the mixed-kind read the global ceiling depends on costs
  0.14 ms on one table against 125 ms on two at 150,000 rows. The deciding factor is
  therefore FR-035's global bound, which two tables can only match by dropping the
  guarantee — not raw query speed, which favours the split.
- **Every composite index leads with the event-type discriminator.** One table holds two
  populations, so a key that omits it matches rows of BOTH kinds and every single-kind query
  pays for the other kind's rows — measured at 742 index entries examined against 239 for
  the identical answer. The screen always filters by event type (the list defaults to
  requests), so the predicate is present on every query and no caller wanted these keys
  un-prefixed. Four of the nine shipped without it and were re-keyed in schema 1.1.0; the
  rule is written here rather than left to the data model because SC-026 now gates it.
- **Three offered filters are intentionally unindexed**: `invoked_via`, `method` and
  `component_category`. Each has only a handful of distinct values, so a key over them buys
  little selectivity while the nine existing indexes already outweigh the row data roughly
  7:3. See SC-026.
- Timestamps stored in UTC, matching `CliAuthLog`; converted for display and for
  interpreting operator-supplied date ranges.
- Created on activation via the plugin's existing activator; dropped on uninstall under the
  existing data-removal opt-in.
- Settings keys extend the existing Settings screen: two enable flags, two retention
  periods, and one retained-event ceiling.

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [x] All form/AJAX handlers verify nonce via `wp_verify_nonce()` or `check_ajax_referer()` — REST writes rely on the standard REST nonce; the settings form uses the existing Settings nonce
- [x] All admin page renders check `current_user_can('manage_options')` (or more granular capability)
- [x] All REST routes have explicit `permission_callback` — no `__return_true` on any route in this feature
- [x] All user input sanitized at system boundary with most-specific function (`sanitize_text_field()`, `absint()`, etc.) — every collection parameter and every recorded tag
- [x] **Redaction is key-name based, and the spec says so.** The shared pattern list matches sensitive *keys*; a secret arriving inside a free-text argument value (a `content`, `search` or `message` field) matches nothing and is stored as sent, subject only to truncation. This is a known and accepted limit, not a gap to be papered over — FR-044's filter is the escape hatch, and the ability-logging setting must tell the operator that arguments are recorded
- [x] All output escaped at point of rendering with most-specific function (`esc_html()`, `esc_attr()`, `esc_url()`, etc.)
- [x] **Every value localized into JavaScript uses `wp_json_encode()`** — never `esc_html()` or `esc_attr()`. Both are HTML-context escapers and neither makes a value safe inside a JS string literal
- [x] **`dangerouslySetInnerHTML` appears nowhere in `src/js/activity-log/`**, and a test asserts a scripted `component_name` reaches the screen inert
- [x] **Every `admin_url()` is wrapped in `esc_url()`** — it is filterable via the `admin_url` hook
- [x] All DB queries use `$wpdb->prepare()` — no raw interpolated queries, including the keyset-paging comparison and the per-event-type retention cutoffs
- [x] OAuth tokens / Application Passwords stored hashed (SHA-256 minimum) — never plaintext. This feature stores **no** credentials: only a connector slug and a client-reported application name
- [x] File uploads (if any) validated for MIME type, extension, and size before processing — N/A
- [x] Recorded previews are redacted through the shared sensitive-key pattern list and truncated; raw bodies are never stored
- [x] The response-size probes are read-only pass-throughs and MUST return the value they received unchanged
- [x] Personal data (source address, user id) is reachable by WordPress core's personal-data exporter and removable by its eraser — this feature is the first in the plugin to store an address, so neither hook exists yet and both are new work

**Verified 2026-10-23.** Each line above was checked against the code rather than assumed, after
the Phase 8 fixes. Four results differ from the literal wording and are recorded here instead of
being quietly ticked:

- **`__return_true` / `dangerouslySetInnerHTML`** — the only matches in the feature are comments
  asserting their own absence (`ActivityLogController` docblock, `DetailPanel.js:17`). Zero real
  uses, which is what the lines claim.
- **`admin_url()` uses `esc_url_raw()`, not `esc_url()`** — and that is correct here, not a miss.
  The value is passed through `wp_json_encode()` into JavaScript; `esc_url()` HTML-encodes the
  ampersand to `&amp;` and would corrupt the URL in a JS context. Using the named function
  literally would be the bug.
- **One statement is not `prepare()`d** — `ActivityLogController::purge()` runs
  `DELETE FROM \`{$table}\``, whose identifier is `$wpdb->prefix` plus a class constant and which
  binds no values at all. `prepare()` cannot parameterise an identifier and there is nothing else
  to bind. Same for the two `ALTER TABLE` statements in `Table::upgrade_to_1_1_0()`, whose index
  names come from a hardcoded map. All three carry a `phpcs:ignore` naming that reason.
- **The settings form's nonce is the vendor's** — F096 registers no form or AJAX handler of its
  own. `TabbedPageRenderer.php:78` emits `settings_fields( $page_slug )` and `options.php`
  verifies it; REST writes use the standard REST cookie nonce.

Gate results at the time of verification: PHPCS clean, PHPStan level 8 clean (cache cleared),
ESLint clean, `npm run build` exit 0, `validate-packages` exit 0, all 17 PHPUnit suites green at
1,476 tests / 3,860 assertions. Of the four pre-ship scripts, three exit 0 and
`validate-structure` still fails C04 on `index.php` and `phpstan-bootstrap.php` — **pre-existing,
unmodified by this feature**, exactly as T092 recorded.

### Key Entities *(include if feature involves data)*

- **Ability**: the individual action that runs behind a client's tool call — the canonical
  term throughout this feature, matching `WP_Ability`, the `wp_ability_*` hooks and the
  sibling `acrossai-abilities-manager` plugin that publishes them. The UI, its filter chips
  and all translatable strings use "Ability"; "operation" is not used as a synonym.
- **Activity event**: one thing that happened — either a request an MCP client made, or an
  ability that executed. Carries what was invoked, by whom, from where, how it was
  authenticated or invoked, the outcome, how long it took, redacted previews of input and
  result, and the sizes of both. An ability event may name the request event that caused
  it; a request event never has a parent.
- **Personal data in the log**: the acting user's identity and the client's source address.
  Recorded in full for diagnosis — a connector that begins failing is often identified by a
  changed origin — and exposed to WordPress's subject-data export and erasure flows so an
  administrator can answer a request about either.
- **Server attribution**: which managed MCP server answered. Retained on the event so
  history stays readable after a server is deleted.
- **Connection type**: how the client's credential arrived — an AI connector, a bare OAuth
  client, an application password, a browser session, or unknown.
- **Invocation source**: which surface executed an ability — MCP, the REST API, WP-CLI,
  the admin screens, or unknown. Distinct from connection type: the two answer different
  questions and are populated on different events.
- **Context cost**: the size of what the server returned, and an explicitly approximate
  token figure derived from it at read time. Never stored as a token count.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [x] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [x] PHPStan level 8: zero errors (`vendor/bin/phpstan`) — **clear the result cache first**; a warm cache reports errors CI never sees
- [x] ESLint: zero errors (`npm run lint:js`)
- [x] PHPUnit tests written and passing for all new PHP logic, including a schema-contract test asserting every declared column exists at its declared width after install
- [x] Security checklist above: all applicable items verified
- [x] All hooks wired in `Main.php` — none in class constructors; the two execution-path brackets are the documented exception
- [x] All new admin UI uses DataForm/DataViews (no pre-approved exception is claimed here)
- [x] No code duplication — shared logic extracted to `includes/Utilities/`; the sensitive-key list and the recorder ignore list each exist once
- [x] All functions, hooks, and classes prefixed with `acrossai_mcp_`
- [x] `npm run validate-packages` passes
- [x] `npm run build` exits 0
- [x] The four pre-ship validate scripts pass (structure, security, deprecations, REST endpoints)

### Measurable Outcomes

- **SC-001**: An administrator can identify which server answered a failing client call,
  and how that client authenticated, within 30 seconds of opening the screen — without
  reading a log file or running a query.
- **SC-002**: For any logged tool call, the administrator can see the abilities that ran
  behind it in one interaction, without leaving the row.
- **SC-003**: 100% of failed, refused and invalid-input ability runs appear with a non-success
  outcome. No failure is presented as a success and none is missing from the list.
- **SC-004**: An ability observed by more than one capture path appears exactly once in
  the list, never twice.
- **SC-005**: With recording disabled, client calls produce nothing in the list and add no
  overhead at all — the recording path is not reached.
- **SC-006**: With recording enabled, it adds at most 5% to a call's own duration,
  confirmed against real client traffic rather than a synthetic benchmark.
- **SC-007**: When recording cannot complete, the AI client still receives the same
  response it would have received with logging switched off.
- **SC-008**: A credential-like argument value is unreadable anywhere the log is displayed
  or exported.
- **SC-009**: The administrator can rank the period's calls by how much the server returned
  and identify the largest consumer, including the catalogue announcement made at connect.
- **SC-010**: Every token figure on screen is marked approximate; no screen or stored field
  presents a billable or exact token count.
- **SC-011**: Each half of the log ages out on its own retention period — expiring the
  noisier half leaves the quieter half's history intact.
- **SC-012**: A user without the required capability is refused on every route and cannot
  reach the screen.
- **SC-013**: A subject-data export for a given user returns that user's recorded activity,
  and a subject-data erasure for that user leaves none of it attributable to them.
- **SC-014**: With the ceiling set, sustained traffic does not grow the log beyond it — the
  oldest history is shed first and the newest is always present. Verified as a GLOBAL bound:
  traffic consisting only of ability events, sustained past the ceiling, evicts older request
  events too, and the total row count never exceeds the configured number.
- **SC-015**: Every figure the screen reports is derived from completely recorded events,
  never from a sample.
- **SC-016**: Paging to a deep page is no slower than paging to the first.
- **SC-027**: An install where nobody has opened the settings form records AND prunes. With
  every activity option absent, the adapter is handed the recording handler, the daily sweep
  is scheduled, and the shipped bounds apply. Verified against the ABSENT options
  specifically — the declared setting default and the fallback each reader passes to
  `get_option()` are separate mechanisms that can disagree.
- **SC-028**: Unticking either toggle stops that half recording. Asserted by simulating the
  save rather than by reading the setting back: an unchecked checkbox posts no field, so the
  value reaching storage is the sanitized absence, and whether that persists depends on the
  declared default differing from it. When the two defaults disagreed, unticking the box
  reported success and changed nothing.
- **SC-026**: Every **indexed** filter the screen offers resolves through a key whose columns
  are keyed for it, asserted from the declared-versus-live index columns rather than from a
  stopwatch. A millisecond target was rejected for the same reason SC-006 is relative: on a
  fast host every shape passes. The distinction this criterion has to catch is not index
  versus scan — the 1.0.0 keys WERE used, they were merely less selective than they should
  have been, examining 742 index entries where 239 sufficed for the identical result. So the
  assertion is on the key's COLUMNS, not on an index merely being chosen.

  Three of the ten offered filters — `invoked_via`, `method` and `component_category` — have
  **no** backing index, deliberately, and this criterion does not claim otherwise. All three
  are low-cardinality (a handful of distinct values each), so a key over them would be
  poorly selective exactly where it was asked to help, while the table's indexes already
  account for roughly 70% of its size — 60 MB of 86 MB at 150,000 rows. They are served by
  `type_created` for ordering plus a scan of the matching event-type range, which the period
  filter already bounds. Stated as an accepted limit rather than left for a reader to
  discover from the schema: a future change may add them, but it should do so because a
  cardinality measurement justified it, not because this list looked incomplete.
- **SC-017**: History remains readable, and still names the server that answered, after
  that server is deleted.
- **SC-031**: On a site where the scheduled sweep is overdue, the system says so. Verified by
  moving the scheduled time into the past rather than by disabling cron, so the assertion is
  about the report and not about the host.
- **SC-030**: With recording enabled and writes failing, the screen says recording is failing
  and Site Health reports it — neither presents the empty result as "nothing happened".
  Verified by forcing writes to fail, not by setting the counter directly: the counter being
  right is not the claim, the operator being told is.
- **SC-029**: One MCP tool call produces one request row and N ability rows whose `parent_id`
  is that request's id, with `invoked_via` reading `mcp`. Verified by driving a real dispatch
  rather than by setting the parent id in the test — the linkage was absent in 1.0.0 precisely
  because its unit tests supplied the value the production path never did.
- **SC-018**: A second feature can attribute its own records to the call that caused them
  without modifying this feature's capture code — the contract Feature 097 depends on.
- **SC-019**: An administrator in a non-UTC time zone sees each event at the local time it
  happened, and "last 24 hours" selects the same events the retention cutoff would treat as
  24 hours old.
- **SC-020**: After a request ends abnormally, the next request records its own identity
  and nobody else's — verified on a runtime that reuses the process, not only on one that
  tears it down.
- **SC-021**: After any removal — purge, retention or ceiling — the remaining history opens with
  a record of what was removed, by whom, and when.
- **SC-022**: A subject-data erasure leaves no event attributable to that person, and removes no
  event from the history.
- **SC-023**: A tool name or error message containing markup or a script reaches the screen as
  visible text and executes nothing.
- **SC-024**: Searching the log for an ability by the name used elsewhere in the plugin finds
  the calls that ran it — the displayed name is not a variant spelling that matches nothing.
- **SC-025**: A storage-shape change is verified against a table that already holds the
  previous shape, in both of the ways it can be missed: upgrading from the prior stored
  version, and upgrading a table whose stored version is absent. Verification compares the
  live table's actual columns AND index columns against the declared schema — not the
  version number against itself, and not merely that each declared name exists.

---

## Assumptions

Recorded defaults, taken from `docs/planings-tasks/096-mcp-activity-log.md` rather than
left open. Each may be revisited in `/speckit-clarify`.

- **Capability** is `manage_options`, verified against the plugin's existing REST
  controllers. There is no `AccessControl` class covering admin screens; `AccessControlTab`
  is per-server client access and does not apply. Granting a lower-privileged auditor
  read-only access to the log is a possible future refinement, not part of this increment.
- **Retention** defaults to 30 days for requests and **7 for ability runs**. The reference
  implementation keeps request logs forever; MCP logs grow faster, so a finite default is
  safer, and the two halves do not grow at the same rate — ability rows were ~68% of volume
  when benchmarked (6,377 of 9,377), because one tool call runs several abilities and
  REST/WP-CLI/admin callers are recorded too. Seven days keeps the drill-down useful for the
  window anyone debugs in while cutting the dominant growth term. The asymmetry is the
  decision; equal numbers would make it look like an oversight.
- **A retained-event ceiling** bounds the worst case on top of retention, trimmed
  oldest-first by the same scheduled job, as ONE bound shared across event types (FR-035).
  Neither reference plugin has one; both rely on time alone, which leaves size a function of
  traffic. Sampling was considered and rejected: it would make the success-rate and
  context-size figures estimates, and an operator cannot tell a sampled log from a quiet one.
  **It defaults to 50,000** (revised 2026-10-23; it was 0 for one day). The earlier
  reasoning — that a non-zero default makes the plugin delete history the operator never
  chose to lose — assumed logging was off until switched on. Once recording starts by
  default the plugin is also CREATING that history uninvited, and the symmetry argument
  reverses: something that records without being asked should bound without being asked.
  50,000 is 5x the benchmarked design point, so an ordinary site never reaches it. 0 remains
  selectable and still means no limit.
- **Logging is on by default for both halves**, which is safe to introduce rather than
  offer as an opt-in only because 096 has never shipped — there is no install whose operator
  declined it. After release, flipping either default back is itself a behaviour change on
  live sites and must be treated as one (FR-029b).
- **Design point is roughly ten thousand events**, which is what the storage shape was
  benchmarked against: at 9,377 events every query behind the screen returned in under
  2.5 ms, and a 16x sanity check at 150,032 events left the slowest UI read at 2.8 ms, with
  only the scheduled retention scan reaching 33 ms (`research.md` D-14, D-15). No figure
  here is a supported maximum — nothing in the design fails above it — but a site holding
  very much more should expect the retention job, not the screen, to be the first thing that
  wants attention.
- **Noise floor**: `ping` is excluded from recording by default and the exclusion is
  filterable. It is most of the volume and least of the value.
- **Session identity on connect**: the identifier reported for a newly opened session is
  used when no established identifier is present, so the one event carrying the client's
  identity can still be tied to the session it opened.
- **Protocol version** is recorded. It is the first thing wanted when a client breaks after
  an upgrade.
- **Client user agent** is recorded, so the npm bridge and a CLI client — which
  authenticate identically — remain distinguishable without inventing a sixth connection
  type.
- **Context-cost derivation** divides recorded bytes by a filterable constant defaulting
  to 4, then passes the result through a second filter so a site able to produce an exact
  count can substitute one, or decline and have an absence displayed. The default will read
  low for structured payloads; this is accepted and labelled, not corrected by storing a
  guess.
- **Stat tiles** stay at four, matching the familiar reference layout; context cost appears
  in the period header and as a sortable column rather than as a fifth tile.
- **Overhead budget** is 5% of the observed call's own duration, verified once on real
  traffic during Phase 0 rather than asserted by a synthetic benchmark. A relative budget was
  chosen over a fixed millisecond ceiling because the plugin runs on hosts of wildly
  differing speed, where a flat figure is simultaneously unachievable and meaningless.
- **Times are stored in UTC** via the same call the plugin's existing log table uses, and
  converted only for presentation and for interpreting operator-supplied ranges. Storing
  site-local time was rejected: a time-zone or daylight-saving change would make existing
  rows unorderable against new ones.
- **Multisite** means per-site storage with no network-wide view in this increment.
- **Full payload capture is permanently out of scope.** The underlying seam does not supply
  response bodies, and storing them is the specific mistake the third-party reference plugin
  makes.
- **Pre-dispatch failures** — an authentication rejection or a malformed request body — are
  not recorded, because they never reach the seam this feature taps. Storage reserves a
  source field so they can be added later as their own event type.
- **Change history is out of scope** — no change table, no WordPress write hooks, no
  before/after diff. Feature 097 owns it end to end; 096's only obligation is the
  attribution seam (FR-012, SC-018).
