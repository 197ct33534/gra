<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SupplierBill;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingExportService
{
    public function monthlySalesCsv(string $month): StreamedResponse
    {
        ['start' => $start, 'end' => $end] = PayrollService::monthRange($month);

        $invoices = Invoice::query()
            ->with(['workOrder.vehicle.customer'])
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->get();

        $filename = 'so-ban-hang-'.$month.'.csv';

        return response()->streamDownload(function () use ($invoices): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Ngày', 'Số HĐ', 'RO', 'Khách/Payer', 'Loại payer', 'Trước VAT', 'VAT', 'Tổng', 'Đã thu', 'Loyalty', 'Còn nợ', 'TT',
            ]);

            foreach ($invoices as $invoice) {
                fputcsv($out, [
                    $invoice->created_at?->timezone((string) config('garage.timezone'))->format('d/m/Y H:i'),
                    $invoice->invoice_number,
                    $invoice->workOrder?->code,
                    $invoice->payer_name ?: ($invoice->workOrder?->vehicle?->customer?->name ?? ''),
                    $invoice->payer()?->getLabel() ?? '',
                    (int) round((float) $invoice->amount_before_vat),
                    (int) round((float) $invoice->vat_amount),
                    (int) round((float) $invoice->total_amount),
                    (int) round((float) $invoice->paid_amount),
                    (int) round((float) $invoice->loyalty_discount_amount),
                    (int) round($invoice->outstandingAmount()),
                    $invoice->payment_status?->getLabel() ?? '',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function monthlyPurchaseCsv(string $month): StreamedResponse
    {
        ['start' => $start, 'end' => $end] = PayrollService::monthRange($month);

        $bills = SupplierBill::query()
            ->with('supplier')
            ->whereBetween('bill_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('id')
            ->get();

        $filename = 'so-mua-hang-'.$month.'.csv';

        return response()->streamDownload(function () use ($bills): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Ngày HĐ', 'Số HĐ NCC', 'NCC', 'Trước VAT', 'VAT', 'Tổng', 'Đã trả', 'Còn nợ', 'TT',
            ]);

            foreach ($bills as $bill) {
                fputcsv($out, [
                    optional($bill->bill_date)->format('d/m/Y'),
                    $bill->bill_number,
                    $bill->supplier?->name,
                    (int) round((float) $bill->amount_before_vat),
                    (int) round((float) $bill->vat_amount),
                    (int) round((float) $bill->total_amount),
                    (int) round((float) $bill->paid_amount),
                    (int) round($bill->outstandingAmount()),
                    $bill->status?->getLabel() ?? (string) $bill->status,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
