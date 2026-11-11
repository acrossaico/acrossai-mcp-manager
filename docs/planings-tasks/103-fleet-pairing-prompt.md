# Planning: Pair a site by pasting a prompt (Feature 103)

## In plain English

Adding a site to a fleet currently means carrying a code between two browser tabs. You open the
child site's Fleet tab, read a twelve-character security ID off it, switch to the hub's admin, and
type the ID plus the site's address into a form. The hub then does a handshake behind the scenes.

This feature replaces the carrying with a paste. The child's Fleet tab shows a short block of
prose; you paste it into an AI that is already connected to your **hub**; the AI calls one ability
and the site is paired. Nothing to edit, nothing to restart.

It is the same idea as Feature 099 (the Generate-Prompt path for MCP clients), but it lands much
smaller. 099 had to automate five manual steps — open a config file, find a key, merge a block,
save, restart — because the work really was file editing. Here the work is a single function call
that already exists, so the prompt has nothing to describe except which call to make.

## Why the current flow runs in that direction

Worth stating, because it is the thing that makes this feature possible at all.

This plugin is the **child** half. It exposes one unauthenticated route:

    POST /wp-json/acrossai-mcp-manager/v1/fleet/register
    { "security_id", "pubkey", "hub_url", "hub_name" }  →  { "host_key", … }

The hub sends a **public** key; the child stores it and answers with a host key. Nothing secret
crosses the wire and nothing worth stealing is left in the database — that is why this shape was
chosen over issuing an Application Password (`includes/Fleet/Register.php`).

The security ID is what authenticates the call. It is guarded by constant-time comparison,
per-IP rate limiting, and the requirement that fleet access be switched on at all.

So the hub is always the caller, and it is the hub that keeps the roster. Both an "add a site"
and a "remove a site" ability therefore belong on the hub, not here.

## The one call that makes this cheap

The entire hub-side handshake is already a single public method:

    // acrossai-pro/includes/Fleet/Pairing.php:78
    public static function connect( string $site_url, string $security_id )

Checked for UI coupling: no `current_user_can`, no `$_POST`, no `check_admin_referer`, no
`wp_die`. It generates the keypair, posts to the child, stores the roster row, and returns either
the site array or a `WP_Error`.

**No refactor is needed.** Both new abilities are wrappers over methods the hub's own admin
buttons already call.

## The split across two repos

`toolset/fleet` already existed — in Pro, at `acrossai-pro/includes/Fleet/Ability.php:71`. Its
actions were `sites`, `tools`, `call`, `ask`: all of them operate a fleet that already exists.
There was no action that *creates* or *removes* a member, and that was the gap this feature
started from. (It has since been split up entirely — see above.)

| Repo | Half | Work |
| --- | --- | --- |
| `acrossai-pro` | hub | Seven Fleet abilities — see "What shipped on the hub" below. `toolset/fleet` is gone |
| `acrossai-mcp-manager` | child | Fleet tab redesign: prompt first, security ID as the fallback |

The child cannot host the ability: it has no keypair, no roster, and is not the party doing the
registering.

## What shipped on the hub (acrossai-pro#132)

The plan below survived contact in its essentials and grew twice. Recorded here because the
sections after it were written against the smaller version and read oddly otherwise.

`toolset/fleet` no longer exists. Fleet is seven abilities:

| Ability | Role |
| --- | --- |
| `fleet/list-sites` | entry point; hands out the site keys everything else needs |
| `fleet/site-tools` | what one managed site can do |
| `fleet/tool-info` | one tool's full input schema |
| `fleet/call-site` | run it on one site |
| `fleet/ask-sites` | run it across several |
| `fleet/add-site` | pair a site (this feature) |
| `fleet/remove-site` | stop managing one (this feature) |

Two things drove the growth beyond add/remove.

**The `action` enum had to go.** Each extraction used the same argument — the dispatcher was
transport, and an action that answers from a local table is not transport. That applied to `sites`
as squarely as to pairing, and once `sites` left, the remaining three were worth lining up with
the shape an assistant already meets on every managed site: discover, read the schema, execute.

**`fleet/tool-info` closed a hole this plan had not noticed.** `site-tools` drops input schemas on
purpose, and the comment justifying that said an assistant "gets the schema from the child when it
calls one" — which is learning a schema by calling blind and reading the failure, against someone
else's production site. The child publishes schemas in the same `tools/list` answer the hub was
already fetching and discarding, so the fix cost nothing.

`Ability_Support` holds what would otherwise be seven copies of the gate, the site lookup, the
`tools/list` fetch and the declaration filters, and declares every slug from one list.

### Two breaking changes, both deliberate

`toolset/fleet` and its `action: "sites"` are both removed rather than aliased. A client's tool
list is fixed at connect time, so anything already connected keeps calling the old tool until it
reconnects. Taken as a clean break because Fleet shipped in 0.11.0 and an alias is a second code
path nobody would delete.

### Left for a decision

`fleet/call-site` has no confirmation step while `fleet/ask-sites` does, so the same destructive
tool is confirmed when aimed at one site through `ask` and not through `call`. Pre-existing;
carried over exactly rather than changed mid-split, and written into the class docblock so it is
settled on purpose rather than discovered.

## The ability contract (Pro)

**Decision (2026-11-10, revised): a second, standalone ability — `fleet/add-site` — not a fifth
action on `toolset/fleet`.**

The first draft of this doc proposed extending the action enum. That was wrong once the existing
ability was read properly. `toolset/fleet` was **transport**: its four actions all forwarded a
call to a site already in the roster, and its own docblock said so — *"Not a cargo ability: this
is transport"*, which is why it carried `mcp.public => false`. Pairing forwards nothing. It does
its work locally, on the hub, and it is the only fleet operation that can run when the roster is
empty. Folding it into the forwarder would have meant an action that ignores every other field in
the schema.

That same argument then applied to `sites`, and then to the rest — which is how the dispatcher
ended up gone. See "What shipped on the hub" above.

The Fleet tab on AcrossAI → Abilities held exactly one row when this was written. It now holds
seven.

    fleet/add-site
      site_url     string, required  — the child site's address
      security_id  string, required  — twelve characters from that site's Fleet tab
      confirmed    bool              — required before anything is written

Label: **Add a site to this fleet**. The slug follows the ecosystem's verb-noun convention
(`mcp-manager/query-logs`, `file-manager/read-file`) and the hub's own vocabulary — `toolset/fleet`
described itself as *"Manage other WordPress sites from this one"*, so "add a site" was the phrase
already in use. `fleet/pair-site` was the alternative; rejected because a model matching a user's
"add this site to my fleet" will reach for `add` first, and the internal term *pairing* appears
nowhere a caller can see.

Returns the roster entry — site key and label — so the model can go straight to
`fleet/site-tools` and `fleet/call-site` against the site it just added.

## The second ability: `fleet/remove-site`

Pairing without unpairing leaves the fleet one-way, so the Fleet tab gets a third row, not a
second. Label: **Remove a site from this fleet**.

    fleet/remove-site
      site         string, required  — the site key from `fleet/list-sites`,
                                       or the site's URL
      confirmed    bool              — required before anything is written

One `site` field that takes either form, rather than a `site` / `site_url` pair with a
"exactly one of" rule. A model that has just run `fleet/list-sites` holds a key; a person pasting a prompt
holds a URL; both should work without the caller reasoning about which field they are in.
Resolution is a `Roster::all()` scan, which is a stored array.

It wraps `Roster::remove( string $key )` (`acrossai-pro/includes/Fleet/Roster.php:173`) — exactly
what the hub's own admin button calls at `admin/Partials/FleetPage.php:135`. No new mechanism.

### Removal is one-sided, and the ability must say so

This is the part to get right in the copy rather than discover later.

`Roster::remove()` drops the hub's row. It does **not** tell the child anything, because there is
nothing to tell it with: the child exposes exactly one fleet REST route, `Register`
(`includes/Fleet/Register.php:120`), and there is no revoke counterpart. The child's trust lives
in its own `_fleet_hosts` meta and is cleared only from its own screen —
`Hosts::remove()` has exactly one caller, `admin/Partials/Settings.php:193`.

So after `fleet/remove-site` the hub stops managing the site, and the site still holds the hub's
public key. Anyone restoring that roster row could manage it again without a new handshake.

**This asymmetry is pre-existing, not introduced here** — the hub's admin button behaves
identically. The ability must be faithful to it rather than quietly doing more, so:

- the success message says the hub has stopped managing the site, and that revoking the hub's
  access is done on the site itself;
- a revoke route on the child is recorded as a follow-up below, not smuggled into this feature.

### How they become callable

Same path its sibling uses, which is not the obvious one. Registration alone does not make an
ability an MCP tool here; the `acrossai_mcp_manager_tool_abilities` filter promotes a slug to
tool level (`acrossai-pro/includes/Main.php:1194`). Being promoted also marks the slug protected,
and protected slugs are excluded from tab grouping — so a second opt-in,
`acrossai_abilities_manager_groupable_protected_slugs` (`Main.php:1198`), is what keeps it
visible in the Fleet tab.

**Both** new slugs must go through **both** filters, or each is either uncallable or invisible.

Keep `mcp.public => false` on both, matching the sibling: tool-level promotion is what exposes
them, and setting `public` as well would advertise them a second time through discover-abilities.

Annotations differ, and should. `fleet/add-site` and `fleet/remove-site` both know exactly what
they do — unlike the abilities that relay someone else's tool, whose honest annotation is the
cautious one because it depends
on the forwarded tool. So annotate them truthfully: neither is readonly, `remove-site` IS
destructive, and both are idempotent (adding a site already in the roster, or removing one that
is not, changes nothing).

Three constraints, each with a reason. They apply to `fleet/remove-site` below as well.

1. **`confirmed` is mandatory.** Pairing grants another site standing control over this one, and
   removal silently ends a management relationship someone may still be relying on. The toolset
   already uses this pattern for anything that changes a site; an unconfirmed call must name the
   target and stop. Calling without it is how the operator sees what they are approving.
2. **`can_dispatch()` still gates them.** `Ability::CAP_FILTER` (`acrossai_pro_fleet_can_dispatch`)
   governs the existing actions and must govern these. Both are more sensitive than `sites`, so
   neither can be the one that skips the gate — and because they are separate abilities rather
   than actions on the dispatcher, each needs the check wired explicitly; it is not inherited.
3. **`WP_Error` passes through unflattened.** `Pairing::connect()` distinguishes "fleet is off on
   that site", "wrong security ID", "unreachable", and "already paired". Collapsing those into one
   failure string would make the prompt path unsupportable — the whole value is the model being
   able to say which of the four went wrong.

## The prompts (child)

**Must work in any LLM, not just Claude.** Feature 099's prompt could lean on Claude Desktop's
config file and restart behaviour. These are pasted into whatever the operator's hub is
connected to — Claude, ChatGPT, Gemini, Cursor, a local model — so it names no vendor, no app and
no file, and assumes nothing about how the client renders tools.

    Add this WordPress site to the fleet this hub manages.

        Site URL:     https://example.com
        Security ID:  a7f3k9x2m1qp

    Call your "fleet/add-site" tool with site_url and security_id set to the two values
    above. Some clients list it as "fleet-add-site".

    Call it first without confirming. It will change nothing and reply with the name of the
    site it is about to add — show me that name and wait for my yes. Then call it again with
    confirmed set to true.

    When it succeeds, tell me the site name and site key it returns.

    If you have no such tool, stop and tell me. It means this conversation is connected to
    the wrong WordPress site: the tool lives on the hub that will manage this site, not on
    this site itself.

### The removal prompt

Same six rules below. Shorter, because there is no security ID to carry — the hub already knows the site.

    Remove this WordPress site from the fleet this hub manages.

        Site URL: https://example.com

    Call your "fleet/remove-site" tool with site set to the URL above. Some clients list it
    as "fleet-remove-site".

    Call it first without confirming. It will change nothing and reply with the name of the
    site it is about to remove — show me that name and wait for my yes. Then call it again
    with confirmed set to true.

    This stops the hub managing the site. It does not revoke the hub's access on the site
    itself — say so when you report back, and tell me that is done from that site's own
    Fleet settings.

    If you have no such tool, stop and tell me. It means this conversation is connected to
    the wrong WordPress site.

The last paragraph is not boilerplate. Without it a model will report "removed" and the operator
will reasonably believe access is gone, when the site still holds the hub's public key. Making
the model state the limit is cheaper and more reliable than hoping the operator reads the docs.

Six properties make both prompts portable, each deliberate:

1. **No vendor, app or file name.** Nothing to go stale, nothing that reads as wrong to a user
   on a different client.
2. **Both tool spellings.** Clients normalise `/` differently; naming both removes a guess.
3. **The confirm handshake is spelled out.** A weaker model will not infer a two-call protocol
   from a schema field. Stating it in prose is what makes `confirmed` work across models rather
   than only in ones that read schemas carefully.
4. **Field names, not prose descriptions.** `site_url` and `security_id` verbatim, so a model
   that cannot see the schema still fills them correctly.
5. **A required read-back.** The operator gets a value to check — the difference between "it
   said it worked" and evidence that it did.
6. **A named failure mode.** The wrong-chat case is the one real footgun and it otherwise fails
   looking like a broken feature. Telling the model to stop and say so converts it into a
   sentence the operator can act on.

**Paste it into the hub's chat, not this site's.** The child's own MCP server has no fleet tools
at all. The UI copy has to say which chat in the block itself, not in a tooltip — point 6 is the
in-prompt backstop for when someone gets it wrong anyway.

Each block is gated on its own precondition, and neither is cosmetic. The **add** prompt renders
only when fleet access is ON: `Register` refuses registration against a site that has not opted
in, so offering it while off is an instruction that cannot succeed. The **remove** prompt renders
only when `Hosts::all( $server_id )` is non-empty — there is nothing to remove from a site no hub
has paired with, and showing it would imply a relationship that does not exist.

## The Fleet tab redesign (child)

Decision: **prompt first, ID as the fallback.** The #220 three-card Claude Design layout stays as
the shell; what changes is the pairing card.

- The paste-able prompt becomes the primary action: the block, a Copy button, and one line naming
  which chat to paste it into.
- The raw security ID and the by-hand instructions move into a secondary "Pair by hand" section —
  present and complete, demoted, not removed. Same relationship Feature 099 established between
  its generated prompt and its manual steps.
- "Rotate security ID" sits with the ID, wherever the ID appears.

## Security: the ID is a credential, and a prompt moves it

Flagged because it is a genuine change in exposure, not a theoretical one.

The security ID authenticates the register call. Anyone holding it, against a site with fleet
access on, can register themselves as a host. Typing it into an admin form keeps it between two
browsers; putting it in a pasted prompt sends it through a chat client and to a model provider,
where it may be retained.

Two mitigations, both already available:

- `SecurityId::regenerate( int $server_id )` exists at `includes/Fleet/SecurityId.php:110`. The
  tab should offer rotation next to the ID, and the pairing card should recommend rotating once
  pairing is confirmed. A rotated ID does not disturb an established pairing — the host key, not
  the security ID, authorises later calls.
- The `confirmed` gate above means a leaked ID alone does not silently add a host through this
  path; a person still approves the named site.

Worth deciding explicitly (see open questions): whether to make the ID single-use.

## Tasks

**Pro (hub)** — done in acrossai-pro#132, and overtaken: the dispatcher was split up entirely
rather than merely gaining two siblings.

- ~~**TASK-1**~~ ✅ — `includes/Fleet/Add_Site_Ability.php`: register `fleet/add-site` against
  `Ability::CATEGORY` and `Ability::TAB_GROUP`, wrapping `Pairing::connect()`. Honour `confirmed`
  and `can_dispatch()`; pass `WP_Error` codes through; `mcp.public => false`; annotations
  `readonly:false, destructive:false, idempotent:true`.
- ~~**TASK-2**~~ ✅ — `includes/Fleet/Remove_Site_Ability.php`: register `fleet/remove-site` the same
  way, wrapping `Roster::remove()`. `site` resolves a roster key OR a URL via `Roster::all()`.
  Annotations `readonly:false, destructive:true, idempotent:true`. The success payload states
  that the site still holds the hub's key.
- ~~**TASK-3**~~ ✅ — Wire both slugs into BOTH `acrossai_mcp_manager_tool_abilities` and
  `acrossai_abilities_manager_groupable_protected_slugs` in `Main.php`. Missing the first makes an
  ability uncallable; missing the second drops it off the Fleet tab.
- ~~**TASK-4**~~ ✅ — Tests: an unconfirmed call of each names the target and writes nothing; every
  `Pairing::connect()` failure mode surfaces distinctly; `remove-site` resolves by key and by URL
  and is a no-op for an unknown site; `can_dispatch() === false` refuses both; the Fleet tab holds
  three rows.

**mcp-manager (child)**

- **TASK-5** — Build both prompt strings from this server's URL, `SecurityId::get()` and
  `Hosts::all()`. Gate each on its own precondition (fleet ON / at least one paired host).
- **TASK-6** — Rebuild the pairing card: add-prompt first with a Copy button and the which-chat
  line, remove-prompt beside the paired-host list, by-hand section demoted, rotate control beside
  the ID.
- **TASK-7** — Tests: add prompt absent when fleet is off; remove prompt absent with no hosts;
  prompts carry the current ID and URL; regenerating the ID changes the add prompt.

**Both**

- **TASK-8** — End to end on sandboxes: pair a child from a hub chat, confirm the roster row,
  rotate the ID, confirm the pairing survives, then remove the site from the same chat and
  confirm the hub's roster is empty while the child still lists the host.

### Quality gates

PHPCS, PHPStan L8 (clear the result cache first), PHPUnit pure + integration, JS lint, PHP 8.1
compat, package hierarchy, F021 grep gates. The child half touches SCSS, so the bundle must be
rebuilt and committed or the stale-bundle gate fails.

## Open questions

1. **Single-use security ID?** Auto-rotating on successful registration would make a leaked
   prompt worthless after first use. It also breaks re-pairing after a hub is removed, which
   today is just "paste it again". Not decided.
2. **Does the hub need the child's name up front?** `Pairing::connect()` learns it from the
   register response. Including it in the prompt would let the model say "pairing *Mumbai
   Community*…" before the call rather than after. Cosmetic, cheap, unconfirmed.
3. **A revoke route on the child (follow-up, not this feature).** Removal is one-sided because
   the child has no counterpart to `Register`. A signed `POST .../fleet/revoke` — authenticated
   by the host key the hub already holds, which `RoutePermission` already knows how to verify —
   would let `fleet/remove-site` clear both sides in one call. It is a genuine new public surface
   on the child, so it wants its own planning pass rather than a rider on this one. Until then
   TASK-2's copy is what keeps the limitation honest.
4. **Copy must say "this server", not "this site".** Verified rather than open: the ID is server
   meta (`SecurityId::get()` reads `MCPServerMetaQuery::get_meta( $server_id, META_KEY )`), and
   the tab only renders for a server whose type is `ServerTypes::FLEET`
   (`FleetTab.php:103`). So the prompt is naturally per-server and the gate is
   `$server['is_enabled']` — but a site can hold several fleet servers, each with its own ID, and
   wording that says "add this site" would be wrong in exactly that case.
