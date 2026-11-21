<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\FormTemplateResource;
use App\Filament\Resources\SignatureRequestResource;
use App\Signing\FormTemplate;
use App\Signing\Signing;
use App\Signing\SigningFailed;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Prepare a document for signature from the owner, the property or the mandate (?propietario=, ?inmueble=,
 * ?encargo=): choose a «Formulario» (or attach a PDF), fields prefilled from the 360 data, missing fields shown,
 * preview, and only then send to one or several signers.
 */
final class SendForSignature extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $slug = 'enviar-a-firma';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Hash of the document last previewed: sending requires a preview of exactly what is sent. */
    public ?string $previewedHash = null;

    public static function canAccess(): bool
    {
        return FormTemplateResource::staff();
    }

    public function getTitle(): string
    {
        return __('Prepare document for signature');
    }

    public function mount(): void
    {
        $id = fn (string $key): ?int => is_numeric(request()->query($key)) ? (int) request()->query($key) : null;
        $this->form->fill([
            'mode' => 'plantilla', 'signature_level' => 'simple',
            'party_id' => $id('propietario'), 'property_id' => $id('inmueble'), 'instruction_id' => $id('encargo'),
            'template_id' => $id('formulario'), 'values' => [], 'signers' => [],
        ]);
        $this->refill(true);
    }

    public function form(Schema $schema): Schema
    {
        $template = fn (Get $get): ?FormTemplate => filled($get('template_id')) ? FormTemplate::query()->find($get('template_id')) : null;

        return $schema->statePath('data')->components([
            Section::make(__('File'))->columns(3)
                ->description(__('Link the document to the owner, the property and the mandate it belongs to; their data fill the form.'))
                ->schema([
                    Select::make('party_id')->label(__('Owner'))->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search): array => Party::query()->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(25)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Party::query()->find($value)?->name)
                        ->afterStateUpdated(fn () => $this->refill()),
                    Select::make('property_id')->label(__('Property'))->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search): array => Property::query()->where('address', 'like', '%'.$search.'%')->orWhere('id', (int) Str::afterLast($search, '-'))->limit(25)->get()
                            ->mapWithKeys(fn (Property $p): array => [$p->getKey() => $p->reference().' · '.Str::limit((string) $p->address, 50)])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($p = Property::query()->find($value)) ? $p->reference().' · '.Str::limit((string) $p->address, 50) : null)
                        ->afterStateUpdated(fn () => $this->refill()),
                    Select::make('instruction_id')->label(__('Mandate'))->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search): array => Instruction::query()->where('subject', 'like', '%'.$search.'%')->latest('id')->limit(25)->pluck('subject', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Instruction::query()->find($value)?->subject)
                        ->afterStateUpdated(fn () => $this->refill()),
                ]),
            Section::make(__('Document'))->columns(2)
                ->description(fn (): string => config('firma.driver') === 'docuseal'
                    ? __('Real sends are ON (DocuSeal :env).', ['env' => config('firma.docuseal.environment')])
                    : __('Real sends are OFF: the send is simulated and nothing leaves the server until DocuSeal is configured.'))
                ->schema([
                    Radio::make('mode')->label(__('Source'))->inline()->live()->options(fn (): array => ['plantilla' => __('Form'), 'pdf' => __('Attach a PDF')]),
                    TextInput::make('title')->label(__('Title'))->required()->maxLength(200),
                    Select::make('template_id')->label(__('Form'))->live()->required(fn (Get $get): bool => $get('mode') === 'plantilla')
                        ->options(fn (): array => FormTemplate::query()->where('status', 'activo')->orderBy('name')->get()
                            ->mapWithKeys(fn (FormTemplate $t): array => [$t->getKey() => $t->name.' · v'.$t->version.' · '.__(FormTemplate::LEVELS[$t->signature_level])])->all())
                        ->visible(fn (Get $get): bool => $get('mode') === 'plantilla')
                        ->afterStateUpdated(fn () => $this->refill(true)),
                    FileUpload::make('attachment')->label(__('PDF'))->disk(fn () => config('firma.disk'))->directory('firma/adjuntos')->visibility('private')
                        ->acceptedFileTypes(['application/pdf'])->maxSize(15 * 1024)
                        ->helperText(__('Signature fields as DocuSeal text tags in the PDF, e.g. {{Firma;role=Propietario;type=signature}}.'))
                        ->visible(fn (Get $get): bool => $get('mode') === 'pdf')->required(fn (Get $get): bool => $get('mode') === 'pdf'),
                    Select::make('signature_level')->label(__('Signature level'))->options(fn () => array_map('__', FormTemplate::LEVELS))
                        ->visible(fn (Get $get): bool => $get('mode') === 'pdf'),
                ]),
            Section::make(__('Data of the document'))->columns(2)
                ->visible(fn (Get $get): bool => $get('mode') === 'plantilla' && $template($get) !== null)
                ->schema(fn (Get $get): array => [
                    Text::make(fn (Get $get): string => ($m = $template($get) ? Signing::missing($template($get), (array) $get('values')) : []) === []
                        ? __('All required data is filled in.') : __('Missing: :fields', ['fields' => implode(' · ', $m)]))
                        ->color(fn (Get $get): string => $template($get) && Signing::missing($template($get), (array) $get('values')) !== [] ? 'danger' : 'success')
                        ->columnSpanFull(),
                    ...array_map(fn (array $f) => TextInput::make('values.'.$f['key'])->label($f['label'] ?? $f['key'])
                        ->live(onBlur: true)->markAsRequired((bool) ($f['required'] ?? false))
                        ->helperText(filled($f['source'] ?? null) ? __('From: :source', ['source' => $f['source']]) : __('Typed in')),
                        (array) $template($get)?->fields),
                ]),
            Section::make(__('Signers'))
                ->description(__('The provider sends each signer the link by e-mail; the SMS with the link or a reminder is sent from the request. A code by SMS is sent by DocuSeal itself and does not change the signature level.'))
                ->schema([
                    Repeater::make('signers')->hiddenLabel()->columns(5)->addActionLabel(__('Add signer'))->schema([
                        TextInput::make('role')->label(__('Role'))->datalist(fn (Get $get): array => collect((array) FormTemplate::query()->find($get('../../template_id'))?->roles)->pluck('key')->all()),
                        TextInput::make('name')->label(__('Name')),   // checked on send (Signing::signers), so the preview is never blocked by them
                        TextInput::make('email')->label(__('E-mail'))->email(),
                        TextInput::make('phone')->label(__('Mobile'))->tel(),
                        Toggle::make('require_phone_2fa')->label(__('Code by SMS'))->inline(false),
                    ]),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->footer([Actions::make([
                Action::make('preview')->label(__('Preview'))->icon('heroicon-m-eye')->color('gray')->action(fn () => $this->preview()),
                Action::make('send')->label(__('Send for signature'))->icon('heroicon-m-paper-airplane')
                    ->disabled(fn (): bool => $this->previewedHash === null)
                    ->requiresConfirmation()->modalDescription(__('The document you previewed is sent to the signers. This cannot be undone.'))
                    ->action(fn () => $this->send()),
            ])]),
        ]);
    }

    public function preview(): mixed
    {
        $input = $this->input();
        $template = filled($input['template_id']) ? FormTemplate::query()->find($input['template_id']) : null;
        $pdf = Signing::documentPdf($template, $input['values'], $input['title'], $input['attachment']);
        $this->previewedHash = $this->hash($input);

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf;
        }, 'vista-previa.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function send(): void
    {
        $input = $this->input();
        if ($this->previewedHash !== $this->hash($input)) {
            $this->previewedHash = null;
            Notification::make()->title(__('The document changed since the preview: preview it again before sending.'))->warning()->send();

            return;
        }

        try {
            $request = Signing::make()->send($input, auth()->id());
        } catch (SigningFailed $e) {
            Notification::make()->title(__('Not sent'))->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title($request->driver === 'simulado' ? __('Send simulated (real sends are OFF)') : __('Sent for signature'))->success()->send();
        $this->redirect(SignatureRequestResource::getUrl('view', ['record' => $request]));
    }

    /** Prefills the values and default signers from the linked owner/property/mandate. */
    private function refill(bool $templateChanged = false): void
    {
        $id = fn (string $k): ?int => filled($this->data[$k] ?? null) ? (int) $this->data[$k] : null;
        $ctx = Signing::context(
            $id('party_id') ? Party::query()->find($id('party_id')) : null,
            $id('property_id') ? Property::query()->find($id('property_id')) : null,
            $id('instruction_id') ? Instruction::query()->find($id('instruction_id')) : null,
        );
        foreach (['party_id' => 'party', 'property_id' => 'property', 'instruction_id' => 'instruction'] as $field => $model) {
            $this->data[$field] ??= $ctx['_models'][$model]?->getKey();
        }

        $template = $id('template_id') ? FormTemplate::query()->find($id('template_id')) : null;
        if ($template !== null) {
            $prefilled = Signing::prefill($template, $ctx)['values'];
            $typed = array_filter((array) ($this->data['values'] ?? []), 'filled');
            $values = [];
            foreach ($prefilled as $key => $value) {   // data from the file wins; what staff typed stays where there is none
                $values[$key] = $value ?? ($typed[$key] ?? null);
            }
            $this->data['values'] = $values;
            if ($templateChanged || blank($this->data['title'] ?? null)) {
                $this->data['title'] = $template->name;
            }
        }
        if ($templateChanged || empty($this->data['signers'])) {
            $this->data['signers'] = collect(Signing::defaultSigners($template, $ctx, auth()->user()))
                ->mapWithKeys(fn (array $s): array => [(string) Str::uuid() => $s])->all();
        }
        $this->previewedHash = null;
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $s = $this->form->getState();
        $pdf = ($s['mode'] ?? 'plantilla') === 'pdf';

        return [
            'template_id' => $pdf ? null : ($s['template_id'] ?? null),
            'attachment' => $pdf ? (is_array($s['attachment'] ?? null) ? (array_values($s['attachment'])[0] ?? null) : ($s['attachment'] ?? null)) : null,
            'signature_level' => $pdf ? ($s['signature_level'] ?? 'simple') : null,
            'title' => (string) ($s['title'] ?? ''),
            'party_id' => $s['party_id'] ?? null, 'property_id' => $s['property_id'] ?? null, 'instruction_id' => $s['instruction_id'] ?? null,
            'values' => $pdf ? [] : (array) ($s['values'] ?? []),
            'signers' => array_values((array) ($s['signers'] ?? [])),
        ];
    }

    /** Identity of the document content (not of the signers). @param array<string, mixed> $input */
    private function hash(array $input): string
    {
        return hash('sha256', (string) json_encode([$input['template_id'], $input['attachment'], $input['values'], $input['title']]));
    }
}
