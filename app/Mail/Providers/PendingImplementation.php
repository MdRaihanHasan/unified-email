<?php

namespace App\Mail\Providers;

use LogicException;

/**
 * Marks a MailboxProvider method that the roadmap has not reached yet.
 *
 * Phase 0 wires up the contract, the client bootstrap and the persistence layer.
 * Each provider's protocol calls land in phases 1-3 (see docs/architecture.md).
 * Throwing loudly beats returning empty data that reads like a working sync.
 */
trait PendingImplementation
{
    private function pending(string $method, string $phase): never
    {
        throw new LogicException(sprintf(
            '%s::%s() is not implemented yet (roadmap %s).',
            static::class,
            $method,
            $phase,
        ));
    }
}
