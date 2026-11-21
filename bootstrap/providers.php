<?php

use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;

return [
    ModuleManagerServiceProvider::class,
    App\Providers\ClartoServiceProvider::class,
    App\Providers\ClartoModulesServiceProvider::class,
];
