<?php

use App\Sso\CentralProvisioner;

/*
 * Dot.Central's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env.
 */
return [
    'provisioner' => CentralProvisioner::class,

    // Central has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link may open any signed-in page of the control room, and nothing else. The Mines webhook, the guardian
    // report and the token API are machine doors and never a deep link target.
    'deep_link' => [
        'allow' => ['#^/(dashboard|search|agent-hub|notifications|audit|exceptions|approvals|quality|health|control-rooms|shifts|agents|agent-knowledge|alerts|reports|user/profile|teams|team-invitations)(/|\?|$)#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|webhooks|guardian|api|up)(/|\?|$)#i'],
    ],

    // /webhooks/mines/{connection} (HMAC), /guardian/health (bearer), /up*, /api/user (Sanctum) are not login pages and
    // the package never touches them.
    'allow_paths' => [],
];
