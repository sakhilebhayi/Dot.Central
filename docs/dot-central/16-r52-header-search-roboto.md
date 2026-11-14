# R-52: shared header, real search, Roboto, mobile (Dot.Central)

Scope: typography, the top bar, search, the phone menu and the audited responsive defects. Not a redesign: routes, policies, tenant
scoping, the entitlement middleware and the R-51 Overview are unchanged.

## The header and the R-51 state bar: one bar, not two

R-51 (docs: `r51/dashboards/central/DESIGN.md`, section 7) already said the state bar becomes Central's header region. R-52 does
exactly that with the shared dot-ui header (`<x-dot.header>`), so the result is ONE bar, 56px on a wide screen, 52px on a phone:

| Region | Content | Notes |
|---|---|---|
| start | original logo (night/day), "Dot.Central", then the state: lamp, state word, counts | the state keeps `statebar statebar--ok|warn|bad|idle`, `role="status"`, and links to the control rooms when it is not "ALL QUIET" |
| centre | the search pill (`<x-dot.search>`), centred by the header grid | 280px at least, 28vw up to 560px (the package default is 32vw up to 640px: the state needs the room) |
| end | Dot Apps switcher, night/day toggle, notifications bell, account menu | the R-51 bar's tools bay, now the header's `tools` slot |

What the state shows adapts to the room the start column has (a container query, not a viewport guess): the word always, the counts
("3 critical · 6 open") from about 440px of start column, the full R-51 sentence from about 600px. The word has a short form under
400px ("ATTENTION", "NO ROOM") so it is never cut off.

Kept from R-51: the foot (shift, Dot.Mines link, UTC clock), the Overview as the one scrolling region, `--bar-h` (now
`var(--dot-header-offset)`, so the Overview's `calc(100dvh - var(--bar-h) - var(--foot-h))` still holds), `--foot-h` (45px on a phone so the
items are 44px), the sidebar and its order. Changed: the sidebar starts under the header, its brand block (logo, name) moved to the header
and is only shown inside the phone drawer, and the Overview page title that the R-51 bar printed is gone (the page's own headline, and the active
sidebar item, say where you are).

Account: on a wide screen Notifications, Profile & settings, Team & roles and Sign out are in the header's account menu, and the sidebar's copies are
hidden (no duplicates). On a phone the account menu is not in the bar, so the sidebar (the drawer) carries them, plus the night/day switch
under 480px where the bar has no room for it.

## Phone menu

`drawer-target="dot-sidebar"` (dot-ui adopt mode): the menu button opens the platform's own sidebar. dot-ui sets `data-dh-open` on it,
draws the scrim on `<body>` (so the transformed sidebar can no longer shrink it to nothing: the audited defect), locks the scroll, traps focus,
closes on Escape, scrim tap, the close button and a link tap, and returns focus. The sidebar is `visibility: hidden` while closed, `100dvh`,
rows 44px.

## Search

`App\Support\Search\CentralSearch`, shown by the Livewire component `HeaderSearch` (dropdown) and by `/search?q=` (full page, GET, works without
JavaScript). Scout is not installed, so it is a parameterised, lower-cased LIKE over stored columns; every word must match somewhere in the
row; each group is capped (4 in the dropdown, 25 on the page); one character is not a search.

| Group | Matches | Scope |
|---|---|---|
| Control rooms | name, slug | `ControlRoom::accessibleBy` (team, narrowed to assigned rooms for operators and supervisors) |
| Equipment | name, type, model, manufacturer, serial, registration, area | the person's rooms |
| Alerts | title, description, source | the person's rooms |
| Exceptions | title, message, rule code, group | the person's rooms |
| Shifts | date, status, shift type | the person's rooms |
| Operators | name, employee number, tag | only rooms the person MANAGES (the register is an administrator page; personal data) |
| Agents | name, description | the current team |
| Go to | the sidebar's pages | minus Audit log and Health when not permitted |

Where a row opens: control room, equipment and shift have their own pages; alerts and exceptions have no detail page, so they open the
existing list filtered to that room and status; operators open the room's administration page.

## Roboto

Roboto (self-hosted, dot-ui) replaces IBM Plex Sans, Plex Sans Condensed and Plex Mono, and Archivo and Big Shoulders in dot-ui's pill and
switcher. Kept: the system monospace stack (`--dot-font-mono`) for readings, codes, counts, the clock and tabular data, as the brief allows; the
Material Symbols Rounded icon font, now self-hosted as a 6 kB subset of the 28 icons the product draws (`resources/fonts/`, built by Vite)
instead of a Google CSS link. No page links a font host any more (app, sign-in, welcome and error pages).
