<?php

declare(strict_types=1);

namespace App\Processes;

use App\Models\ImprovementRequest;
use App\Models\User;
use App\Processes\Models\ProcessFeedbackLink;
use App\Processes\Models\ProcessVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** «Comunicar una duda o mejora» and «Enviar la duda al responsable»: an ImprovementRequest linked to the process. */
final class ProcessFeedback
{
    public static function submit(User $user, int|string $teamId, string $text, ?ProcessVersion $version = null,
        ?string $stepKey = null, string $source = 'lector', ?int $assistantQueryId = null): ImprovementRequest
    {
        $text = trim($text);
        if ($text === '') {
            throw ValidationException::withMessages(['text' => __('procesos.errores.duda_vacia')]);
        }
        if ($version !== null && ((string) $version->team_id !== (string) $teamId || ! ProcessAccess::canView($user, $version))) {
            abort(404);
        }
        $process = $version?->process;
        $step = $stepKey !== null ? collect((array) $version?->steps)->firstWhere('key', $stepKey) : null;
        $title = '[Procesos] '.($process ? $process->code : __('procesos.duda_sin_proceso')).' · '.Str::limit(Str::squish($text), 100);
        $context = $version === null ? [] : array_filter([
            '',
            '—',
            __('procesos.duda.contexto', ['code' => $process->code, 'title' => $version->title, 'n' => $version->number ?? '—']),
            $step ? __('procesos.duda.paso', ['n' => $version->stepNumbers()[$stepKey] ?? '?', 'title' => $step['title'] ?? '']) : null,
            ProcessLinks::version($version, $step ? 'paso-'.$stepKey : null),
        ], fn ($l) => $l !== null);

        return DB::transaction(function () use ($user, $teamId, $title, $text, $context, $version, $stepKey, $source, $assistantQueryId): ImprovementRequest {
            $request = ImprovementRequest::submit($user, $teamId, Str::limit($title, 157), $text.($context ? "\n".implode("\n", $context) : ''));
            ProcessFeedbackLink::query()->create([
                'team_id' => $teamId, 'improvement_request_id' => $request->getKey(), 'process_id' => $version?->process_id,
                'process_version_id' => $version?->getKey(), 'step_key' => $stepKey, 'source' => in_array($source, ['lector', 'asistente'], true) ? $source : 'lector',
                'assistant_query_id' => $assistantQueryId, 'created_at' => now(),
            ]);

            return $request;
        });
    }
}
