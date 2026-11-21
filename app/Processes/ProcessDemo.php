<?php

declare(strict_types=1);

namespace App\Processes;

use App\Models\User;
use App\Processes\Models\Area;
use App\Processes\Models\Position;
use App\Processes\Models\PositionAssignment;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessAttachment;
use App\Processes\Models\ProcessMapEntry;
use App\Signing\SimplePdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * Loads, idempotently, the proposed structure (areas, positions), the process map and the four fictitious
 * «DEMOSTRACIÓN» procedures of orquestacion/procesos/ejemplos-demo.md (database/data/procesos-*.json) into a company.
 * Demo people are created disabled (they cannot sign in). Existing codes, areas, positions and people are left as they are.
 * Seeding does not dispatch ProcessVersionPublished (the command calls the assistant's reindex when present).
 */
final class ProcessDemo
{
    /** @return array{areas: int, positions: int, map: int, people: array<string, User>, processes: array<string, Process>} */
    public static function load(Team $team, bool $withExamples = true): array
    {
        $teamId = $team->getKey();
        $map = json_decode((string) file_get_contents(database_path('data/procesos-mapa.json')), true, flags: JSON_THROW_ON_ERROR);
        $result = ['areas' => 0, 'positions' => 0, 'map' => 0, 'people' => [], 'processes' => []];

        return DB::transaction(function () use ($team, $teamId, $map, $withExamples, $result): array {
            // The company has no validated areas or positions yet: only those the examples need are created, as
            // proposals clearly named «(DEMOSTRACIÓN)». The real ones are created in «Áreas y puestos».
            $needed = $withExamples ? self::neededPositions() : [];
            $neededAreas = array_unique([...array_map(fn (array $p): string => $p['area'], array_filter($map['positions'], fn (array $p): bool => in_array($p['key'], $needed, true))),
                ...array_map(fn (array $x): string => $x['process']['area'], self::demo()['processes'])]);
            foreach ($withExamples ? $map['areas'] : [] as $a) {
                if (! in_array($a['code'], $neededAreas, true)) {
                    continue;
                }
                $area = Area::query()->firstOrCreate(['team_id' => $teamId, 'code' => $a['code']], [
                    'name' => $a['name'].' ('.__('procesos.demostracion').')', 'business_line' => $a['business_line'], 'proposal' => true, 'sort' => $a['sort'] ?? 100,
                    'description' => __('procesos.demo_estructura'),
                ]);
                $result['areas'] += (int) $area->wasRecentlyCreated;
            }
            $areas = Area::query()->where('team_id', $teamId)->pluck('id', 'code');
            foreach ($map['positions'] as $i => $p) {
                if (! in_array($p['key'], $needed, true)) {
                    continue;
                }
                $position = Position::query()->firstOrCreate(['team_id' => $teamId, 'key' => $p['key']], [
                    'area_id' => $areas[$p['area']], 'name' => $p['name'].' ('.__('procesos.demostracion').')', 'proposal' => true, 'sort' => ($i + 1) * 10,
                    'description' => __('procesos.demo_estructura'),
                ]);
                $result['positions'] += (int) $position->wasRecentlyCreated;
            }
            foreach ($map['entries'] as $e) {
                $entry = ProcessMapEntry::query()->firstOrNew(['team_id' => $teamId, 'key' => $e['key']]);
                $entry->fill(array_intersect_key($e, array_flip(['title', 'area_code', 'category', 'business_line', 'kind', 'owner_position_key',
                    'executing_position_keys', 'trigger', 'liberu_refs', 'tarmio_kinds', 'priority', 'priority_reason', 'template_key'])));
                if (! $entry->exists) {
                    $entry->status = $e['status'] ?? 'por_documentar';
                    $result['map']++;
                }
                $entry->save();
            }
            if ($withExamples) {
                $result['people'] = self::people($team);
                $result['processes'] = self::examples($teamId, $result['people']);
            }

            return $result;
        });
    }

    /** Position keys used by the examples and their fictitious people. @return list<string> */
    private static function neededPositions(): array
    {
        $demo = self::demo();
        $keys = array_column($demo['people'], 'position');
        foreach ($demo['processes'] as $x) {
            array_push($keys, ...$x['process']['positions']);
            foreach ($x['process']['audiences'] as $a) {
                $keys[] = $a['type'] === 'position' ? $a['target'] : null;
            }
            foreach ($x['versions'] as $v) {
                array_push($keys, ...array_column($v['steps'], 'actor_position'), ...array_column($v['decisions'], 'decider_position'));
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /** @return array<string, User> */
    private static function people(Team $team): array
    {
        $demo = self::demo();
        $positions = Position::query()->where('team_id', $team->getKey())->pluck('id', 'key');
        $people = [];
        foreach ($demo['people'] as $key => $p) {
            $user = User::query()->where('email', $p['email'])->first() ?? User::withoutEvents(fn () => User::query()->forceCreate([
                'name' => $p['name'], 'email' => $p['email'], 'password' => bcrypt(bin2hex(random_bytes(16))), 'locale' => 'es',
                'current_team_id' => $team->getKey(), 'disabled_at' => now(),
            ]));
            if (! ProcessAccess::isMember($user, $team->getKey())) {
                DB::table('team_user')->insert(['team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
            }
            self::role($user, $team->getKey(), 'staff');
            if ($p['approver']) {
                self::role($user, $team->getKey(), 'procesos_demo_aprobador', [ProcessAccess::APPROVE]);
            }
            $branch = DB::getSchemaBuilder()->hasTable('real_estate_branches') ? DB::table('real_estate_branches')->where('team_id', $team->getKey())->where('name', $p['branch'])->value('id') : null;
            PositionAssignment::query()->firstOrCreate(['team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'position_id' => $positions[$p['position']]], ['branch_id' => $branch, 'is_primary' => true]);
            $people[$key] = $user;
        }

        return $people;
    }

    /** @param list<string> $permissions */
    public static function role(User $user, int|string $teamId, string $role, array $permissions = []): void
    {
        $roleId = DB::table('roles')->where(['team_id' => $teamId, 'name' => $role, 'guard_name' => 'web'])->value('id')
            ?? DB::table('roles')->insertGetId(['team_id' => $teamId, 'name' => $role, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($permissions as $name) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')]);
        }
        DB::table('model_has_roles')->insertOrIgnore(['role_id' => $roleId, 'model_type' => $user->getMorphClass(), 'model_id' => $user->getKey(), 'team_id' => $teamId]);
        rescue(fn () => app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(), report: false);
    }

    /**
     * @param  array<string, User>  $people
     * @return array<string, Process>
     */
    private static function examples(int|string $teamId, array $people): array
    {
        $demo = self::demo();
        $areas = Area::query()->where('team_id', $teamId)->pluck('id', 'code');
        $positions = Position::query()->where('team_id', $teamId)->pluck('id', 'key');
        $pid = fn (?string $key): ?int => $key !== null ? (int) $positions[$key] : null;
        $uid = fn (?string $key): ?int => $key !== null ? (int) $people[$key]->getKey() : null;
        $created = [];

        foreach ($demo['processes'] as $example) {
            if (Process::query()->where('team_id', $teamId)->where('code', $example['code'])->exists()) {
                continue;
            }
            $m = $example['process'];
            $process = Process::query()->create([
                'team_id' => $teamId, 'code' => $example['code'], 'area_id' => $areas[$m['area']], 'category' => $m['category'],
                // No real owners or approvers yet (owner's decision): left unassigned, they show up in «Responsables sin asignar».
                'business_line' => $m['business_line'], 'kind' => $m['kind'], 'status' => 'borrador', 'owner_user_id' => null,
                'approver_user_id' => null, 'visibility' => $m['visibility'], 'ai_excluded' => (bool) $m['ai_excluded'],
                'template_key' => $m['template_key'], 'created_by' => $uid($example['versions'][0]['author']),
            ]);
            $process->positions()->sync(array_map($pid, $m['positions']));
            foreach ($m['audiences'] as $a) {
                $process->audiences()->create(['type' => $a['type'], 'target_id' => $a['type'] === 'user' ? $uid($a['target']) : $pid($a['target']), 'effect' => $a['effect']]);
            }
            Processes::event($process, null, $people[$example['versions'][0]['author']], 'creado', __('procesos.demostracion'));

            $previous = null;
            foreach ($example['versions'] as $v) {
                $version = $process->versions()->create([
                    'team_id' => $teamId, 'status' => 'borrador', 'title' => $v['title'], 'created_by' => $uid($v['author']),
                    'based_on_version_id' => $previous?->getKey(),
                ]);
                $files = [];
                foreach ($demo['attachments'] as $name => $spec) {
                    if (str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), $name)) {
                        $files[$name] = self::attachment($people[$v['author']], $version, $name, $spec)->getKey();
                    }
                }
                $blocks = fn (array $list): array => array_map(fn (array $b): array => isset($b['data']['attachment'])
                    ? ['type' => $b['type'], 'data' => [...array_diff_key($b['data'], ['attachment' => 1]), 'attachment_id' => $files[$b['data']['attachment']]]]
                    : $b, $list);
                $version->forceFill([
                    ...array_intersect_key($v, array_flip(['summary', 'objective', 'expected_result', 'trigger', 'requirements', 'required_data', 'no_decisions',
                        'exceptions', 'escalation', 'evidences', 'deadlines', 'kpis', 'related', 'diagram_mermaid', 'keywords', 'change_summary'])),
                    'steps' => array_map(fn (array $s): array => [
                        ...array_diff_key($s, ['actor_position' => 1]), 'actor_position_id' => $pid($s['actor_position']), 'blocks' => $blocks($s['blocks']),
                    ], $v['steps']),
                    'decisions' => array_map(fn (array $d): array => [...array_diff_key($d, ['decider_position' => 1]), 'decider_position_id' => $pid($d['decider_position'])], $v['decisions']),
                    'tools' => array_map(fn (array $t): array => [...array_diff_key($t, ['attachment' => 1]), 'attachment_id' => $t['attachment'] ? $files[$t['attachment']] : null], $v['tools']),
                    'blocks' => $blocks($v['blocks']),
                ])->save();
                $version->forceFill([
                    'status' => $v['status'], 'number' => $v['number'], 'approved_by' => $uid($v['approved_by']), 'self_approved' => (bool) $v['self_approved'],
                    'published_at' => $v['published_at'], 'submitted_by' => $uid($v['author']), 'submitted_at' => $v['published_at'],
                    'meta_snapshot' => [...$version->metaSnapshot(), 'next_review_on' => $v['next_review_on']],
                ])->save();
                Processes::event($process, $version, $people[$v['approved_by']], 'publicado', __('procesos.demostracion'), ['number' => $v['number']]);
                if ($v['status'] === 'publicada') {
                    $process->forceFill(['status' => 'publicado', 'current_version_id' => $version->getKey(), 'next_review_on' => $v['next_review_on'], 'last_reviewed_at' => $v['published_at']])->save();
                }
                $previous = $version;
            }
            $created[$example['code']] = $process->fresh();
        }

        return $created;
    }

    private static function attachment(User $by, \App\Processes\Models\ProcessVersion $version, string $name, array $spec): ProcessAttachment
    {
        $path = 'procesos/demo-tmp/'.bin2hex(random_bytes(6)).'-'.$name;
        Storage::disk(ProcessAttachment::DISK)->put($path, $spec['kind'] === 'image' ? self::png($spec['text']) : SimplePdf::make($spec['text'], $spec['text']."\n\nDocumento ficticio para probar el apartado «Procesos y conocimiento»."));
        try {
            return ProcessAttachment::store($by, $version, $path, $name, $spec['kind'], trusted: true);
        } finally {
            Storage::disk(ProcessAttachment::DISK)->delete($path);
        }
    }

    private static function png(string $text): string
    {
        $image = imagecreatetruecolor(800, 450);
        imagefill($image, 0, 0, imagecolorallocate($image, 220, 222, 225));
        imagestring($image, 5, 40, 210, iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: 'DEMOSTRACION', imagecolorallocate($image, 23, 61, 56));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private static function demo(): array
    {
        return json_decode((string) file_get_contents(database_path('data/procesos-demo.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
