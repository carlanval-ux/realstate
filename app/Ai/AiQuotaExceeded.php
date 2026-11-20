<?php

declare(strict_types=1);

namespace App\Ai;

/** The provider refused for quota, rate limit or credits: work saved so far is kept. */
final class AiQuotaExceeded extends AiUnavailable {}
