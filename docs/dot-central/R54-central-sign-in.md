# Central sign-in through InfoDot (R-54), off until the owner turns it on

THIS REPOSITORY DEPLOYS ON MERGE. Everything below is behind `DOTSSO_ENABLED` (default false), so a merge changes
nothing a visitor can see, except that "Create an account" no longer lands on /login.

The shared client package (`packages/dot-sso-client`, copied from InfoDot by `sync.sh`) does nothing while the flag is
false. The R-52 receiver (`POST /auth/ecosystem`, ten reason codes, the 503 operator page, `php artisan sso:check`, the
email lock, `users.infodot_user_uuid`) keeps working as the fallback, and stays when the flag is on.

1. Deploy (migrations add `dotsso_revocations` and `dotsso_claims`; `users.infodot_user_uuid` already exists).
2. On the InfoDot host: `php artisan sso:platform register central --name="Dot.Central" --domain=https://central.infodot.co.za --redirect=https://central.infodot.co.za/sso/callback --post-logout=https://central.infodot.co.za/ --backchannel=https://central.infodot.co.za/sso/backchannel-logout` (the secret is printed once).
3. In this app's `.env`: `DOTSSO_ISSUER=https://infodot.co.za`, `DOTSSO_CLIENT_ID`, `DOTSSO_CLIENT_SECRET`, `SESSION_SECURE_COOKIE=true`, then `DOTSSO_ENABLED=true`, then `php artisan config:cache`.
4. Check: sign in from the landing page, create an account, a second visit, sign out at InfoDot, one API call, `php artisan sso:check`. Rollback: `DOTSSO_ENABLED=false`. Emergency local sign-in while enabled: `DOTSSO_ALLOW_LOCAL_LOGIN=true`.

An existing local account that was never email-verified is no longer taken over: the person confirms the address with an
emailed code (claim flow) and nothing is replaced or detached.
