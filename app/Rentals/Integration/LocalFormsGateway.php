<?php

declare(strict_types=1);

namespace App\Rentals\Integration;

use App\Rentals\Models\RentalDocument;
use App\Rentals\Occupancies;

/** Local «send to Formularios»: the document stays prepared in CLARTO; signature is recorded as requested, not sent. */
final class LocalFormsGateway implements FormsGateway
{
    public function prepare(RentalDocument $document, array $signers, bool $sendToSignature): string
    {
        $reference = 'FORM-LOCAL-'.$document->getKey();
        Occupancies::record($document->occupancy, $sendToSignature ? 'firma_solicitada' : 'documento_preparado', [
            'documento_id' => $document->getKey(), 'tipo' => $document->kind, 'plantilla' => $document->template,
            'firmantes' => array_map(fn (array $s): string => $s['role'].': '.$s['name'], $signers), 'driver' => 'local',
        ]);

        return $reference;
    }
}
