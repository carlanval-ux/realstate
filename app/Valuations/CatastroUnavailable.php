<?php

declare(strict_types=1);

namespace App\Valuations;

use RuntimeException;

/** Catastro did not answer (timeout, 5xx, not JSON). */
final class CatastroUnavailable extends RuntimeException {}
