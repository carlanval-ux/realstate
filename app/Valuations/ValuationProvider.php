<?php

declare(strict_types=1);

namespace App\Valuations;

/**
 * A source of estimates: CLARTO's own estimator, or an AVM (idealista/data, Tinsa) through its adapter. The engine
 * asks the enabled AVMs first and falls back to the next one, ending with CLARTO's estimator.
 */
interface ValuationProvider
{
    /** clarto | idealista | tinsa */
    public function key(): string;

    public function name(): string;

    /** True when the answer is simulated (test driver): the web and the report say so. */
    public function simulated(): bool;

    /**
     * @param  array<string, mixed>  $subject  normalised dwelling (ValuationEngine::subject())
     * @return array<string, mixed>|null ['estado' => 'estimada', …result fields] or ['estado' => 'revision', 'motivo' => …];
     *                                   null when this provider has no answer for the dwelling (the next one is asked)
     *
     * @throws ProviderFailed when the provider fails (timeout, error, unreadable answer)
     */
    public function estimate(array $subject): ?array;
}
