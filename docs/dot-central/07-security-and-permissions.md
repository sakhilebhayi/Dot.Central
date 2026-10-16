# Dot.Central: Security and Permissions Specification

## 1. Identity and tenancy
- Authentication: Laravel Fortify/Jetstream (password, 2FA) plus ecosystem SSO handoff from the InfoDot hub (short-lived Sanctum token with `ecosystem:read`, consumed once). The handoff requires the hub and Dot.Central to share `users`/`personal_access_tokens`; where they do not, SSO is unavailable and local login applies. Hardening planned: reject the handoff if the token's user lacks any team; add a feature test.
- Organisation = Jetstream team. Site = control room. Users are assigned to control rooms (`control_room_user`). Managers and administrators of a team see all its control rooms.
- Tenant isolation: existing global scopes stay; every new table carries `control_room_id` (or `team_id` for catalogues) and is covered by a policy; console/queue code resolves the control room explicitly and never relies on `Auth`.

## 2. Roles (Jetstream team roles)

| Capability | Operator | Supervisor | Manager | Administrator |
|---|:-:|:-:|:-:|:-:|
| View overview, fleet, map, equipment, alerts, reports (assigned rooms) | yes | yes | yes (all rooms) | yes (all rooms) |
| Acknowledge / assign / resolve alerts; explain exceptions | yes | yes | no | no |
| Escalate alerts; approve/reject/dismiss exceptions; resolve conflicts | no | yes | yes (conflicts only) | no |
| Start / review / close shift; issue and acknowledge handover | yes | yes | no | no |
| Approve / reopen shift | no | yes | no | yes (reopen only, logged) |
| Record incidents, tasks, manual interventions (with reason) | yes | yes | no | no |
| Export reports / CSV | no | yes | yes | yes |
| View audit log | no | yes | yes | yes |
| Manage control rooms, Dot.Mines connections, catalogues, thresholds, shift types | no | no | no | yes (audited) |
| Manage users, roles, room assignments | no | no | no | yes |
| Acknowledge/resolve an alert *back to Dot.Mines* | no | yes | no | no |

Enforcement: Laravel policies per model + `Gate` abilities named after the capability; every controller/Livewire action calls `authorize`; denied attempts are audited. Jetstream `admin`/`editor` roles are migrated to `administrator`/`operator`.

## 3. Credentials and secrets
- Dot.Mines tokens and webhook secrets stored with Laravel `encrypted` casts; shown once; never logged, never in Blade, never in `/sync` responses; rotated by saving a new one (old overwritten, audit row without the value).
- No credentials in source control; `.env.example` lists key names only.
- Outbound HTTP: 10 s timeout, no redirects, TLS verification on.

## 4. Input validation and data integrity
- Form requests for every write; enum-backed statuses; timestamps validated to lie within the shift window where relevant; numeric ranges checked (hours ≥ 0, non-decreasing engine hours unless `meter_replaced` approval).
- Webhooks: HMAC verification, 5-minute skew tolerance, delivery-id replay protection, body size limit.
- Idempotency keys on mirrored rows (`external_id`, `sync_version`, delivery id).

## 5. Audit
Append-only `audit_log` (no update/delete path in the application; Postgres grant hardening documented for deployment). One row per field change with user, role, control room, shift, old/new value, reason, approver, `automatic` flag.

## 6. Sessions and errors
Jetstream session management; `AuthenticateSession` on; CSRF on web; `verified` middleware retained but `User` does not implement `MustVerifyEmail` (documented, unchanged). Error pages branded; exceptions logged without secrets.

## 7. Open security items
See `10-outstanding-issues.md`: ecosystem service accounts; SSO token in query string; shared-database assumption for SSO.
