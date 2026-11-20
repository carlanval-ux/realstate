<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\AiClient;
use App\Ai\AiUnavailable;
use App\Ai\PropertyTextWriter;
use App\Models\AiSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Runs the same properties through several models (Spanish writing + English and Turkish translation)
 * without saving anything to the records, and writes a Markdown report with the outputs, timings and
 * automatic checks (figures not in the data, place names left out, Turkish letters, lengths).
 *
 *   php artisan clarto:ia-comparar --modelos=a:free,b:free --inmuebles=CL-2026-00004,CL-2026-00005
 */
final class CompareAiModels extends Command
{
    protected $signature = 'clarto:ia-comparar {--modelos= : Comma-separated model ids} {--inmuebles=CL-2026-00004,CL-2026-00005 : References}';

    protected $description = 'Compare AI models on the same properties (nothing is saved to the records)';

    public function handle(): int
    {
        $models = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('modelos')))));
        if ($models === []) {
            $this->error('Pass --modelos=model1,model2');

            return self::FAILURE;
        }

        $properties = collect(explode(',', (string) $this->option('inmuebles')))
            ->map(fn (string $ref) => Property::query()->with(['region', 'city', 'district'])->whereKey((int) Str::afterLast(trim($ref), '-'))->first())
            ->filter()->values();

        $md = ['# Comparativa de modelos de IA · '.now()->toDateTimeString(), ''];
        $summary = [];

        foreach ($models as $model) {
            $settings = AiSetting::current()->replicate();
            $settings->forceFill(['writing_model' => $model, 'translation_model' => $model, 'paid_fallback_authorized' => false]);
            $writer = new PropertyTextWriter(new AiClient($settings));
            $md[] = "## `{$model}`";
            $row = ['model' => $model, 'ok' => 0, 'errors' => 0, 'notes' => 0, 'seconds' => 0.0, 'tr_letters' => 0];

            foreach ($properties as $property) {
                $md[] = "### {$property->reference()} · {$property->title}";
                $md[] = '**Datos:** '.collect(PropertyTextWriter::facts($property))->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ');
                try {
                    $t = microtime(true);
                    $es = $writer->writeSpanish($property);
                    $row['seconds'] += microtime(true) - $t;
                    $md = [...$md, ...$this->block('ES', $es)];
                    foreach (['en', 'tr'] as $locale) {
                        $t = microtime(true);
                        $tr = $writer->translate($property, $locale, $es['fields']);
                        $row['seconds'] += microtime(true) - $t;
                        $md = [...$md, ...$this->block(strtoupper($locale), $tr)];
                        $row['notes'] += count($tr['notes']);
                        if ($locale === 'tr') {
                            $row['tr_letters'] += preg_match_all('/[ıİşŞğĞçÇöÖüÜ]/u', implode(' ', array_filter($tr['fields'])));
                        }
                    }
                    $row['notes'] += count($es['notes']);
                    $row['ok']++;
                } catch (AiUnavailable $e) {
                    $row['errors']++;
                    $md[] = '> **Error:** '.$e->getMessage();
                }
                $md[] = '';
            }
            $summary[] = $row;
            $this->info(sprintf('%s: %d ok, %d errors, %d checks, %.1fs', $model, $row['ok'], $row['errors'], $row['notes'], $row['seconds']));
        }

        $table = ['| Modelo | Fichas OK | Errores | Avisos de datos | Letras turcas | Segundos |', '|---|---|---|---|---|---|'];
        foreach ($summary as $r) {
            $table[] = sprintf('| `%s` | %d | %d | %d | %d | %.1f |', $r['model'], $r['ok'], $r['errors'], $r['notes'], $r['tr_letters'], $r['seconds']);
        }
        array_splice($md, 2, 0, [...$table, '']);

        $path = storage_path('app/ia-comparativa/'.now()->format('Ymd-His').'.md');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, implode("\n", $md)."\n");
        $this->line($path);

        return self::SUCCESS;
    }

    /**
     * @param  array{fields: array<string, ?string>, model: string, notes: list<string>}  $result
     * @return list<string>
     */
    private function block(string $label, array $result): array
    {
        $f = $result['fields'];

        return array_values(array_filter([
            "**{$label}** (`{$result['model']}`)",
            '- Título ('.mb_strlen((string) $f['title']).'): '.$f['title'],
            '- Resumen ('.mb_strlen((string) $f['summary']).'): '.$f['summary'],
            '- SEO: '.$f['seo_title'].' · '.$f['seo_description'],
            '- Descripción ('.mb_strlen((string) $f['description']).'):',
            '',
            '  '.str_replace("\n", "\n  ", (string) $f['description']),
            '',
            $result['notes'] !== [] ? '- ⚠ '.implode(' · ', $result['notes']) : '- ✓ Sin avisos de datos',
            '',
        ], fn ($l) => $l !== null));
    }
}
