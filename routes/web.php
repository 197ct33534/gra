<?php

use App\Http\Controllers\BankWebhookController;
use App\Http\Controllers\OnboardingTourController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\PublicFeedbackController;
use App\Http\Controllers\PublicQuoteController;
use App\Http\Controllers\PublicStatusController;
use App\Http\Controllers\WarrantyPdfController;
use App\Http\Controllers\WorkOrderPdfController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/quote/{token}', [PublicQuoteController::class, 'show'])->name('public.quote.show');
Route::post('/quote/{token}/approve', [PublicQuoteController::class, 'approve'])
    ->middleware('throttle:20,1')
    ->name('public.quote.approve');
Route::post('/quote/{token}/reject', [PublicQuoteController::class, 'reject'])
    ->middleware('throttle:20,1')
    ->name('public.quote.reject');

Route::get('/status/{token}', [PublicStatusController::class, 'show'])->name('public.status.show');
Route::get('/feedback/{token}', [PublicFeedbackController::class, 'show'])->name('public.feedback.show');
Route::post('/feedback/{token}', [PublicFeedbackController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('public.feedback.store');

Route::get('/dat-lich', [PublicBookingController::class, 'create'])->name('public.booking.create');
Route::post('/dat-lich', [PublicBookingController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('public.booking.store');

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
    Route::post('/onboarding-tour/seen', [OnboardingTourController::class, 'seen'])->name('onboarding-tour.seen');
});
