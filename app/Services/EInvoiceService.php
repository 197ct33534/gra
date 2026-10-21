<?php

namespace App\Services;

use App\EInvoice\EInvoiceException;
use App\EInvoice\EInvoiceGateway;
use App\Enums\ActivityEvent;
use App\Enums\CustomerMessageStatus;
use App\Enums\CustomerMessageType;
use App\Enums\EInvoiceStatus;
use App\Enums\InvoicePayer;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Invoice;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

class EInvoiceService
{
    public function __construct(private readonly EInvoiceGateway $gateway) {}

    public function issue(Invoice $invoice, bool $adminOverride = false): Invoice
    {
        return DB::transaction(function () use ($invoice, $adminOverride): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with('workOrder.vehicle.customer')->firstOrFail();

            if ($locked->einvoice_status === EInvoiceStatus::Issued) {
                throw new DomainException('Hóa đơn đã xuất HĐĐT số '.$locked->einvoice_number.'.');
            }

            $requirePaid = (bool) config('garage.einvoice.require_paid', true);

            if ($requirePaid && $locked->payment_status !== PaymentStatus::Paid && ! $adminOverride) {
                throw new DomainException('Chỉ xuất HĐĐT khi hóa đơn đã thanh toán đủ (hoặc admin override).');
            }

            try {
                $result = $this->gateway->issue($locked);
            } catch (EInvoiceException $e) {
                $locked->einvoice_status = EInvoiceStatus::Failed;
                $locked->einvoice_error = $e->getMessage();
                $locked->save();
                throw new DomainException($e->getMessage(), 0, $e);
            }

            $locked->einvoice_status = EInvoiceStatus::Issued;
            $locked->einvoice_number = $result['number'];
            $locked->einvoice_series = $result['series'];
            $locked->einvoice_template = $result['template'] ?? null;
            $locked->einvoice_provider = (string) config('garage.einvoice.driver', 'log');
            $locked->einvoice_provider_id = $result['provider_id'];
            $locked->einvoice_tax_authority_code = $result['tax_authority_code'] ?? null;
            $locked->einvoice_lookup_url = $result['lookup_url'] ?? null;
            $locked->einvoice_issued_at = now();
            $locked->einvoice_canceled_at = null;
            $locked->einvoice_error = null;
            $locked->einvoice_payload = $result['payload'] ?? null;
            $locked->save();

            ActivityLogger::log(
                ActivityEvent::EInvoiceIssued,
                $locked,
                "Xuất HĐĐT {$locked->einvoice_series}{$locked->einvoice_number} cho {$locked->invoice_number}",
                ['provider_id' => $locked->einvoice_provider_id],
            );

            return $locked;
        });
    }

    public function cancel(Invoice $invoice, string $reason, int $userId): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $userId): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->einvoice_status !== EInvoiceStatus::Issued) {
                throw new DomainException('Chỉ hủy được HĐĐT ở trạng thái Đã xuất.');
            }

            if (! filled($reason)) {
                throw new DomainException('Cần nhập lý do hủy HĐĐT.');
            }

            try {
                $result = $this->gateway->cancel($locked, $reason);
            } catch (EInvoiceException $e) {
                throw new DomainException($e->getMessage(), 0, $e);
            }

            $locked->einvoice_status = EInvoiceStatus::Canceled;
            $locked->einvoice_canceled_at = now();
            $locked->einvoice_error = null;
            $locked->einvoice_payload = array_merge(
                is_array($locked->einvoice_payload) ? $locked->einvoice_payload : [],
                ['cancel' => $result['payload'] ?? [], 'reason' => $reason, 'by' => $userId],
            );
            $locked->save();

            ActivityLogger::log(
                ActivityEvent::EInvoiceCanceled,
                $locked,
                "Hủy HĐĐT {$locked->einvoice_series}{$locked->einvoice_number}: {$reason}",
                ['reason' => $reason],
            );

            return $locked;
        });
    }

    public function queueLookupLink(Invoice $invoice): ?CustomerMessage
    {
        $invoice->loadMissing('workOrder.vehicle.customer');

        if ($invoice->einvoice_status !== EInvoiceStatus::Issued || blank($invoice->einvoice_lookup_url)) {
            return null;
        }

        if ($invoice->payer_type === InvoicePayer::Insurance) {
            return null;
        }

        $customer = $invoice->workOrder?->vehicle?->customer;
        $phone = MessagingService::normalizePhone($customer?->phone);

        if (! $customer instanceof Customer || $phone === null) {
            return null;
        }

        $body = config('garage.name').' đã xuất HĐĐT '
            .$invoice->einvoice_series.$invoice->einvoice_number
            .' ('.VietnameseMoney::format($invoice->total_amount).'). '
            .'Tra cứu: '.$invoice->einvoice_lookup_url;

        return CustomerMessage::query()->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $invoice->workOrder?->vehicle_id,
            'invoice_id' => $invoice->id,
            'type' => CustomerMessageType::EInvoiceLink,
            'channel' => (string) config('garage.messaging.channel', 'zalo'),
            'phone' => $phone,
            'body' => $body,
            'status' => CustomerMessageStatus::Pending,
            'scheduled_at' => now(),
        ]);
    }
}
