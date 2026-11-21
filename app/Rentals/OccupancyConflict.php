<?php

declare(strict_types=1);

namespace App\Rentals;

/** The unit (or its dwelling / one of its rooms) is already occupied in those dates, or the calendar is busy. */
final class OccupancyConflict extends RentalRefused {}
