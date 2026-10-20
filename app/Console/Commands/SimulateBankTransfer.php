<?php

namespace App\Console\Commands;

use App\Services\BankReconciliationService;
use App\Support\VietnameseMoney;
use Illuminate\Console\Command;

class SimulateBankTransfer extends Command
{
    protected $signature = 'garage:simulate-transfer {amount : Số tiền} {content : Nội dung chuyển khoản, VD "TT RO-20261019-0001"}';

    protected $description = 'Giả lập một khoản chuyển khoản SePay để chạy thử đối soát tự động';

    public function handle(BankReconciliationService $reconciliation): int
    {
        if (app()->isProduction()) {
            $this->error('Không chạy giả lập trên production.');

            return self::FAILURE;
        }

        $transaction = $reconciliation->ingestSepay(
            BankReconciliationService::fakeSepayPayload((float) $this->argument('amount'), (string) $this->argument('content')),
        );

        $this->info('Đã nhận '.VietnameseMoney::format($transaction->amount).' → '.$transaction->status->getLabel()
            .($transaction->invoice_id ? ' (hóa đơn '.$transaction->invoice?->invoice_number.')' : '')
            .($transaction->note ? '. '.$transaction->note : ''));

        return self::SUCCESS;
    }
}
