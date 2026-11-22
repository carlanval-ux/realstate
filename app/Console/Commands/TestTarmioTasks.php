<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acquisition\GhlHandoff;
use App\Tarmio\SyncTarmioTask;
use App\Tarmio\TarmioTask;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Lettings\Models\Letting;
use Liberu\RealEstate\Properties\Models\Property;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource;
use Liberu\RealEstate\PropertyManagement\Models\MaintenanceRequest;
use Throwable;

/**
 * Liberu → TARMIO tasks with a SIMULATED TARMIO (Http::fake of its Supabase REST API) and simulated GoHighLevel.
 * Three flows: owner from GHL won, breakdown, check-in date change. Runs in a rolled-back transaction.
 *
 *   php artisan clarto:tarmio-prueba
 */
final class TestTarmioTasks extends Command
{
    protected $signature = 'clarto:tarmio-prueba';

    protected $description = 'Test Liberu → TARMIO tasks with simulated TARMIO and GHL answers (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    /** @var array<string, array<string, mixed>> TARMIO tasks table (simulated) */
    private array $remote = [];

    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    private array $calls = [];

    private bool $tarmioDown = false;

    /** @var array<int, true> */
    private array $ran = [];

    public function handle(): int
    {
        config([
            'cache.default' => 'array',
            'tarmio.driver' => 'http', 'tarmio.url' => 'https://tarmio-prueba.supabase.co', 'tarmio.api_key' => 'sb_publishable_prueba',
            'tarmio.user' => 'integracion-liberu@clarto.test', 'tarmio.password' => 'clave-prueba',
            'tarmio.organization_id' => 'org-clarto', 'tarmio.project_id' => 'prj-liberu', 'tarmio.responsable_captacion' => 'captacion@clarto.test',
            'ghl.token' => 'pit-prueba', 'ghl.location_id' => 'LOC-CLARTO', 'ghl.pipeline_id' => 'PIPE-CAPTACION', 'ghl.api_url' => 'https://services.leadconnectorhq.com',
            'ghl.fields' => ['municipio' => 'f-mun', 'superficie_m2' => 'f-m2'],
        ]);
        Queue::fake([SyncTarmioTask::class]);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => str_contains($request->url(), 'leadconnectorhq') ? $this->ghl($request) : $this->tarmio($request));

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin excepciones: '.$e->getMessage(), false);
        } finally {
            DB::rollBack();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (TARMIO and GHL simulated with Http::fake; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $team = Team::query()->oldest()->firstOrFail();
        $creates = fn (): int => count(array_filter($this->calls, fn ($c) => $c['method'] === 'POST' && str_ends_with($c['url'], '/rest/v1/tasks')));
        $patches = fn (): int => count(array_filter($this->calls, fn ($c) => $c['method'] === 'PATCH'));
        $taskOf = fn (?TarmioTask $t): array => $this->remote[$t?->tarmio_task_id ?? ''] ?? [];

        // 1. New owner from a GHL won opportunity ─────────────────────────────────────────────────────────────────
        $this->line('— Alta de propietario desde GHL');
        GhlHandoff::process('OPP-TARMIO-1');
        $this->runJobs();
        $handoff = GhlHandoff::query()->where('opportunity_id', 'OPP-TARMIO-1')->first();
        $row = TarmioTask::query()->where('source_id', $handoff?->property_id)->where('kind', 'alta_propietario')->first();
        $remote = $taskOf($row);
        $url = PropertyResource::getUrl('edit', ['record' => $handoff?->property_id], panel: 'admin', tenant: $team);
        $this->check('GHL ganada → 1 tarea creada en TARMIO y su id guardado en Liberu', $handoff?->status === 'procesado' && $creates() === 1 && $row?->status === 'sincronizada' && $remote !== []);
        $this->check('asignada al responsable de captación (por e-mail → usuario TARMIO)', ($remote['assignee_id'] ?? null) === 'u-captacion');
        $this->check('empresa, proyecto, vencimiento y referencia externa', ($remote['organization_id'] ?? '') === 'org-clarto' && ($remote['project_id'] ?? '') === 'prj-liberu'
            && ($remote['due_date'] ?? '') === now()->addWeekdays(2)->format('Y-m-d') && ($remote['source_external_id'] ?? '') === 'alta_propietario:property:'.$handoff?->property_id);
        $this->check('enlace directo a la ficha en Liberu: '.$url, str_contains((string) ($remote['description'] ?? ''), $url) && str_contains($url, '/properties/'.$handoff?->property_id.'/edit'));
        $sent = json_encode(array_column($this->calls, 'body'));
        $this->check('no viajan datos personales (nombre, e-mail, teléfono del propietario)', ! str_contains($sent, 'Ficticia') && ! str_contains($sent, 'propietaria.ficticia') && ! str_contains($sent, '600000001'));
        $n = count($this->calls);
        GhlHandoff::process('OPP-TARMIO-1');
        $handoff?->forceFill(['updated_at' => now()])->save();   // the same event reaching the hook again
        $this->runJobs();
        $this->check('webhook GHL repetido (dos veces): ni tarea ni llamada nueva', $creates() === 1 && count($this->calls) === $n && TarmioTask::query()->where('kind', 'alta_propietario')->where('source_id', $handoff?->property_id)->count() === 1);

        // 2. Breakdown ─────────────────────────────────────────────────────────────────────────────────────────────
        $this->line('— Avería');
        $property = Property::query()->findOrFail($handoff?->property_id);
        $breakdown = MaintenanceRequest::query()->create(['team_id' => $team->getKey(), 'property_id' => $property->getKey(), 'title' => 'Fuga en el baño (ficticia)',
            'description' => 'Inquilino ficticio avisa de una fuga.', 'status' => 'pending', 'priority' => 'high', 'requested_date' => now()->toDateString()]);
        $breakdownUrl = $url.'#averia-'.$breakdown->getKey();
        $this->tarmioDown = true;
        $row = TarmioTask::sync($breakdown, 'averia', 'Avería · '.$property->reference(), 'tecnico@clarto.test', now()->addDay(), $breakdownUrl);
        $this->runJobs();
        $this->check('TARMIO caído: tarea en error, nada creado', $row->refresh()->status === 'error' && $creates() === 1);
        $this->tarmioDown = false;
        (new SyncTarmioTask($row->getKey()))->handle();   // the queue's retry
        $this->runJobs();
        $remote = $taskOf($row->refresh());
        $this->check('reintento: crea la tarea una sola vez, asignada al técnico, con enlace', $creates() === 2 && $row->status === 'sincronizada'
            && ($remote['assignee_id'] ?? null) === 'u-tecnico' && str_contains((string) $remote['description'], $breakdownUrl));
        TarmioTask::sync($breakdown, 'averia', 'Avería · '.$property->reference(), 'tecnico@clarto.test', now()->addDay(), $breakdownUrl);
        TarmioTask::sync($breakdown, 'averia', 'Avería · '.$property->reference(), 'tecnico@clarto.test', now()->addDay(), $breakdownUrl);
        $this->runJobs();
        $this->check('mismo evento dos veces: sin duplicados ni llamadas', $creates() === 2 && TarmioTask::query()->where('kind', 'averia')->count() === 1);

        // Created in TARMIO but Liberu crashed before saving the id: the retry finds it by its external reference.
        $other = MaintenanceRequest::query()->create(['team_id' => $team->getKey(), 'property_id' => $property->getKey(), 'title' => 'Persiana rota (ficticia)',
            'description' => '-', 'status' => 'pending', 'priority' => 'normal', 'requested_date' => now()->toDateString()]);
        $this->remote['tsk-huerfana'] = ['id' => 'tsk-huerfana', 'public_ref' => 'TSK-09999', 'organization_id' => 'org-clarto', 'source_provider' => 'liberu',
            'source_external_id' => 'averia:maintenance_request:'.$other->getKey(), 'status' => 'pendiente'];
        $row2 = TarmioTask::sync($other, 'averia', 'Avería · '.$property->reference(), 'tecnico@clarto.test', now()->addDays(2), $breakdownUrl);
        $this->runJobs();
        $this->check('caída tras crear en TARMIO: el reintento reutiliza esa tarea (no duplica)', $creates() === 2 && $row2->refresh()->tarmio_task_id === 'tsk-huerfana');

        $this->remote[$row->tarmio_task_id]['status'] = 'completada';   // the technician closes it in TARMIO
        TarmioTask::sync($breakdown, 'averia', 'Avería · '.$property->reference(), 'tecnico@clarto.test', now()->addDays(3), $breakdownUrl);
        $this->runJobs();
        $this->check('completar en TARMIO no cambia la avería en Liberu ni Liberu reabre la tarea', $breakdown->refresh()->status->value === 'pending'
            && $this->remote[$row->tarmio_task_id]['status'] === 'completada' && ! array_key_exists('status', end($this->calls)['body']));

        // 3. Check-in date change ──────────────────────────────────────────────────────────────────────────────────
        $this->line('— Cambio de fecha de una entrada');
        $letting = Letting::query()->create(['team_id' => $team->getKey(), 'property_id' => $property->getKey(), 'subject' => 'Alquiler ficticio '.$property->reference(),
            'capability' => 'tenancy', 'status' => 'in_progress', 'details' => ['fecha_entrada' => now()->addDays(10)->toDateString()]]);
        $checkIn = fn () => TarmioTask::sync($letting, 'entrada', 'Entrada (check-in) · '.$property->reference(), $letting->details['gestor'] ?? 'gestora@clarto.test',
            \Illuminate\Support\Carbon::parse($letting->details['fecha_entrada']), $url.'#alquiler-'.$letting->getKey());
        $row = $checkIn();
        $this->runJobs();
        $id = $row->refresh()->tarmio_task_id;
        $this->check('entrada programada → tarea con su fecha', $creates() === 3 && ($this->remote[$id]['due_date'] ?? '') === now()->addDays(10)->toDateString());
        $letting->update(['details' => ['fecha_entrada' => now()->addDays(14)->toDateString()]]);
        $p = $patches();
        $checkIn();
        $this->runJobs();
        $this->check('cambia la fecha → se actualiza la MISMA tarea', $creates() === 3 && $patches() === $p + 1 && $row->refresh()->tarmio_task_id === $id
            && $this->remote[$id]['due_date'] === now()->addDays(14)->toDateString());
        $letting->update(['details' => ['fecha_entrada' => now()->addDays(14)->toDateString(), 'gestor' => 'tecnico@clarto.test']]);
        $checkIn();
        $this->runJobs();
        $this->check('cambia el responsable → misma tarea reasignada', $creates() === 3 && $this->remote[$id]['assignee_id'] === 'u-tecnico');
        $n = count($this->calls);
        $checkIn();
        $checkIn();
        $this->runJobs();
        $this->check('mismo evento dos veces: sin llamadas ni filas nuevas', count($this->calls) === $n && TarmioTask::query()->where('kind', 'entrada')->count() === 1);

        $allowed = ['organization_id', 'project_id', 'created_by', 'source_provider', 'source_external_id', 'title', 'description', 'due_date', 'assignee_id'];
        $this->check('solo se envían título, responsable, fecha, enlace y referencia', collect($this->calls)->filter(fn ($c) => str_contains($c['url'], '/rest/v1/tasks'))
            ->every(fn ($c) => array_diff(array_keys($c['body']), $allowed) === []));
    }

    /** Runs the queued SyncTarmioTask jobs not run yet (what the queue worker would do). */
    private function runJobs(): void
    {
        foreach (Queue::pushed(SyncTarmioTask::class) as $job) {
            if (isset($this->ran[spl_object_id($job)])) {
                continue;
            }
            $this->ran[spl_object_id($job)] = true;
            try {
                $job->handle();
            } catch (Throwable $e) {
                $this->line('      (trabajo en cola falló, queda para reintento: '.$e->getMessage().')');
            }
        }
    }

    private function tarmio(Request $request): mixed
    {
        $url = $request->url();
        $body = (array) $request->data();
        if ($request->header('apikey') !== ['sb_publishable_prueba']) {
            return Http::response(['message' => 'No API key'], 401);
        }
        if (str_contains($url, '/auth/v1/token')) {
            return ($body['email'] ?? '') === 'integracion-liberu@clarto.test' && ($body['password'] ?? '') === 'clave-prueba'
                ? Http::response(['access_token' => 'jwt-prueba', 'expires_in' => 3600, 'user' => ['id' => 'u-integracion']])
                : Http::response(['error' => 'invalid_grant'], 400);
        }
        if (! $request->hasHeader('Authorization', 'Bearer jwt-prueba')) {
            return Http::response(['message' => 'JWT expected'], 401);
        }
        if ($this->tarmioDown && $request->method() === 'POST' && str_ends_with($url, '/rest/v1/tasks')) {
            return Http::response(['message' => 'Service unavailable'], 503);
        }
        $this->calls[] = ['method' => $request->method(), 'url' => strtok($url, '?'), 'body' => $request->method() === 'GET' ? [] : $body];

        if (str_ends_with($url, '/rpc/lookup_user_by_email')) {
            $users = ['captacion@clarto.test' => 'u-captacion', 'tecnico@clarto.test' => 'u-tecnico', 'gestora@clarto.test' => 'u-gestora'];

            return Http::response(isset($users[$body['_email'] ?? '']) ? [['user_id' => $users[$body['_email']], 'full_name' => 'Persona ficticia']] : []);
        }
        if ($request->method() === 'GET' && str_contains($url, '/rest/v1/tasks')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $ext = substr((string) ($q['source_external_id'] ?? ''), 3);

            return Http::response(array_values(array_filter($this->remote, fn ($t) => ($t['source_external_id'] ?? null) === $ext)));
        }
        if ($request->method() === 'POST' && str_ends_with($url, '/rest/v1/tasks')) {
            $id = 'tsk-'.(count($this->remote) + 1);
            $this->remote[$id] = ['id' => $id, 'public_ref' => sprintf('TSK-%05d', count($this->remote) + 1), 'status' => 'pendiente'] + $body;

            return Http::response([$this->remote[$id]], 201);
        }
        if ($request->method() === 'PATCH' && preg_match('#/rest/v1/tasks\?id=eq\.([\w-]+)#', $url, $m)) {
            if (! isset($this->remote[$m[1]])) {
                return Http::response([]);
            }
            $this->remote[$m[1]] = array_merge($this->remote[$m[1]], $body);

            return Http::response([$this->remote[$m[1]]]);
        }

        return Http::response(['message' => 'not found'], 404);
    }

    private function ghl(Request $request): mixed
    {
        if (preg_match('#/opportunities/([A-Z0-9-]+)$#', $request->url(), $m)) {
            return Http::response(['opportunity' => ['id' => $m[1], 'status' => 'won', 'contactId' => 'CON-T1', 'locationId' => 'LOC-CLARTO', 'pipelineId' => 'PIPE-CAPTACION',
                'customFields' => [['id' => 'f-mun', 'fieldValue' => 'Sagunto'], ['id' => 'f-m2', 'fieldValue' => '90']]]]);
        }

        return Http::response(['contact' => ['id' => 'CON-T1', 'name' => 'Propietaria Ficticia', 'email' => 'propietaria.ficticia@example.com', 'phone' => '+34600000001']]);
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
