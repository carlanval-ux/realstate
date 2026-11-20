<?php

use App\Ai\AiUnavailable;
use App\Ai\PropertyTextWriter;

it('normalises figures in Spanish, English and Turkish formats', function () {
    expect(PropertyTextWriter::numbers('189.000 € · 189,000 EUR · 189 000 · 96 m² · 3 dormitorios · 96,5'))
        ->toEqual(['189000', '96', '3', '96.5']);
});

it('flags figures that are not in the property data and missing place names', function () {
    $facts = ['Precio' => '189.000 EUR', 'Superficie construida' => '96 m²', 'Dormitorios' => '3', 'Zona' => 'Puerto de Sagunto'];

    $ok = PropertyTextWriter::check($facts, ['title' => 'Piso de 3 dormitorios en Puerto de Sagunto', 'description' => '96 m² por 189.000 €']);
    $bad = PropertyTextWriter::check($facts, ['title' => 'Piso a 5 minutos de la playa', 'description' => '120 m² por 189.000 €']);

    expect($ok)->toBe([])
        ->and($bad[0])->toContain('5')->toContain('120')
        ->and($bad[1])->toContain('Puerto de Sagunto');
});

it('reads the JSON answer even inside code fences and rejects other formats', function () {
    $fields = PropertyTextWriter::parse("```json\n{\"title\": \"Piso\", \"description\": \"Texto\", \"seo_title\": \"\"}\n```");

    expect($fields['title'])->toBe('Piso')->and($fields['seo_title'])->toBeNull();
    expect(fn () => PropertyTextWriter::parse('Lo siento, no puedo.'))->toThrow(AiUnavailable::class);
});
