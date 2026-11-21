<?php

declare(strict_types=1);

namespace App\Filament\Processes\Pages;

use App\Filament\Processes\ProcessUi;
use App\Processes\Models\Area;
use App\Processes\Models\Position;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessAttachment;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessAccess;
use App\Processes\Processes;
use App\Processes\ProcessLinks;
use App\Processes\ProcessTemplates;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Draft editor: a minimal draft can be saved with just the name and completed later; the completeness box shows what
 * is still missing for review (ProcessVersion::publicationGaps). Uploaded files become private attachments of the draft.
 */
final class ProcessEditor extends Page
{
    protected static ?string $slug = 'procesos/{process}/editar';

    protected static bool $shouldRegisterNavigation = false;

    public int $processId;

    public int $versionId;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'procesos.editar';
    }

    public static function canAccess(): bool
    {
        $team = ProcessUi::team();

        return $team !== null && ProcessAccess::isStaff(ProcessUi::user(), $team);
    }

    public function mount(int|string $process): void
    {
        $model = ProcessUi::process($process);
        $user = ProcessUi::user();
        abort_unless(ProcessAccess::canEdit($user, $model), 404);
        $this->processId = $model->getKey();
        $open = $model->openVersion;
        if ($open?->status === 'en_revision') {
            session()->flash('clp-notice', __('procesos.editor.en_revision'));
            $this->redirect(ProcessLinks::version($open));

            return;
        }
        abort_unless(ProcessUi::moduleOn(), 403, (string) \App\Modules\ClartoModules::refusal('procesos'));
        $draft = $open ?? Processes::startRevision($user, $model);
        $this->versionId = $draft->getKey();
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $draft = $this->draft();
        $process = $draft->process;
        $this->form->fill([
            ...$draft->only(ProcessVersion::CONTENT),
            'steps' => array_map(fn (array $s): array => [...$s, 'work_link' => (array) ($s['work_link'] ?? [])], (array) $draft->steps),
            ...$process->only(['area_id', 'category', 'business_line', 'kind', 'owner_user_id', 'approver_user_id', 'visibility', 'ai_excluded']),
            'position_ids' => $process->positions()->pluck('clarto_positions.id')->all(),
            'audiences' => $process->audiences()->get(['type', 'target_id', 'effect'])->toArray(),
        ]);
    }

    public function draft(): ProcessVersion
    {
        $draft = ProcessVersion::query()->whereKey($this->versionId)->where('process_id', $this->processId)->firstOrFail();
        abort_unless($draft->status === 'borrador' && ProcessAccess::canEdit(ProcessUi::user(), $draft->process), 404);

        return $draft;
    }

    public function getTitle(): string
    {
        return __('procesos.editor.titulo', ['code' => Process::query()->whereKey($this->processId)->value('code')]);
    }

    public function form(Schema $schema): Schema
    {
        $team = ProcessUi::team();
        $positions = fn (): array => Position::options($team);
        $md = fn (string $name): Textarea => Textarea::make($name)->label(__("procesos.campos.{$name}"))->rows(3)->helperText(__('procesos.editor.markdown'));
        $relativeOrHttps = ['nullable', 'regex:#^(/[^/]|https://)#'];

        return $schema->statePath('data')->components([
            Tabs::make('editor')->persistTabInQueryString()->tabs([
                Tab::make(__('procesos.editor.tab_ficha'))->schema([
                    TextInput::make('title')->label(__('procesos.campos.title'))->required()->maxLength(160),
                    TextInput::make('summary')->label(__('procesos.campos.summary'))->maxLength(300)->helperText(__('procesos.editor.summary_ayuda')),
                    $md('objective'), $md('expected_result'),
                    $md('trigger'), $md('requirements'), $md('required_data'),
                    TagsInput::make('keywords')->label(__('procesos.campos.keywords'))->helperText(__('procesos.editor.keywords_ayuda')),
                    Textarea::make('change_summary')->label(__('procesos.campos.change_summary'))->rows(2),
                ]),
                Tab::make(__('procesos.editor.tab_pasos'))->schema([
                    Repeater::make('steps')->hiddenLabel()->reorderable()->collapsible()->cloneable(false)
                        ->itemLabel(fn (array $state): string => (string) ($state['title'] ?? ''))
                        ->addActionLabel(__('procesos.editor.anadir_paso'))
                        ->schema([
                            Hidden::make('key')->default(fn (): string => Processes::newKey()),
                            TextInput::make('title')->label(__('procesos.campos.step_title'))->required()->maxLength(160),
                            Grid::make(2)->schema([
                                Select::make('actor_position_id')->label(__('procesos.campos.actor_position'))->options($positions),
                                TextInput::make('actor_label')->label(__('procesos.campos.actor_label'))->maxLength(80)->placeholder(__('procesos.editor.actor_ejemplo')),
                            ]),
                            Textarea::make('action')->label(__('procesos.campos.action'))->rows(3),
                            Textarea::make('done_when')->label(__('procesos.campos.done_when'))->rows(2),
                            Grid::make(2)->schema([
                                TextInput::make('evidence')->label(__('procesos.campos.evidence'))->maxLength(300),
                                TextInput::make('deadline')->label(__('procesos.campos.deadline'))->maxLength(120),
                            ]),
                            Fieldset::make(__('procesos.campos.work_link'))->statePath('work_link')->schema([
                                TextInput::make('label')->label(__('procesos.campos.work_link_label'))->maxLength(80),
                                TextInput::make('url')->label(__('procesos.campos.work_link_url'))->maxLength(300)->rules($relativeOrHttps)
                                    ->validationMessages(['regex' => __('procesos.errores.url')]),
                            ]),
                            Grid::make(2)->schema([
                                Select::make('tarmio_kind')->label(__('procesos.campos.tarmio_kind'))->options(__('procesos.tarmio')),
                                Select::make('decision_key')->label(__('procesos.campos.decision_key'))->options(fn (): array => $this->decisionOptions()),
                            ]),
                            $this->blocksBuilder('blocks')->label(__('procesos.campos.blocks')),
                        ]),
                ]),
                Tab::make(__('procesos.editor.tab_decisiones'))->schema([
                    Toggle::make('no_decisions')->label(__('procesos.campos.no_decisions')),
                    Repeater::make('decisions')->label(__('procesos.campos.decisions'))->collapsible()
                        ->itemLabel(fn (array $state): string => (string) ($state['question'] ?? ''))
                        ->schema([
                            Hidden::make('key')->default(fn (): string => Processes::newKey()),
                            TextInput::make('question')->label(__('procesos.campos.question'))->required()->maxLength(200),
                            Select::make('decider_position_id')->label(__('procesos.campos.decider'))->options($positions),
                            Repeater::make('options')->label(__('procesos.campos.options'))->minItems(2)->defaultItems(2)->grid(2)->schema([
                                TextInput::make('label')->label(__('procesos.campos.option_label'))->required()->maxLength(80),
                                Select::make('goto')->label(__('procesos.campos.goto'))->required()->options(fn (): array => $this->gotoOptions()),
                                Textarea::make('note')->label(__('procesos.campos.note'))->rows(2),
                            ]),
                        ]),
                    Repeater::make('exceptions')->label(__('procesos.campos.exceptions'))->collapsible()->schema([
                        TextInput::make('case')->label(__('procesos.campos.case'))->required()->maxLength(200),
                        Textarea::make('handling')->label(__('procesos.campos.handling'))->rows(2),
                        TextInput::make('escalate_to')->label(__('procesos.campos.escalate_to'))->maxLength(120),
                    ]),
                    $md('escalation'),
                ]),
                Tab::make(__('procesos.editor.tab_herramientas'))->schema([
                    Repeater::make('tools')->label(__('procesos.campos.tools'))->collapsible()->schema([
                        Grid::make(2)->schema([
                            Select::make('type')->label(__('procesos.campos.tool_type'))->options(__('procesos.herramienta_tipos'))->default('liberu')->required(),
                            TextInput::make('name')->label(__('procesos.campos.name'))->required()->maxLength(160),
                            TextInput::make('url')->label(__('procesos.campos.url'))->maxLength(300)->rules($relativeOrHttps)->validationMessages(['regex' => __('procesos.errores.url')]),
                            Select::make('attachment_id')->label(__('procesos.campos.attachment'))->options(fn (): array => $this->attachmentOptions()),
                        ]),
                        TextInput::make('note')->label(__('procesos.campos.note'))->maxLength(200),
                    ]),
                    $md('evidences'), $md('deadlines'),
                    Repeater::make('kpis')->label(__('procesos.campos.kpis'))->grid(2)->schema([
                        TextInput::make('name')->label(__('procesos.campos.name'))->required()->maxLength(160),
                        TextInput::make('target')->label(__('procesos.campos.target'))->maxLength(120),
                        TextInput::make('frequency')->label(__('procesos.campos.frequency'))->maxLength(60),
                        TextInput::make('source')->label(__('procesos.campos.source'))->maxLength(160),
                    ]),
                    Repeater::make('related')->label(__('procesos.campos.related'))->grid(2)->schema([
                        TextInput::make('code')->label(__('procesos.campos.code'))->required()->maxLength(12),
                        Select::make('relation')->label(__('procesos.campos.relation'))->options(__('procesos.relaciones'))->default('relacionado')->required(),
                    ]),
                ]),
                Tab::make(__('procesos.editor.tab_contenido'))->schema([
                    Textarea::make('diagram_mermaid')->label(__('procesos.campos.diagram_mermaid'))->rows(8)->helperText(__('procesos.editor.mermaid_ayuda')),
                    $this->blocksBuilder('blocks')->label(__('procesos.secciones.contenido')),
                ]),
                Tab::make(__('procesos.editor.tab_proceso'))->schema([
                    Grid::make(2)->schema([
                        Select::make('area_id')->label(__('procesos.campos.area'))->required()
                            ->options(fn (): array => Area::query()->where('team_id', $team)->orderBy('sort')->get()->mapWithKeys(fn (Area $a): array => [$a->id => $a->label()])->all()),
                        Select::make('category')->label(__('procesos.campos.category'))->required()->options(ProcessUi::options('categorias', Process::CATEGORIES)),
                        Select::make('business_line')->label(__('procesos.campos.business_line'))->required()->options(ProcessUi::options('lineas', Process::BUSINESS_LINES)),
                        Select::make('kind')->label(__('procesos.campos.kind'))->required()->options(ProcessUi::options('formatos', Process::KINDS))->helperText(__('procesos.editor.kind_ayuda')),
                        Select::make('owner_user_id')->label(__('procesos.campos.owner'))->searchable()->options(fn (): array => ProcessUi::users($team)),
                        Select::make('approver_user_id')->label(__('procesos.campos.approver'))->searchable()->options(fn (): array => ProcessUi::users($team)),
                    ]),
                    Select::make('position_ids')->label(__('procesos.campos.positions'))->multiple()->options($positions),
                    Select::make('visibility')->label(__('procesos.campos.visibility'))->required()->live()->options(ProcessUi::options('visibilidades', Process::VISIBILITIES)),
                    Repeater::make('audiences')->label(__('procesos.campos.audience'))->visible(fn ($get): bool => $get('visibility') === 'restringido')
                        ->helperText(__('procesos.editor.audiencia_ayuda'))->grid(3)->schema([
                            Select::make('type')->label(__('procesos.campos.type'))->required()->live()->options(__('procesos.audiencia_tipos')),
                            Select::make('target_id')->label(__('procesos.campos.target_id'))->required()->searchable()
                                ->options(fn ($get): array => match ($get('type')) {
                                    'area' => Area::query()->where('team_id', $team)->get()->mapWithKeys(fn (Area $a): array => [$a->id => $a->label()])->all(),
                                    'position' => Position::options($team),
                                    'user' => ProcessUi::users($team),
                                    default => [],
                                }),
                            Select::make('effect')->label(__('procesos.campos.effect'))->required()->default('allow')
                                ->options(fn ($get): array => $get('type') === 'user' ? __('procesos.audiencia_efectos') : ['allow' => __('procesos.audiencia_efectos.allow')]),
                        ]),
                    Toggle::make('ai_excluded')->label(__('procesos.campos.ai_excluded'))->helperText(__('procesos.editor.ia_ayuda')),
                ]),
            ]),
        ]);
    }

    private function blocksBuilder(string $name): Builder
    {
        $upload = fn (string $kind): FileUpload => FileUpload::make('upload')->label(__('procesos.campos.file'))
            ->disk(ProcessAttachment::DISK)->directory(fn (): string => ProcessAttachment::uploadDirectory((int) auth()->id()))->visibility('private')
            ->storeFileNamesIn('upload_name')->acceptedFileTypes(array_keys(ProcessAttachment::TYPES[$kind][0]))
            ->maxSize((int) (ProcessAttachment::TYPES[$kind][1] / 1024))->helperText(fn ($get): ?string => $this->attachmentName($get('attachment_id')));

        return Builder::make($name)->collapsible()->blockNumbers(false)->addActionLabel(__('procesos.editor.anadir_bloque'))->blocks([
            Block::make('text')->label(__('procesos.bloques.text'))->schema([Textarea::make('markdown')->hiddenLabel()->rows(4)->required()]),
            Block::make('callout')->label(__('procesos.bloques.callout'))->schema([
                Select::make('tone')->label(__('procesos.campos.tone'))->options(__('procesos.tonos'))->default('info')->required(),
                Textarea::make('markdown')->label(__('procesos.campos.markdown'))->rows(3)->required(),
            ]),
            Block::make('image')->label(__('procesos.bloques.image'))->schema([
                Hidden::make('attachment_id'), Hidden::make('upload_name'), $upload('image')->image(),
                TextInput::make('alt')->label(__('procesos.campos.alt'))->maxLength(200)->helperText(__('procesos.editor.alt_ayuda')),
                TextInput::make('caption')->label(__('procesos.campos.caption'))->maxLength(300),
            ]),
            Block::make('video')->label(__('procesos.bloques.video'))->schema([
                Hidden::make('attachment_id'), Hidden::make('upload_name'), $upload('video'),
                TextInput::make('url')->label(__('procesos.campos.video_url'))->maxLength(300)->helperText(__('procesos.editor.video_ayuda'))
                    ->rules(['nullable', 'regex:#^https://(www\.)?(youtube\.com|youtu\.be|vimeo\.com)/#'])->validationMessages(['regex' => __('procesos.errores.video')]),
                TextInput::make('caption')->label(__('procesos.campos.caption'))->maxLength(300),
            ]),
            Block::make('file')->label(__('procesos.bloques.file'))->schema([
                Hidden::make('attachment_id'), Hidden::make('upload_name'), $upload('file'),
                TextInput::make('label')->label(__('procesos.campos.label'))->maxLength(160),
            ]),
            Block::make('link')->label(__('procesos.bloques.link'))->schema([
                TextInput::make('url')->label(__('procesos.campos.url'))->required()->maxLength(300)->rules(['regex:#^(/[^/]|https://)#'])->validationMessages(['regex' => __('procesos.errores.url')]),
                TextInput::make('label')->label(__('procesos.campos.label'))->maxLength(160),
            ]),
            Block::make('diagram')->label(__('procesos.bloques.diagram'))->schema([
                Textarea::make('mermaid')->label(__('procesos.campos.mermaid'))->rows(6)->required(),
                TextInput::make('caption')->label(__('procesos.campos.caption'))->maxLength(300),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $draft = $this->draft();
        $gaps = $draft->publicationGaps();

        return $schema->components([
            Section::make(__('procesos.editor.completitud'))->collapsible()->collapsed($gaps === [])
                ->description($gaps === [] ? __('procesos.editor.completo') : __('procesos.editor.faltan', ['n' => count($gaps)]))
                ->schema(array_map(fn (string $g): Text => Text::make('• '.$g), [...$gaps, ...$draft->publicationWarnings()]) ?: [Text::make(__('procesos.editor.listo'))]),
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('save')->label(__('procesos.editor.guardar'))->submit('save'),
                    Action::make('saveAndSubmit')->label(__('procesos.editor.guardar_enviar'))->color('success')->action('saveAndSubmit'),
                    Action::make('ver')->label(__('procesos.editor.vista_previa'))->color('gray')->url(fn (): string => ProcessLinks::version($this->draft())),
                ])]),
        ]);
    }

    public function save(): void
    {
        $this->persist();
        Notification::make()->title(__('procesos.editor.guardado'))->success()->send();
        $this->fillForm();
    }

    public function saveAndSubmit(): void
    {
        $this->persist();
        try {
            Processes::submit(ProcessUi::user(), $this->draft());
        } catch (ValidationException $e) {
            Notification::make()->title(__('procesos.editor.no_enviado'))->body(implode("\n", array_merge(...array_values($e->errors()))))->warning()->persistent()->send();
            $this->fillForm();

            return;
        }
        Notification::make()->title(__('procesos.ver.enviado'))->success()->send();
        $this->redirect(ProcessLinks::process($this->draft()->process));
    }

    private function persist(): void
    {
        $user = ProcessUi::user();
        $draft = $this->draft();
        $state = $this->form->getState();

        $steps = array_map(function (array $s) use ($user, $draft): array {
            $link = array_filter((array) ($s['work_link'] ?? []), 'filled');

            return [
                'key' => filled($s['key'] ?? null) ? $s['key'] : Processes::newKey(),
                'title' => $s['title'] ?? null, 'actor_position_id' => filled($s['actor_position_id'] ?? null) ? (int) $s['actor_position_id'] : null,
                'actor_label' => $s['actor_label'] ?? null, 'action' => $s['action'] ?? null, 'done_when' => $s['done_when'] ?? null,
                'evidence' => $s['evidence'] ?? null, 'deadline' => $s['deadline'] ?? null,
                'work_link' => filled($link['url'] ?? null) ? ['label' => $link['label'] ?? __('procesos.ver.ir_trabajo'), 'url' => $link['url']] : null,
                'tarmio_kind' => $s['tarmio_kind'] ?? null, 'decision_key' => $s['decision_key'] ?? null,
                'blocks' => $this->storeUploads((array) ($s['blocks'] ?? []), $user, $draft),
            ];
        }, array_values((array) ($state['steps'] ?? [])));
        $decisions = array_map(fn (array $d): array => [
            'key' => filled($d['key'] ?? null) ? $d['key'] : Processes::newKey(), 'question' => $d['question'] ?? null,
            'decider_position_id' => filled($d['decider_position_id'] ?? null) ? (int) $d['decider_position_id'] : null,
            'options' => array_values(array_map(fn (array $o): array => ['label' => $o['label'] ?? null, 'goto' => $o['goto'] ?? 'end', 'note' => $o['note'] ?? null], (array) ($d['options'] ?? []))),
        ], array_values((array) ($state['decisions'] ?? [])));
        $list = fn (string $key): array => array_values((array) ($state[$key] ?? []));

        Processes::saveDraft($user, $draft, [
            ...array_intersect_key($state, array_flip(['title', 'summary', 'objective', 'expected_result', 'trigger', 'requirements', 'required_data', 'escalation', 'evidences', 'deadlines', 'diagram_mermaid', 'change_summary', 'no_decisions'])),
            'keywords' => array_values((array) ($state['keywords'] ?? [])),
            'steps' => $steps, 'decisions' => $decisions,
            'exceptions' => $list('exceptions'), 'kpis' => $list('kpis'), 'related' => $list('related'),
            'tools' => array_map(fn (array $t): array => [...$t, 'attachment_id' => filled($t['attachment_id'] ?? null) ? (int) $t['attachment_id'] : null], $list('tools')),
            'blocks' => $this->storeUploads($list('blocks'), $user, $draft),
        ]);
        Processes::updateMetadata($user, $draft->process, array_intersect_key($state, array_flip(['area_id', 'category', 'business_line', 'kind', 'owner_user_id', 'approver_user_id', 'visibility', 'ai_excluded'])),
            array_map('intval', (array) ($state['position_ids'] ?? [])),
            ($state['visibility'] ?? 'empresa') === 'restringido' ? $list('audiences') : null);
    }

    /** New uploads in image / video / file blocks become private attachments of the draft (attachment_id). */
    private function storeUploads(array $blocks, \App\Models\User $user, ProcessVersion $draft): array
    {
        return array_values(array_map(function (array $block) use ($user, $draft): array {
            $data = (array) ($block['data'] ?? []);
            $path = $data['upload'] ?? null;
            $path = is_array($path) ? (reset($path) ?: null) : $path;
            if (filled($path) && in_array($block['type'] ?? '', ['image', 'video', 'file'], true)) {
                $names = (array) ($data['upload_name'] ?? []);
                $name = is_string($data['upload_name'] ?? null) ? $data['upload_name'] : ($names[$path] ?? basename((string) $path));
                $data['attachment_id'] = ProcessAttachment::store($user, $draft, (string) $path, (string) $name, $block['type'])->getKey();
            }
            unset($data['upload'], $data['upload_name']);
            if (isset($data['attachment_id'])) {
                $data['attachment_id'] = filled($data['attachment_id']) ? (int) $data['attachment_id'] : null;
            }

            return ['type' => $block['type'], 'data' => $data];
        }, $blocks));
    }

    /** @return array<string, string> */
    public function gotoOptions(): array
    {
        $options = [];
        foreach (array_values((array) ($this->data['steps'] ?? [])) as $i => $s) {
            if (filled($s['key'] ?? null)) {
                $options['step:'.$s['key']] = __('procesos.ver.paso_n', ['n' => $i + 1]).'. '.($s['title'] ?? '');
            }
        }
        $options['end'] = __('procesos.ver.fin');
        foreach (Process::query()->where('team_id', ProcessUi::team())->whereKeyNot($this->processId)->orderBy('code')->pluck('code') as $code) {
            $options['process:'.$code] = __('procesos.ver.ir_proceso', ['code' => $code]);
        }

        return $options;
    }

    /** @return array<string, string> */
    public function decisionOptions(): array
    {
        return collect((array) ($this->data['decisions'] ?? []))->filter(fn ($d) => filled($d['key'] ?? null))
            ->mapWithKeys(fn (array $d): array => [$d['key'] => (string) ($d['question'] ?: '—')])->all();
    }

    /** @return array<int, string> */
    public function attachmentOptions(): array
    {
        return ProcessAttachment::query()->where('process_version_id', $this->versionId)->pluck('original_name', 'id')->all();
    }

    public function attachmentName(mixed $id): ?string
    {
        $name = filled($id) ? ProcessAttachment::query()->where('process_version_id', $this->versionId)->whereKey($id)->value('original_name') : null;

        return $name ? __('procesos.editor.adjunto_actual', ['name' => $name]) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('plantilla')->label(__('procesos.editor.guia'))->color('gray')->icon('heroicon-o-light-bulb')
                ->modalSubmitAction(false)->modalCancelActionLabel(__('procesos.ver.cerrar'))
                ->modalContent(fn () => new \Illuminate\Support\HtmlString('<div style="line-height:1.6">'.e(__('procesos.editor.guia_texto')).'<br><br><strong>'
                    .e(ProcessTemplates::options()[$this->draft()->process->template_key ?? 'instruccion_breve'] ?? '').'</strong></div>')),
            Action::make('volver')->label(__('procesos.editor.volver'))->color('gray')->url(fn (): string => ProcessLinks::version($this->draft())),
        ];
    }
}
