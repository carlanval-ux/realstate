<?php

declare(strict_types=1);

use App\Filament\Pages\UserManual;
use App\Modules\ClartoModules;

beforeEach(function (): void {
    app()->setLocale('zz');
    file_put_contents(resource_path('manual/zz.md'), <<<'MD'
        # Manual

        Introducción.

        ## Inmuebles

        Alta de inmuebles con fotos.

        ## Valoraciones
        <!-- módulo: valoraciones -->

        Informe de valoración automática.

        ### Ajustes de valoración

        Precio por metro.

        ## Procesos

        Procedimientos publicados.

        ### Preguntar a CLARTO
        <!-- módulo: procesos_asistente, valoraciones -->

        Asistente de procesos.

        ### Leer un proceso

        Paso a paso.
        MD);
});

afterEach(fn () => @unlink(resource_path('manual/zz.md')));

function manualModules(bool $valoraciones, bool $asistente): void
{
    app()->instance(ClartoModules::CHOICES, ['clarto-valoraciones' => $valoraciones, 'clarto-procesos-asistente' => $asistente]);
}

it('shows a module block only while one of its modules is switched on', function (): void {
    manualModules(valoraciones: false, asistente: false);
    $manual = collect((new UserManual)->manual())->keyBy('title');

    expect($manual->keys()->all())->toBe(['', 'Inmuebles', 'Procesos'])
        ->and($manual['Procesos']['html'])->not->toContain('Asistente')->toContain('Paso a paso')->not->toContain('módulo');

    manualModules(valoraciones: false, asistente: true);
    expect(collect((new UserManual)->manual())->firstWhere('title', 'Procesos')['html'])
        ->toContain('<h3 id="preguntar-a-clarto">')->toContain('Paso a paso');
});

it('finds entries containing every word, accents ignored, among the visible ones', function (): void {
    manualModules(valoraciones: true, asistente: false);

    expect(UserManual::search('valoracion metro'))->toBe([[
        'title' => 'Ajustes de valoración', 'section' => 'Valoraciones', 'anchor' => 'ajustes-de-valoracion', 'excerpt' => 'Precio por metro.',
    ]])
        ->and(UserManual::search('valoración inexistente'))->toBe([])
        ->and(UserManual::search('asistente'))->toHaveCount(1);

    manualModules(valoraciones: false, asistente: false);
    expect(UserManual::search('metro'))->toBe([])
        ->and(UserManual::search('asistente'))->toBe([]);
});
