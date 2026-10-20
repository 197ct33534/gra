<?php

namespace App\Http\Controllers;

use App\Models\WarrantyCard;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class WarrantyPdfController extends Controller
{
    public function __invoke(WarrantyCard $warrantyCard): Response
    {
        $warrantyCard->load(['vehicle.customer', 'workOrder', 'customer']);

        return Pdf::loadView('pdf.warranty_card', [
            'card' => $warrantyCard,
            'garage' => config('garage'),
        ])
            ->setPaper('a5')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->stream('bao-hanh-'.$warrantyCard->code.'.pdf');
    }
}
