<?php

declare(strict_types=1);

namespace App\Filament;

use App\Ai\PropertyTextWriter;
use App\Models\PropertyText;
use App\Modules\ClartoModules;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * "Public texts" tab of the property form: title, short and long description and SEO in es, en and tr.
 *
 * Approved texts are what the website shows: Castilian title/description are the property's own columns,
 * the rest are PropertyText rows (status aprobado), saved per language through relationship groups. AI
 * proposals are PropertyText rows with status borrador, shown in an editable "AI draft" section with
 * Approve / Discard / Regenerate. Publishing never depends on en/tr: empty fields fall back to Castilian.
 */
final class PropertyPublicTexts
{
    public static function tab(): Tab
    {
        return Tab::make(__('Public texts'))
            ->icon('heroicon-o-language')
            ->schema([
                Text::make(fn (): ?string => ClartoModules::refusal(ClartoModules::IDIOMAS))
                    ->color('warning')
                    ->visible(fn (): bool => ! self::enabled()),
                Actions::make([
                    self::aiAction('generate_all', __('Generate ES · EN · TR with AI'), 'heroicon-m-sparkles',
                        __('The record is saved first. AI drafts are created in Spanish from the confirmed data and translated into English and Turkish. Nothing is published until you approve each draft.'),
                        fn (PropertyTextWriter $w, Property $r): array => $w->generateAll($r)),
                    self::aiAction('translate_approved', __('Translate approved Spanish to EN and TR'), 'heroicon-m-language',
                        __('The record is saved first. The approved Spanish texts are translated into English and Turkish as drafts.'),
                        fn (PropertyTextWriter $w, Property $r): array => $w->translateApproved($r)),
                ])->visible(fn (?Property $record): bool => $record !== null && self::enabled()),
                Tabs::make('public-texts')->tabs([
                    // Castilian title and long description are the property's own columns; the rest is its es row
                    // (one relationship group only: two groups on the same relation would both create it).
                    self::languageTab('es', [
                        TextInput::make('title')->label(__('Title'))->maxLength(255),
                        Textarea::make('description')->label(__('Long description'))->rows(8),
                        Group::make(self::fields(['summary', 'seo_title', 'seo_description']))
                            ->relationship('clartoTextEs', condition: self::hasContent(...))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['locale' => 'es', 'status' => PropertyText::APPROVED]),
                    ]),
                    self::languageTab('en'),
                    self::languageTab('tr'),
                ]),
            ]);
    }

    /** @param  list<\Filament\Schemas\Components\Component>|null  $schema */
    private static function languageTab(string $locale, ?array $schema = null): Tab
    {
        $suffix = ucfirst($locale);
        $schema ??= [
            // Module off: the editable group is hidden (a hidden relationship group is neither saved nor deleted) and
            // the existing translation is shown read-only.
            Group::make(self::fields(PropertyText::FIELDS))
                ->visible(fn (): bool => self::enabled())
                ->relationship('clartoText'.$suffix, condition: self::hasContent(...))
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['locale' => $locale, 'status' => PropertyText::APPROVED]),
            self::readOnly('clartoText'.$suffix),
        ];

        return Tab::make(PropertyText::LOCALES[$locale])
            ->extraAttributes(['lang' => $locale])
            ->badge(fn (?Property $record): ?string => ($n = count($record ? PropertyText::missing($record, $locale) : PropertyText::FIELDS)) > 0 ? __(':count pending', ['count' => $n]) : null)
            ->badgeColor('warning')
            ->schema([
                ...($locale === 'es' ? [] : [Text::make(__('Empty fields are shown in Spanish on the website until they are translated.'))->color('gray')]),
                Section::make(__('The Spanish text changed after this translation was approved: review it.'))
                    ->icon('heroicon-o-exclamation-triangle')->iconColor('warning')->compact()
                    ->visible(fn (?Property $record): bool => $locale !== 'es' && (bool) $record?->{'clartoText'.$suffix}()->whereNotNull('review_required_at')->exists())
                    ->schema([Actions::make([
                        Action::make("reviewed_{$locale}")->label(__('Mark as reviewed'))->icon('heroicon-m-check')->color('gray')
                            ->action(function (Property $record, $livewire) use ($suffix): void {
                                $record->{'clartoText'.$suffix}()->update(['review_required_at' => null]);
                                self::reload($livewire, $record);
                            }),
                    ])]),
                ...$schema,
                Section::make(__('AI draft (not published)'))
                    ->description(fn (?Property $record): ?string => ($d = $record?->{'clartoDraft'.$suffix}()->first())
                        ? trim(__('Model: :m', ['m' => $d->model ?? '—']).($d->notes ? "\n".__('Check: ').$d->notes : ''))
                        : null)
                    ->icon('heroicon-o-sparkles')->collapsible()
                    ->visible(fn (?Property $record): bool => (bool) $record?->{'clartoDraft'.$suffix}()->exists())
                    ->schema([
                        Group::make(self::fields(PropertyText::FIELDS))->visible(fn (): bool => self::enabled())->relationship('clartoDraft'.$suffix, condition: self::hasContent(...)),
                        self::readOnly('clartoDraft'.$suffix),
                        Actions::make([
                            Action::make("approve_{$locale}")->label(__('Approve draft'))->icon('heroicon-m-check-circle')->color('success')
                                ->visible(fn (): bool => self::enabled())
                                ->requiresConfirmation()->modalDescription(__('The draft replaces the approved text of this language and can be published.'))
                                ->action(function (Property $record, $livewire) use ($locale): void {
                                    $livewire->save(false, false);
                                    PropertyTextWriter::approve($record->refresh(), $locale);
                                    Notification::make()->title(__('Draft approved'))->success()->send();
                                    self::reload($livewire, $record);
                                }),
                            Action::make("discard_{$locale}")->label(__('Discard draft'))->icon('heroicon-m-trash')->color('danger')->requiresConfirmation()
                                ->action(function (Property $record, $livewire) use ($suffix): void {
                                    $record->{'clartoDraft'.$suffix}()->delete();
                                    self::reload($livewire, $record);
                                }),
                        ]),
                    ]),
                Actions::make([
                    self::aiAction("regenerate_{$locale}", $locale === 'es' ? __('Write Spanish draft with AI') : __('Translate to :lang with AI', ['lang' => PropertyText::LOCALES[$locale]]), 'heroicon-m-arrow-path',
                        __('The record is saved first and a new draft replaces the current draft of this language (approved texts are not touched).'),
                        fn (PropertyTextWriter $w, Property $r): array => $w->regenerate($r, $locale)),
                ])->visible(fn (?Property $record): bool => $record !== null && self::enabled()),
            ]);
    }

    /** @param  callable(PropertyTextWriter, Property): array{saved: list<string>, error: ?string}  $work */
    private static function aiAction(string $name, string $label, string $icon, string $description, callable $work): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color('gray')
            ->requiresConfirmation()->modalHeading($label)->modalDescription($description)
            ->action(function (Property $record, $livewire) use ($work): void {
                $livewire->save(false, false);
                $result = $work(PropertyTextWriter::make(), $record->refresh());
                $saved = implode(', ', array_map('strtoupper', $result['saved']));

                $notification = Notification::make()->title($result['error'] === null ? __('AI drafts ready: :l', ['l' => $saved]) : __('AI stopped'));
                $result['error'] === null
                    ? $notification->body(__('Review and approve each draft in its language tab.'))->success()
                    : $notification->body(($saved !== '' ? __('Saved before stopping: :l.', ['l' => $saved]).' ' : '').$result['error'])->warning()->persistent();
                $notification->send();

                self::reload($livewire, $record);
            });
    }

    /** «Idiomas y traducción» on: translations and AI drafts may be written (existing ones are always shown). */
    private static function enabled(): bool
    {
        return ClartoModules::enabled(ClartoModules::IDIOMAS);
    }

    /** The stored text of a relation, read-only, while the module is off (nothing is lost or hidden). */
    private static function readOnly(string $relation): Text
    {
        $labels = ['title' => __('Title'), 'summary' => __('Short description'), 'description' => __('Long description'), 'seo_title' => __('SEO title'), 'seo_description' => __('SEO description')];

        return Text::make(function (?Property $record) use ($relation, $labels): string {
            $text = $record?->{$relation}()->first();
            $lines = $text ? array_filter(array_map(fn (string $f): ?string => filled($text->{$f}) ? ($labels[$f] ?? $f).': '.$text->{$f} : null, PropertyText::FIELDS)) : [];

            return $lines === [] ? __('No text in this language yet.') : implode("\n", $lines);
        })->visible(fn (): bool => ! self::enabled());
    }

    private static function reload($livewire, Property $record): void
    {
        $livewire->redirect($livewire->getResource()::getUrl('edit', ['record' => $record]));
    }

    /**
     * @param  list<string>  $names
     * @return list<\Filament\Forms\Components\Field>
     */
    private static function fields(array $names): array
    {
        $all = [
            'title' => TextInput::make('title')->label(__('Title'))->maxLength(255),
            'summary' => Textarea::make('summary')->label(__('Short description'))->rows(2)->maxLength(500),
            'description' => Textarea::make('description')->label(__('Long description'))->rows(8),
            'seo_title' => TextInput::make('seo_title')->label(__('SEO title'))->maxLength(70),
            'seo_description' => Textarea::make('seo_description')->label(__('SEO description'))->rows(2)->maxLength(170),
        ];

        return array_values(array_intersect_key($all, array_flip($names)));
    }

    /** @param  array<string, mixed>|null  $state */
    private static function hasContent(?array $state): bool
    {
        return collect($state ?? [])->only(PropertyText::FIELDS)->filter(fn ($value): bool => filled($value))->isNotEmpty();
    }
}
