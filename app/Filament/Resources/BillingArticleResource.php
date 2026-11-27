<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Albaranes\AlbaranLine;
use App\Albaranes\BillingArticle;
use App\Filament\Resources\BillingArticleResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * «Artículos de albarán»: concepts created by hand to reuse on albarán lines. There is no predefined catalogue;
 * a line can always be written as free text. Also created from the line itself («Crear artículo»).
 */
final class BillingArticleResource extends Resource
{
    protected static ?string $model = BillingArticle::class;

    protected static ?string $slug = 'articulos-albaran';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 41;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('albaranes.articles.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('albaranes.articles.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('albaranes.articles.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.sales_lettings');
    }

    /** @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label(__('albaranes.fields.description'))->required()->maxLength(500)->columnSpanFull(),
            TextInput::make('reference')->label(__('albaranes.articles.reference'))->maxLength(30)
                ->helperText(__('albaranes.articles.reference_help'))
                ->unique(BillingArticle::class, 'reference', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('team_id', BillingArticle::currentTeamId())),
            TextInput::make('unit_price')->label(__('albaranes.fields.unit_price'))->numeric()->required()->default(0)->suffix('€'),
            Select::make('vat_pct')->label(__('albaranes.fields.vat'))->options(self::vatOptions())->required()->default('21.00'),
            Toggle::make('requires_conformity')->label(__('albaranes.articles.requires_conformity'))->helperText(__('albaranes.articles.requires_conformity_help')),
            Toggle::make('active')->label(__('albaranes.articles.active'))->default(true),
        ];
    }

    /** @return array<string, string> keys like the decimal:2 cast ("21.00") so saved values select the right option */
    public static function vatOptions(): array
    {
        return collect(AlbaranLine::VAT_RATES)->mapWithKeys(fn (int $r): array => [number_format($r, 2, '.', '') => $r.' %'])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(self::fields());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label(__('albaranes.articles.reference'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('name')->label(__('albaranes.fields.description'))->searchable()->wrap(),
                TextColumn::make('unit_price')->label(__('albaranes.fields.unit_price'))->money('EUR')->sortable(),
                TextColumn::make('vat_pct')->label(__('albaranes.fields.vat'))->formatStateUsing(fn ($state): string => (float) $state.' %'),
                IconColumn::make('requires_conformity')->label(__('albaranes.fields.conformity'))->boolean(),
                IconColumn::make('active')->label(__('albaranes.articles.active'))->boolean(),
                TextColumn::make('external_id')->label(__('albaranes.articles.in_facturalo'))->placeholder('—')->toggleable(),
            ])
            ->filters([TernaryFilter::make('active')->label(__('albaranes.articles.active'))->default(true)])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    /** Only the current company's articles. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('team_id', BillingArticle::currentTeamId());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBillingArticles::route('/')];
    }
}
