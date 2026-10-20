<?php

use App\Http\Controllers\BankWebhookController;
use App\Http\Controllers\WarrantyPdfController;
use App\Http\Controllers\WorkOrderPdfController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// SePay gọi từ máy chủ của họ: không có phiên đăng nhập / CSRF, xác thực bằng khóa API trong header.
Route::post('/webhooks/sepay', [BankWebhookController::class, 'sepay'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('webhooks.sepay');

Route::middleware('auth')->group(function (): void {
    Route::get('/work-orders/{workOrder}/job-card', [WorkOrderPdfController::class, 'jobCard'])->name('work-orders.job-card');
    Route::get('/work-orders/{workOrder}/quotation', [WorkOrderPdfController::class, 'quotation'])->name('work-orders.quotation');
    Route::get('/work-orders/{workOrder}/invoice', [WorkOrderPdfController::class, 'invoice'])->name('work-orders.invoice');
    Route::get('/invoices/{invoice}/pdf', [WorkOrderPdfController::class, 'invoiceDocument'])->name('invoices.pdf');
    Route::get('/warranty-cards/{warrantyCard}/pdf', WarrantyPdfController::class)->name('warranty-cards.pdf');
});
