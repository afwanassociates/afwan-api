<?php

namespace App\Enums;

/**
 * After a passport moves from agent A to agent B, the accounts head decides
 * whether it still counts (is paid) for A. Until then it is waiting and does
 * not count for A.
 */
enum AgentCountDecision: string
{
    case Waiting = 'waiting';
    case Yes = 'yes';
    case No = 'no';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting for Accounts Head',
            self::Yes => 'Yes',
            self::No => 'No',
        };
    }
}
