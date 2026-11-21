<?php

declare(strict_types=1);

namespace App\Signing;

use RuntimeException;

/** A send, reconciliation or SMS could not be done; the message is safe to show to staff (no URLs, keys or bodies). */
final class SigningFailed extends RuntimeException {}
