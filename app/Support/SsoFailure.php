<?php

namespace App\Support;

use RuntimeException;

/**
 * Why a sign-in from InfoDot did not complete. The reason is a short code that is safe to log and to show: it never
 * carries the token, the secret or anything about the person.
 */
class SsoFailure extends RuntimeException
{
    /** Host settings are wrong: nobody can sign in until an operator fixes them. */
    public const NOT_CONFIGURED = 'not_configured';

    public const SECRET_REJECTED = 'secret_rejected';

    public const REDEEM_HOST_MISMATCH = 'redeem_host_mismatch';

    /** The browser, token or account is the problem, not the host. */
    public const ORIGIN_REJECTED = 'origin_rejected';

    public const TOKEN_MISSING = 'token_missing';

    public const TOKEN_REJECTED = 'token_rejected';

    public const INFODOT_UNAVAILABLE = 'infodot_unavailable';

    public const BAD_ANSWER = 'bad_answer';

    public const NO_ACCOUNT = 'no_account';

    public const IDENTITY_CONFLICT = 'identity_conflict';

    /** Reasons an operator must act on. */
    public const OPERATOR = [self::NOT_CONFIGURED, self::SECRET_REJECTED, self::REDEEM_HOST_MISMATCH];

    /** Reference code on the operator page, and the key of its advice. */
    private const CODES = [
        self::NOT_CONFIGURED => 'SSO-NOT-CONFIGURED',
        self::SECRET_REJECTED => 'SSO-SECRET-REJECTED',
        self::REDEEM_HOST_MISMATCH => 'SSO-HOST-MISMATCH',
    ];

    /** @param array<string, scalar|null> $context safe, non-secret detail for the log */
    public function __construct(public readonly string $reason, public readonly array $context = [])
    {
        parent::__construct($reason);
    }

    public function needsOperator(): bool
    {
        return in_array($this->reason, self::OPERATOR, true);
    }

    public function code(): string
    {
        return self::CODES[$this->reason] ?? strtoupper(str_replace('_', '-', 'sso_'.$this->reason));
    }

    /** What the person sees on the login page for a failure that is not a host problem. Safe: no detail. */
    public function userMessage(): string
    {
        return match ($this->reason) {
            self::TOKEN_MISSING, self::TOKEN_REJECTED => 'That sign-in link has expired or was already used. Please open Dot.Central again from InfoDot.',
            self::INFODOT_UNAVAILABLE => 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.',
            self::NO_ACCOUNT, self::IDENTITY_CONFLICT => 'InfoDot confirmed who you are, but we could not match you to a Dot.Central account. Please ask your administrator.',
            default => 'We could not sign you in through InfoDot. Please open this app again from InfoDot.',
        };
    }
}
