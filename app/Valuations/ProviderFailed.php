<?php

declare(strict_types=1);

namespace App\Valuations;

use RuntimeException;

/** An external AVM failed (timeout, HTTP error, answer without a value): the engine falls back and records it. */
final class ProviderFailed extends RuntimeException {}
