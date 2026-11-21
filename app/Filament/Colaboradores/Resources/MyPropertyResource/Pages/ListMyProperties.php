<?php

declare(strict_types=1);

namespace App\Filament\Colaboradores\Resources\MyPropertyResource\Pages;

use App\Collaborators\CollaboratorAccess;
use App\Collaborators\CollaboratorView;
use App\Filament\Colaboradores\Resources\MyPropertyResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Liberu\RealEstate\Properties\Models\Property;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ListMyProperties extends ListRecords
{
    protected static string $resource = MyPropertyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label(__('colaboradores.actions.export'))->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(fn (): StreamedResponse => self::export(auth()->user())),
        ];
    }

    /** CSV of the signed-in collaborator's properties: the same scoped query and presenter as the panel and the API. */
    public static function export(?object $user): StreamedResponse
    {
        $rows = CollaboratorAccess::scopeProperties(Property::query(), $user)->orderBy('id')->get()
            ->map(fn (Property $p): array => CollaboratorView::row($p, CollaboratorAccess::assignment($user, $p)));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['referencia', 'titulo', 'tipo', 'ubicacion', 'superficie_m2', 'habitaciones', 'banos', 'relacion', 'precio_autorizado', 'disponibilidad', 'estado_comercial', 'ultima_novedad'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['referencia'], $r['titulo'], $r['tipo'], $r['ubicacion'], $r['superficie_m2'], $r['habitaciones'], $r['banos'], $r['relacion'], $r['precio_autorizado'], $r['disponibilidad'], $r['estado_comercial'], $r['ultima_novedad']], ';');
            }
            fclose($out);
        }, 'mis-inmuebles-clarto.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
