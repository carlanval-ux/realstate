<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Documents\CompanyDocumentFolder;
use App\Filament\Resources\CompanyDocumentFolderResource\Pages\ManageCompanyDocumentFolders;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Folders of «Documentación de la empresa» (same responsables, /admin only): create, rename, move under another
 * folder. A folder with subfolders or documents cannot be deleted. Reached from the documents list («Carpetas»).
 */
final class CompanyDocumentFolderResource extends Resource
{
    protected static ?string $model = CompanyDocumentFolder::class;

    protected static ?string $slug = 'documentacion-carpetas';

    protected static bool $shouldRegisterNavigation = false;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('documentacion.folders.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('documentacion.folders.menu');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('documentacion.folders.name'))->required()->maxLength(120),
            Select::make('parent_id')->label(__('documentacion.folders.parent'))->placeholder(__('documentacion.folders.root'))->searchable()
                ->options(fn (?CompanyDocumentFolder $record): array => array_diff_key(
                    CompanyDocumentFolder::options(Filament::getTenant()?->getKey()),
                    array_flip($record?->withDescendantIds() ?? []),   // never inside itself
                )),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('path')->label(__('documentacion.folders.path'))->weight('medium')
                    ->state(fn (CompanyDocumentFolder $record): string => CompanyDocumentFolder::options($record->team_id)[$record->getKey()] ?? $record->name),
                TextColumn::make('documents_count')->label(__('documentacion.folders.documents'))->counts('documents')->alignCenter(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->failureNotificationTitle(__('documentacion.folders.not_empty'))
                    ->modalDescription(__('documentacion.folders.delete_help')),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompanyDocumentFolders::route('/')];
    }
}
