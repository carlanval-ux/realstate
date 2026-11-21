<?php

declare(strict_types=1);

namespace App\Rentals\Integration;

use App\Rentals\Models\RentalDocument;

/**
 * Integration point «send to Formularios» (prepare a contract, annex, inventory or record with prefilled values and,
 * when asked, send it to signature). Local implementation: LocalFormsGateway. After merging feat/formularios-firma,
 * bind an adapter over App\Signing\Signing (prefill + send).
 */
interface FormsGateway
{
    /**
     * @param  list<array{name: string, email: ?string, phone: ?string, role: string}>  $signers
     * @return string reference of the form / signature request
     */
    public function prepare(RentalDocument $document, array $signers, bool $sendToSignature): string;
}
