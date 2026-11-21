<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\TestAlquileres;
use App\Rentals\Integration\AlbaranGateway;
use App\Rentals\Integration\CommunicationLog;
use App\Rentals\Integration\FormsGateway;
use App\Rentals\Integration\LocalAlbaranGateway;
use App\Rentals\Integration\LocalFormsGateway;
use App\Rentals\Integration\SimulatedSesHospedajes;
use App\Rentals\Integration\TableCommunicationLog;
use App\Rentals\Integration\TravellerRegistry;
use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalUnit;
use App\Rentals\RentalAccess;
use App\Rentals\RentalModules;
use Illuminate\Support\ServiceProvider;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * «Alquiler tradicional», «Alquiler por habitaciones» and «Alquiler vacacional» (docs/clarto/alquileres.md).
 * Registered from ClartoServiceProvider. Integration points are bound to their LOCAL implementations with bindIf:
 * after merging the sibling branches, bind the real adapters (before this provider runs, or with bind() to replace).
 */
final class ClartoRentalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(AlbaranGateway::class, LocalAlbaranGateway::class);
        $this->app->bindIf(FormsGateway::class, LocalFormsGateway::class);
        $this->app->bindIf(CommunicationLog::class, TableCommunicationLog::class);
        $this->app->bindIf(TravellerRegistry::class, SimulatedSesHospedajes::class);
    }

    public function boot(): void
    {
        RentalAccess::defineGates();

        // For the property / owner 360 page: units and occupancies of a property.
        Property::resolveRelationUsing('rentalUnits', fn (Property $property) => $property->hasMany(RentalUnit::class));
        Property::resolveRelationUsing('rentalOccupancies', fn (Property $property) => $property->hasMany(Occupancy::class));

        // CLARTO module registry (feat/modulos-clarto): announce the three implementations when it is present.
        $registry = 'App\\Modules\\ClartoModules';
        if (class_exists($registry)) {
            foreach (RentalModules::KEYS as $key) {
                if (in_array($key, $registry::keys(), true)) {
                    $registry::implement($key);
                }
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([TestAlquileres::class]);
        }
    }
}
