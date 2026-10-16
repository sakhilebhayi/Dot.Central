<?php

namespace App\Services;

/** Result of an optional push of an alert action back to Dot.Mines. */
final class PushOutcome
{
    private function __construct(public readonly bool $requested, public readonly bool $pushed, public readonly ?string $failure) {}

    public static function notRequested(): self
    {
        return new self(false, false, null);
    }

    public static function pushed(): self
    {
        return new self(true, true, null);
    }

    public static function failed(string $reason): self
    {
        return new self(true, false, $reason);
    }

    /** Text appended to the audit reason. */
    public function suffix(): string
    {
        return match (true) {
            $this->pushed => ' (pushed to Dot.Mines)',
            $this->requested => ' (Dot.Mines not updated: '.$this->failure.')',
            default => '',
        };
    }

    /** Flash message for the operator. */
    public function message(string $done): string
    {
        return match (true) {
            $this->pushed => $done.' Dot.Mines updated too.',
            $this->requested => $done.' Dot.Mines was NOT updated: '.$this->failure.' Resolve it there or ask an administrator to check the connection\'s permissions.',
            default => $done,
        };
    }
}
