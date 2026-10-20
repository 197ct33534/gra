<?php

namespace App\Filament\Resources\BankTransactions\Pages;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Services\BankReconciliationService;
use App\Support\VietnameseMoney;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBankTransactions extends ListRecords
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Chạy thử khi chưa nối SePay; ẩn trên môi trường production.
            Action::make('simulate')
                ->label('Giả lập chuyển khoản')
                ->icon(Heroicon::OutlinedBeaker)
                ->color('gray')
                ->visible(fn (): bool => ! app()->isProduction())
                ->schema([
                    TextInput::make('content')
                        ->label('Nội dung chuyển khoản')
                        ->placeholder('VD: TT RO-20261019-0001')
                        ->required(),
                    TextInput::make('amount')
                        ->label('Số tiền')
                        ->numeric()
                        ->minValue(1000)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $transaction = app(BankReconciliationService::class)->ingestSepay(
                        BankReconciliationService::fakeSepayPayload((float) $data['amount'], (string) $data['content']),
                    );
                    Notification::make()
                        ->title('Đã nhận '.VietnameseMoney::format($transaction->amount).': '.$transaction->status->getLabel())
                        ->body($transaction->note)
                        ->success()
                        ->send();
                }),
        ];
    }
}
