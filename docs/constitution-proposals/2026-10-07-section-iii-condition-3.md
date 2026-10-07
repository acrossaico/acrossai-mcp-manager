# Constitution Amendment Proposal — §III consent-surface exception, condition 3

**Status**: PROPOSED — awaiting approval. Not applied.
**Raised**: 2026-10-07, by `/speckit-security-review-branch` finding TASK-SEC-002 (T101).
**Bump type**: **MINOR** → v1.2.0. *Not* PATCH: this narrows the applicability of a
condition inside a NON-NEGOTIABLE principle, which is semantic, not editorial.
It was deliberately excluded from the v1.1.1 PATCH for that reason.

## 1. The conflict

§III's consent-surface exception requires such a surface to:

> 3. Be operator-gated via a default-OFF option (e.g. `acrossai_mcp_npm_login_enabled`),
>    so the surface does not exist on a fresh install without explicit operator opt-in;

F095's OAuth consent screen (`/authorize`, `templates/oauth/consent.php`) is a
browser-mediated consent surface where a logged-in user consents on their own
behalf — squarely within the exception. But it ships **ON by default**, a
deliberate product decision recorded as C5 in `specs/095-oauth-migration/plan.md`:
a connector capability behind a default-OFF switch is a capability most operators
never discover.

So the constitution and the shipped behaviour disagree. One of them must move.

## 2. Why condition 3 is the right thing to move

Conditions 1, 2, 4 and 5 are about *containing* the credential. Condition 3 is
different: it is about *reducing attack surface on installs that never wanted the
feature*. That rationale is strong when the surface can mint a credential
exceeding the consenting user's own authority — the `npm_login` case it was
written for. It is weak when the surface can only ever bind to the consenting
user's own account, because then "a fresh install has this surface" grants nobody
anything they did not already have.

The OAuth consent screen is the second kind, and I verified the full gate chain
rather than assuming it:

- `is_user_logged_in()` — `AuthorizationController.php:115`
- per-server connector enablement — `:170`
- per-server user approval, with a `manage_options` auto-approve carve-out — `:192`
- nonce on the consent POST — `:269`
- token bound to the consenting user's own `user_id`

## 3. Proposed text

Replace condition 3 with:

> 3. Be operator-gated via a default-OFF option (e.g. `acrossai_mcp_npm_login_enabled`)
>    **whenever the surface can issue a credential exceeding the consenting user's own
>    authority**, so the surface does not exist on a fresh install without explicit
>    operator opt-in. A surface that can only ever bind a credential to the consenting
>    user's own account, and that is already gated by conditions 1 and 2, MAY ship
>    enabled — but MUST still be disableable by the operator, and MUST state in its
>    class docblock why condition 3 does not apply to it.

## 4. What this does NOT do

- It does not weaken conditions 1, 2, 4 or 5.
- It does not exempt `FrontendAuth.php`. The npm login flow issues an Application
  Password usable beyond the consenting session, so condition 3 still binds there
  in full — the canonical instance is unaffected.
- It does not make any surface ungateable: the carve-out still requires an operator
  off-switch, only not a default-OFF one.

## 5. If rejected

The alternative is to make OAuth connectors default-OFF, reversing C5. That is a
product decision, not a security one, and would mean the plugin's headline free
capability is invisible until an operator finds a switch. Until either path is
taken, the consent screen is a standing §III deviation and must carry documented
justification in the feature plan per §Governance — C5 already provides it.

## 6. Procedure if approved

Per §Governance: bump 1.1.1 → 1.2.0, update `Last Amended`, rewrite the sync
impact report, review all three templates, and commit as
`docs: amend constitution to v1.2.0 (scope §III consent-surface condition 3)`.
