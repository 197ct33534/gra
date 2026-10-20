<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Services\WorkOrderPrintService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class WorkOrderPdfController extends Controller
{
    public function jobCard(WorkOrder $workOrder, WorkOrderPrintService $printer): Response
    {
        return Pdf::loadView('pdf.job_card', $printer->jobCardData($workOrder))
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->stream('phieu-giao-viec-'.$workOrder->code.'.pdf');
    }

    public function quotation(WorkOrder $workOrder, WorkOrderPrintService $printer): Response
    {
        return Pdf::loadView('pdf.quotation', $printer->quotationData($workOrder))
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->stream('bao-gia-'.$workOrder->code.'.pdf');
    }

    public function invoice(WorkOrder $workOrder, WorkOrderPrintService $printer): Response
    {
        return Pdf::loadView('pdf.invoice', $printer->invoiceData($workOrder))
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->stream('hoa-don-'.$workOrder->code.'.pdf');
    }

    public function invoiceDocument(Invoice $invoice, WorkOrderPrintService $printer): Response
    {
        $workOrder = $invoice->workOrder()->firstOrFail();

        return Pdf::loadView('pdf.invoice', $printer->invoiceData($workOrder, $invoice))
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->stream('hoa-don-'.$invoice->invoice_number.'.pdf');
    }
}
