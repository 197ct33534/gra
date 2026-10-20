<?php

namespace App\Observers;

use App\Enums\ActivityEvent;
use App\Models\Invoice;
use App\Services\ActivityLogger;
use App\Support\VietnameseMoney;

class InvoiceObserver
{
    public function created(Invoice $invoice): void
    {
        ActivityLogger::log(
            ActivityEvent::InvoiceIssued,
            $invoice,
            "Lập hóa đơn {$invoice->invoice_number}: ".VietnameseMoney::format($invoice->total_amount).', đã thu '.VietnameseMoney::format($invoice->paid_amount),
            ['total' => (float) $invoice->total_amount, 'paid' => (float) $invoice->paid_amount],
        );
    }

    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('paid_amount')) {
            return;
        }

        $received = (float) $invoice->paid_amount - (float) $invoice->getOriginal('paid_amount');

        ActivityLogger::log(
            ActivityEvent::PaymentRecorded,
            $invoice,
            "Thu thêm {$invoice->invoice_number}: ".VietnameseMoney::format($received).' (đã thu '.VietnameseMoney::format($invoice->paid_amount).')',
            ['received' => $received, 'paid' => (float) $invoice->paid_amount],
        );
    }

    public function deleted(Invoice $invoice): void
    {
        ActivityLogger::log(
            ActivityEvent::InvoiceDeleted,
            $invoice,
            "Xóa hóa đơn {$invoice->invoice_number} (".VietnameseMoney::format($invoice->total_amount).')',
            ['total' => (float) $invoice->total_amount],
        );
    }
}
