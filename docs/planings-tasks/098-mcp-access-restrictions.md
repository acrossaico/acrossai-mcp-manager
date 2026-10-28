# Planning: MCP Access Restrictions — IP Allowlist and Pause All AI Access (Feature 098)

> **Status:** Planned · **Date:** 2026-10-26 ·
> **Owner plugin:** `acrossai-mcp-manager`
> **Depends on:** [096 — MCP Activity Log](096-mcp-activity-log.md) — a refusal that
> is not recorded is indistinguishable from a request that never arrived.
>
> **Tracked as:** [#189 Pause All AI Access](https://github.com/acrossaico/acrossai-mcp-manager/issues/189)
> (phase 1) and [#190 IP allowlist](https://github.com/acrossaico/acrossai-mcp-manager/issues/190)
> (phase 2). Either ships without the other.

## In plain English

Two ways to say "no" that the plugin cannot say today.

**Pause All AI Access** is one switch that makes every tool call fail while leaving
everything else alone. Connected assistants stay signed in, their tokens stay valid, the
servers stay registered — they just cannot *do* anything until you switch it back. It is
the thing you reach for when something looks wrong and you want it to stop **now**,
without unpicking connections you will want back in ten minutes.

**IP Allowlist** restricts which addresses may reach your MCP servers at all. Give it a
list of addresses or CIDR ranges — IPv4 and IPv6 — and anything from outside is refused
before it runs.

Both refusals are **recorded in the activity log**, which is the point. A tool call that
silently does nothing is a support ticket; a tool call that was refused, with the reason
and the address it came from, is an answer.

## Neither of these exists today — verified, not assumed

The brief assumed Pause already existed. It does not, and the closest thing behaves
differently in the ways that matter.

| | What exists | Why it is not Pause |
|---|---|---|
| Per-server `is_enabled` | A column on `wp_acrossai_mcp_servers`; `MCP\Controller` skips disabled rows when registering | It **unregisters the server**. The endpoint stops existing, so a client gets a transport-level failure rather than a clean refusal, nothing is logged (there is no server to log it), and it is per server rather than one switch |
| `*_kill_switch` greps | `acrossai_mcp_manager_cimd_enabled`, `acrossai_disable_ability_suggestions` | Feature-specific filters for the CIMD trust path and ability suggestions. Not global, not user-facing |
| Global on/off option | **None.** Every `acrossai_mcp_*enable*` option found is feature-scoped | — |

For IP: no CIDR handling anywhere. `OAuth\Security\RateLimiter` reads an address, but for
throttling, not authorisation.

## The constraint that shapes the IP design

**IP rules cannot be an Access Control provider.** The vendor contract
(`vendor/wpboilerplate/wpb-access-control/src/AbstractProvider.php`) is:

```php
public function user_has_access( int $user_id, array $selected_options ): bool
```

A provider receives a user id and the rule's selected options. It receives **no request
context** — no address, no headers, nothing about where the call came from. An IP check
is not a statement about *who* is asking; it is a statement about *where from*. The
signature cannot express it, and widening a vendor interface to carry request state would
be the wrong fix.

So IP gating gets its **own seam**, sitting beside Access Control rather than inside it.
Order matters and must be decided: IP is a cheaper check than a capability lookup and
rejects more traffic, so it should run **first**.

## The seam

Both features refuse at the same place, which is the main argument for shipping them as
one feature rather than two: otherwise the refusal-and-record path gets built twice.

Existing gate points to follow, not reinvent:

- `includes/MCP/ToolExposureGate.php::gate_tool_call_by_curation()`
- `includes/MCP/AbilityExposureGate.php::gate_tool_call_by_exposure()`

Both already intercept a tool call and can refuse it. A third gate joins them, running
**before** both — there is no point consulting curation or exposure for a call that is
not allowed to happen at all.

The activity log already carries what is needed: `status` is
`success | error | denied | invalid_input`, and `denied` is already written by
`AbilityActivityRecorder`. A refusal is a `denied` row, not a new event type.

## Open questions, to settle before implementation

1. **Scope of the IP allowlist — global, or per server?** Per server matches how every
   other restriction in this plugin works (Access Control, tool curation, ability
   exposure). Global is simpler and is what the brief implies. A per-server list with a
   global default is a third option and probably the honest answer, but it is also the
   most work.
2. **Empty list means what?** An empty allowlist MUST mean "allow everything", not "allow
   nothing" — the opposite would lock out every existing install on upgrade. This needs
   stating as a requirement, not left to the implementation.
3. **Which address does the plugin trust?** `REMOTE_ADDR` is the only one that cannot be
   spoofed, but it is the proxy's address on any site behind Cloudflare, a load balancer
   or a host CDN — where it would refuse everything. `X-Forwarded-For` is trivially
   forged unless the proxy is known. This is the single most dangerous detail in the
   feature: get it wrong and the allowlist either locks out the legitimate operator or
   can be bypassed by setting a header. Needs an explicit decision, a documented default,
   and a filter.
4. **Does Pause cover the whole site or one server?** The brief says "all", and one
   switch is the point — a pause you have to apply eleven times is not an emergency
   control.
5. **Does Pause refuse reads as well as writes?** "Every tool call" is the brief's
   wording and is the safer reading. A "pause writes only" variant is a different,
   larger feature and should not be smuggled in.
6. **What does a paused server tell the client?** A clear protocol-level error naming the
   pause beats a generic failure: the operator sees it in the assistant's own output and
   does not file a bug.

## Admin surface

The brief asks that admins **know these exist**, which is as much of the work as the
gate itself. A kill switch nobody can find is not a kill switch.

- **Pause** belongs somewhere unmissable and reversible in one click — the MCP settings
  tab at minimum, and worth considering an admin-bar indicator while paused, because the
  failure mode of this feature is *forgetting it is on* and spending an afternoon
  debugging a connection that is working perfectly.
- **IP allowlist** sits with the other per-server restrictions if it is per server, or on
  the MCP settings tab if global.
- Both need the activity log to show *why* something was refused, not merely that it was.

## Phases

1. **Pause All AI Access** — one option, one gate, a `denied` row per refused call, the
   settings control and a visible "paused" indicator. Shippable alone and useful alone.
2. **IP allowlist** — CIDR parsing for IPv4 and IPv6, the trusted-address decision from
   Q3, the storage decision from Q1, and the same refusal-and-record path.
3. **Surfacing** — readme, changelog, and whatever the activity-log screen needs to make
   a refusal readable at a glance.

Phase 1 first because it is smaller, answers fewer open questions, and is the one an
operator reaches for in an emergency.

## Why one feature, not two

They share the gate, the refusal semantics, the `denied` recording path and the settings
area. Built separately, the second one either duplicates that work or refactors the
first. The user stories stay independent — either phase can ship without the other.
