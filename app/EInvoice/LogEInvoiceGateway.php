<?php

namespace App\EInvoice;

use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Driver giả lập: không gọi API thuế, sinh số HĐ giả để chạy thử STG/local.
 */
class LogEInvoiceGateway implements EInvoiceGateway
{
    public function issue(Invoice $invoice): array
    {
        $number = (string) random_int(1, 999999);
        $series = (string) config('garage.einvoice.series', 'C26T');
        $providerId = 'LOG-'.Str::upper(Str::random(10));

        $payload = [
            'driver' => 'log',
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total' => $invoice->total_amount,
            'buyer' => $invoice->payer_name,
        ];

        Log::info('einvoice.log.issue', $payload);

        return [
            'provider_id' => $providerId,
            'number' => $number,
            'series' => $series,
            'template' => (string) config('garage.einvoice.template', '1'),
            'tax_authority_code' => 'LOG-CQT-'.now()->format('Ymd'),
            'lookup_url' => url('/admin/invoices?einvoice='.$providerId),
            'payload' => $payload,
        ];
    }

    public function cancel(Invoice $invoice, string $reason): array
    {
        $payload = [
            'driver' => 'log',
            'invoice_id' => $invoice->id,
            'einvoice_number' => $invoice->einvoice_number,
            'reason' => $reason,
        ];

        Log::info('einvoice.log.cancel', $payload);

        return ['payload' => $payload];
    }
}
