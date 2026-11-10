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

So the hub is always the caller. Any "add a site" ability therefore belongs on the hub, not here.

## The one call that makes this cheap

The entire hub-side handshake is already a single public method:

    // acrossai-pro/includes/Fleet/Pairing.php:78
    public static function connect( string $site_url, string $security_id )

Checked for UI coupling: no `current_user_can`, no `$_POST`, no `check_admin_referer`, no
`wp_die`. It generates the keypair, posts to the child, stores the roster row, and returns either
the site array or a `WP_Error`.

**No refactor is needed.** The new ability is a wrapper.

## The split across two repos

`toolset/fleet` already exists — in Pro, at `acrossai-pro/includes/Fleet/Ability.php:71`. Its
actions are `sites`, `tools`, `call`, `ask`: all of them operate a fleet that already exists.
There is no action that *creates* a member, and that is the gap.

| Repo | Half | Work |
| --- | --- | --- |
| `acrossai-pro` | hub | A `connect` action on `toolset/fleet`, wrapping `Pairing::connect()` |
| `acrossai-mcp-manager` | child | Fleet tab redesign: prompt first, security ID as the fallback |

The child cannot host the ability: it has no keypair, no roster, and is not the party doing the
registering.

## The ability contract (Pro)

Extend the existing `action` enum rather than registering a second ability — a caller already
knows this toolset's three verbs, and pairing is the natural fourth.

    action: "connect"
      site_url     string, required  — the child site's address
      security_id  string, required  — twelve characters from that site's Fleet tab
      confirmed    bool              — required before anything is written

Returns the roster entry — site key and label — so the model can go straight to `tools` and
`call` against the site it just added.

Three constraints, each with a reason:

1. **`confirmed` is mandatory.** Pairing grants another site standing control over this one. The
   toolset already uses this pattern for anything that changes a site; an unconfirmed call must
   name the site and stop. Calling without it is how the operator sees what they are approving.
2. **`can_dispatch()` still gates it.** `Ability::CAP_FILTER` (`acrossai_pro_fleet_can_dispatch`)
   governs the existing actions and must govern this one. Pairing is strictly more sensitive than
   `sites`, so it cannot be the one action that skips the gate.
3. **`WP_Error` passes through unflattened.** `Pairing::connect()` distinguishes "fleet is off on
   that site", "wrong security ID", "unreachable", and "already paired". Collapsing those into one
   failure string would make the prompt path unsupportable — the whole value is the model being
   able to say which of the four went wrong.

## The prompt (child)

Short, because there is nothing to describe but one call:

    Please add this WordPress site to your fleet.

    - Site URL: https://example.com
    - Security ID: a7f3k9x2m1qp

    Use the fleet toolset's "connect" action with that URL and security ID. Tell me the site
    name it reports back once it is paired.

**Paste it into an AI connected to your hub — not to this site.** This is the one real footgun:
the child's own MCP server has no fleet toolset, so pasting it in the wrong chat fails in a way
that reads like a broken feature. The UI copy has to say which chat, in the block itself, not in
a tooltip.

Render the block only when fleet access is ON. `Register` refuses registration against a site that
has not opted in, so offering the prompt while it is off is an instruction that cannot succeed.

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

- **TASK-1 (Pro)** — Add `connect` to the `action` enum and `input_schema`; add
  `Ability::connect_site()` wrapping `Pairing::connect()`; honour `confirmed` and `can_dispatch()`;
  pass `WP_Error` codes through.
- **TASK-2 (Pro)** — Tests: unconfirmed call names the site and writes nothing; each of the four
  `Pairing::connect()` failure modes surfaces distinctly; `can_dispatch() === false` refuses.
- **TASK-3 (child)** — Build the prompt string from this site's URL + `SecurityId::get()`. Render
  only when fleet access is on.
- **TASK-4 (child)** — Rebuild the pairing card: prompt first, Copy button, which-chat line,
  by-hand section demoted, rotate control beside the ID.
- **TASK-5 (child)** — Tests: prompt absent when fleet is off; prompt carries the current ID;
  regenerating the ID changes the prompt.
- **TASK-6** — End to end: pair a sandbox child from a hub chat, confirm the roster row, rotate
  the ID, confirm the pairing still works.

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
3. **Copy must say "this server", not "this site".** Verified rather than open: the ID is server
   meta (`SecurityId::get()` reads `MCPServerMetaQuery::get_meta( $server_id, META_KEY )`), and
   the tab only renders for a server whose type is `ServerTypes::FLEET`
   (`FleetTab.php:103`). So the prompt is naturally per-server and the gate is
   `$server['is_enabled']` — but a site can hold several fleet servers, each with its own ID, and
   wording that says "add this site" would be wrong in exactly that case.
