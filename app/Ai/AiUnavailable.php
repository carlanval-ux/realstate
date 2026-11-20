<?php

declare(strict_types=1);

namespace App\Ai;

use RuntimeException;

/** The AI provider could not produce an answer (configuration, network or rejection). */
class AiUnavailable extends RuntimeException {}
