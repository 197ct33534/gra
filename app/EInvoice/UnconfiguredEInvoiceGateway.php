<?php

namespace App\EInvoice;

use App\Models\Invoice;

class UnconfiguredEInvoiceGateway implements EInvoiceGateway
{
    public function __construct(private readonly string $driver) {}

    public function issue(Invoice $invoice): array
    {
        throw new EInvoiceException(
            "Chưa tích hợp nhà cung cấp HĐĐT \"{$this->driver}\". Đặt GARAGE_EINVOICE_DRIVER=log để chạy thử."
        );
    }

    public function cancel(Invoice $invoice, string $reason): array
    {
        throw new EInvoiceException(
            "Chưa tích hợp nhà cung cấp HĐĐT \"{$this->driver}\". Đặt GARAGE_EINVOICE_DRIVER=log để chạy thử."
        );
    }
}
