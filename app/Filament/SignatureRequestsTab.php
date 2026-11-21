<?php

declare(strict_types=1);

namespace App\Filament;

use App\Filament\Pages\SendForSignature;
use App\Filament\Resources\FormTemplateResource;
use App\Filament\Resources\SignatureRequestResource;
use App\Signing\SignatureRequest;
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Model;

/** "Signatures" tab of the property form and of the owner 360 page: documents sent for signature and a button to prepare one. */
final class SignatureRequestsTab
{
    public static function forProperty(): Tab
    {
        return self::tab('property_id', 'inmueble');
    }

    public static function forOwner(): Tab
    {
        return self::tab('party_id', 'propietario');
    }

    private static function tab(string $column, string $param): Tab
    {
        return Tab::make(__('Signatures'))->icon('heroicon-o-pencil-square')
            ->visible(fn (?Model $record): bool => $record !== null && FormTemplateResource::staff())
            ->schema([
                Html::make(fn (?Model $record): string => $record ? self::table($column, $record) : ''),
                Actions::make([
                    Action::make('prepare_signature_'.$param)->label(__('Prepare document for signature'))->icon('heroicon-m-paper-airplane')
                        ->url(fn (?Model $record): ?string => $record ? SendForSignature::getUrl([$param => $record->getKey()]) : null),
                ]),
            ]);
    }

    private static function table(string $column, Model $record): string
    {
        $rows = SignatureRequest::query()->where($column, $record->getKey())->latest('id')->limit(50)->get();
        if ($rows->isEmpty()) {
            return '<p style="opacity:.7">'.e(__('No documents sent for signature yet.')).'</p>';
        }
        $cell = 'padding:.4rem .6rem;border-bottom:1px solid rgba(128,128,128,.15);vertical-align:top';
        $head = implode('', array_map(fn (string $h): string => '<th style="text-align:left;padding:.4rem .6rem;font-weight:600;border-bottom:1px solid rgba(128,128,128,.3)">'.e(__($h)).'</th>', ['Document', 'Status', 'Sent', 'Signed']));
        $body = $rows->map(fn (SignatureRequest $r): string => '<tr>'
            .'<td style="'.$cell.'"><a href="'.e(SignatureRequestResource::getUrl('view', ['record' => $r])).'" style="color:var(--primary-600);text-decoration:underline">'.e($r->title).'</a>'
            .($r->driver === 'simulado' ? ' <span style="opacity:.6">('.e(__('simulation')).')</span>' : '').'</td>'
            .'<td style="'.$cell.'">'.e($r->statusLabel()).'</td>'
            .'<td style="'.$cell.'">'.e($r->sent_at?->timezone('Europe/Madrid')->format('d/m/Y H:i') ?? '—').'</td>'
            .'<td style="'.$cell.'">'.e($r->completed_at?->timezone('Europe/Madrid')->format('d/m/Y H:i') ?? '—').'</td>'
            .'</tr>')->implode('');

        return '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.875rem"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }
}
