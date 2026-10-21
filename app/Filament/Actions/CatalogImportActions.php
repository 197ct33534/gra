<?php

namespace App\Filament\Actions;

use App\Services\CatalogImportService;
use App\Support\CsvSpreadsheet;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CatalogImportActions
{
    public static function downloadCustomerTemplate(): Action
    {
        return Action::make('downloadCustomerTemplate')
            ->label('Mẫu CSV khách')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (): StreamedResponse {
                $svc = app(CatalogImportService::class);

                return CsvSpreadsheet::streamDownload(
                    'mau-khach-hang.csv',
                    $svc->customerTemplateHeaders(),
                    [[
                        'Nguyễn Văn A',
                        '0912345678',
                        'a@example.com',
                        'Q7, TP.HCM',
                        'individual',
                        '',
                        '',
                        '0',
                    ]],
                );
            });
    }

    public static function importCustomers(): Action
    {
        return Action::make('importCustomers')
            ->label('Import khách (CSV)')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->modalHeading('Import khách hàng từ CSV/Excel')
            ->modalDescription('Xuất Excel → Lưu dưới dạng CSV UTF-8. Khớp theo số điện thoại (tạo mới hoặc cập nhật).')
            ->form([
                FileUpload::make('file')
                    ->label('File CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'text/comma-separated-values'])
                    ->required()
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private'),
            ])
            ->action(function (array $data): void {
                $path = self::resolveUploadedPath($data['file'] ?? null);
                $result = app(CatalogImportService::class)->importCustomers($path);
                self::notifyResult('Khách hàng', $result);
            });
    }

    public static function downloadPartTemplate(): Action
    {
        return Action::make('downloadPartTemplate')
            ->label('Mẫu CSV phụ tùng')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (): StreamedResponse {
                $svc = app(CatalogImportService::class);

                return CsvSpreadsheet::streamDownload(
                    'mau-phu-tung.csv',
                    $svc->partTemplateHeaders(),
                    [[
                        'OIL-5W30-DEMO',
                        'Dầu nhớt demo 5W-30',
                        'Dầu nhớt & Phụ gia',
                        'lít',
                        '180000',
                        '250000',
                        '10',
                        '5',
                        '',
                        'A1',
                        '6',
                        '10000',
                    ]],
                );
            });
    }

    public static function importParts(): Action
    {
        return Action::make('importParts')
            ->label('Import phụ tùng (CSV)')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->modalHeading('Import phụ tùng từ CSV/Excel')
            ->modalDescription('Khớp theo SKU. Cập nhật không đè tồn kho hiện có; tồn chỉ áp dụng khi tạo mã mới.')
            ->form([
                FileUpload::make('file')
                    ->label('File CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'text/comma-separated-values'])
                    ->required()
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private'),
            ])
            ->action(function (array $data): void {
                $path = self::resolveUploadedPath($data['file'] ?? null);
                $result = app(CatalogImportService::class)->importParts($path);
                self::notifyResult('Phụ tùng', $result);
            });
    }

    /**
     * @param  array{created: int, updated: int, skipped: int, errors: list<string>}  $result
     */
    private static function notifyResult(string $label, array $result): void
    {
        $body = "Tạo {$result['created']}, cập nhật {$result['updated']}, bỏ qua {$result['skipped']}.";
        if ($result['errors'] !== []) {
            $body .= ' '.implode(' ', array_slice($result['errors'], 0, 5));
            if (count($result['errors']) > 5) {
                $body .= ' …';
            }
        }

        $notification = Notification::make()
            ->title('Import '.$label)
            ->body($body);

        if ($result['skipped'] > 0 && $result['created'] + $result['updated'] === 0) {
            $notification->danger()->send();
        } elseif ($result['skipped'] > 0) {
            $notification->warning()->send();
        } else {
            $notification->success()->send();
        }
    }

    private static function resolveUploadedPath(mixed $file): string
    {
        if ($file instanceof TemporaryUploadedFile) {
            return $file->getRealPath() ?: $file->getPathname();
        }

        if (is_array($file)) {
            $file = reset($file);
        }

        if (is_string($file) && $file !== '') {
            $absolute = Storage::disk('local')->path($file);
            if (is_file($absolute)) {
                return $absolute;
            }

            if (is_file($file)) {
                return $file;
            }
        }

        throw new \DomainException('Không tìm thấy file đã tải lên.');
    }
}
