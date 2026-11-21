<?php

declare(strict_types=1);

namespace App\Filament;

use App\Albaranes\Albaran;
use App\Filament\Resources\AlbaranResource;
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Liberu\RealEstate\Properties\Models\Property;

/** «Albaranes» tab of the owner 360 page and of the property form: statuses, invoice linked back, «New albarán». */
final class AlbaranTabs
{
    public static function owner(): Tab
    {
        return self::tab('party', fn (Model $party): Collection => Albaran::query()->where('party_id', $party->getKey())->latest('service_date')->latest('id')->get());
    }

    public static function property(): Tab
    {
        return self::tab('property', fn (Model $property): Collection => Albaran::query()->where('property_id', $property->getKey())->latest('service_date')->latest('id')->get())
            ->visible(fn (?Property $record): bool => $record !== null);
    }

    /** @param callable(Model): Collection<int, Albaran> $albaranes */
    private static function tab(string $param, callable $albaranes): Tab
    {
        return Tab::make(__('albaranes.plural'))
            ->icon('heroicon-o-clipboard-document-list')
            ->schema([
                Text::make(fn (?Model $record): HtmlString => self::table($record ? $albaranes($record) : collect())),
                Actions::make([
                    Action::make('albaran_new_'.$param)->label(__('albaranes.actions.new'))->icon('heroicon-m-plus')
                        ->url(fn (?Model $record): string => AlbaranResource::getUrl('create', array_filter([$param => $record?->getKey()]))),
                ]),
            ]);
    }

    /** @param Collection<int, Albaran> $albaranes */
    private static function table(Collection $albaranes): HtmlString
    {
        if ($albaranes->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('albaranes.none')).'</p>');
        }

        $colors = [Albaran::DRAFT => '#6b7280', Albaran::PENDING => '#b45309', Albaran::SENT => '#1d4ed8', Albaran::ERROR => '#b91c1c', Albaran::INVOICED => '#15803d'];
        $head = collect(['number', 'service_date', 'total', 'status', 'invoice_number', 'invoice_date'])
            ->map(fn (string $f): string => '<th class="px-2 py-1 text-left">'.e(__('albaranes.fields.'.$f)).'</th>')->implode('');
        $rows = $albaranes->map(fn (Albaran $a): string => '<tr class="border-t border-gray-200 dark:border-white/10">'
            .'<td class="px-2 py-1"><a class="text-primary-600 underline" href="'.e(AlbaranResource::getUrl('edit', ['record' => $a])).'">'.e($a->number).'</a></td>'
            .'<td class="px-2 py-1">'.e($a->service_date?->format('d/m/Y')).'</td>'
            .'<td class="px-2 py-1">'.e(number_format((float) $a->total, 2, ',', '.')).' €</td>'
            .'<td class="px-2 py-1" style="color:'.$colors[$a->status].';font-weight:600" title="'.e((string) $a->last_error).'">'.e($a->statusLabel()).'</td>'
            .'<td class="px-2 py-1">'.e($a->invoice_number ?? '—').'</td>'
            .'<td class="px-2 py-1">'.e($a->invoice_date?->format('d/m/Y') ?? '—').'</td>'
            .'</tr>')->implode('');

        return new HtmlString('<table class="w-full text-sm"><thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>');
    }
}
