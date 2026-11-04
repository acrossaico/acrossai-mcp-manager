# Planning: Consultation Doors (Feature 102)

**Status: implemented on acrossai.co, 2026-11-04.** The plugin-side change is in this repo and
uncommitted at time of writing; everything else is live.

## In plain English

`/consultations/` used to be one thing: a cropped Google Calendar iframe for the Pro booking page,
with no explanation of what you were booking. It is now **two doors behind one page**:

| URL | Renders |
|---|---|
| `/consultations/?type=paid` | Google Calendar appointment embed |
| `/consultations/` | the same thing — the bare URL is an alias |
| `/consultations/?type=free` | a Fluent Forms request, answered by hand |

Only the exact string `free` opens the free door. `paid`, an empty `?type=`, a misspelling, and a
parameter stripped by a cache or a link shortener all resolve to paid — **the request fails toward
the page that asks for a licence, never toward the one that gives time away.**

## What replaced the original plan

The first version of this doc proposed making consultations Pro-only: one required licence question
on the Google booking form, and the page removed from menus, search and the sitemap.

That is **superseded**. The licence question shipped and stays, but the page is public again,
because the free door now lives on it. Nothing about hiding the page from menus, search or the
sitemap applies any more — do not implement it.

The earlier Stripe/two-schedule idea stays shelved. Google Workspace only allows one appointment
schedule on this plan (verified in the Calendar UI: *"Creating different types of appointments is
not included in your subscription"*), which is exactly why the free tier is a form rather than a
second calendar.

## Two premises in the first draft were wrong

Worth recording, because both were stated confidently and both cost time:

1. **"Fluent Forms is not installed."** It is — v6.2.17, active since August. The mistake was
   reading `toolset/integrations` as a list of *active plugins*; it lists only plugins that expose
   AcrossAI ability cards. Fluent Forms has no card, so it was absent from that list while being
   perfectly active. Its abilities live in `toolset/other`.
2. **"Reusable block 139 references a colour that does not exist."** The palette does define the
   slug as `line` rather than `border`, but the child theme already maps the alias site-wide in
   the last block of `functions.php`. Those borders render fine.

## What shipped

### acrossai.co

| File | What |
|---|---|
| `inc/consultations.php` (new) | `[aa_consultation_door]` shortcode, door selector, cache suppression, canonical, submit-button label filter |
| `assets/consultations.css` (new) | Hero, meta row, iframe crop, licence note, Pro note, two Fluent Forms fixes |
| `functions.php` | One `require_once` appended, matching the existing `inc/` pattern |
| Page 168 | Shortcode + editable shared sections ("what we can cover", host card) + the existing connectors block, unchanged |
| Fluent Forms #6 (new) | `AcrossAI — Free consultation request` |
| LiteSpeed | `/consultations/` added to the URI exclusion list |

The Fluent Forms skin was **not** rewritten — `assets/contact-support.css` already skins
`.aa-formcard`, and the free door reuses it.

### Form #6 fields

`first_name`, `last_name`, `email` (all required) · `website_url` (required, URL-validated) ·
`discuss` (required textarea) · `using_products` (optional dropdown: Both / MCP Manager /
Abilities Manager / Neither yet) · `page_url` (hidden, `{embed_post.permalink}`).

Two notifications: one to `deepak@acrossai.co` with `reply_to` set to the requester, and an
auto-reply promising a time within two working days and pointing at `?type=paid` for anyone who
needs it sooner.

### This repo

`src/js/quick-connect/StepLayout.jsx:185` — `'Free Consultations ↗'` → `'Consultations ↗'`. The
old label promised every free user something free and then sent them to a licence wall. The link
still points at `/consultations/`; the page's own cross-door line routes non-customers.

The link **cannot** be gated by licence: the Freemius SDK was retired in F028 and the last gated
closure dropped in F095, so this plugin holds no licence state at all.

Rebuild and commit `build/js/quick-connect.js` in the same commit — CI fails a stale bundle
(`6fd1976`).

## Three bugs found while building

1. **Fluent Forms' screen-reader form title rendered visibly**, as a stray duplicate heading above
   the first field. It is a `<legend class="ff_screen_reader_title">` that nothing in this theme
   hides. Now clipped rather than `display:none`, so assistive tech still announces it.
2. **The submit button ignored the brand colour.** Fluent injects
   `form.fluent_form_6 .ff-btn-submit:not(.ff_btn_no_style)` at (0,3,1); the existing skin rule is
   (0,3,0) and lost. Note that `.fluentform` sits on Fluent's **wrapper div** — the `<form>` carries
   `.frm-fluent-form` — so a selector written `form.fluentform` matches nothing.
3. **The iframe crop sliced a line of Google's text in half.** 170px cut through "Google Meet video
   conference info added after booking". Now 205px, with the view reduced by the same 35px so
   `crop + view` still equals the iframe's own `height` attribute and no white gap opens below.

**(1) and (2) almost certainly affect `/contact/` and `/support/` too** — same skin, same Fluent
version. Deliberately not changed here; fix them when you next touch those pages.

## Left undone

- The Fluent Forms **form title** is still `AcrossAI — Free consultation request` in the admin
  list. That is fine; it is hidden on the front end.
- `/contact/` and `/support/` carry bugs 1 and 2 above, untouched.
- Mobile iframe crop (`245px / 1115px`) was derived by applying the same +35px shift as desktop.
  **Not verified on a real phone** — check it before you rely on it.
- Nothing was committed in this repo.

## Still worth deciding

Do **expired** and **trial** licences qualify for a paid-door booking? F074 puts people on free
trials straight from the wizard, so this will come up. Whatever you decide, put it in the page copy
and the Google booking form's helper text.
