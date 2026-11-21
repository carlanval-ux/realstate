<?php

declare(strict_types=1);

namespace App\Rentals;

use RuntimeException;

/** A rental operation refused for a reason the user can read (rule, permission, module switched off). */
class RentalRefused extends RuntimeException {}
