<?php

namespace App\EInvoice;

use App\Models\Invoice;

interface EInvoiceGateway
{
    /**
     * @return array{
     *     provider_id: string,
     *     number: string,
     *     series: string,
     *     template: ?string,
     *     tax_authority_code: ?string,
     *     lookup_url: ?string,
     *     payload: array<string, mixed>
     * }
     */
    public function issue(Invoice $invoice): array;

    /**
     * @return array{payload: array<string, mixed>}
     */
    public function cancel(Invoice $invoice, string $reason): array;
}
