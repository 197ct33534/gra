<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Support\PhoneTable;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ExpiringVehicleDocsWidget extends TableWidget
{
    protected static ?int $sort = 5;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        $days = max(1, (int) config('garage.vehicle_documents.warn_before_days', 30));
        $until = now()->addDays($days)->toDateString();

        return $table
            ->heading('Giấy tờ xe sắp/đã hết hạn ('.$days.' ngày)')
            ->query(
                Vehicle::query()
                    ->with('customer')
                    ->where(function (Builder $q) use ($until): void {
                        $q->whereNotNull('registration_expires_at')->whereDate('registration_expires_at', '<=', $until)
                            ->orWhere(fn (Builder $q2) => $q2->whereNotNull('insurance_expires_at')->whereDate('insurance_expires_at', '<=', $until))
                            ->orWhere(fn (Builder $q2) => $q2->whereNotNull('inspection_expires_at')->whereDate('inspection_expires_at', '<=', $until));
                    })
                    ->orderByRaw('coalesce(registration_expires_at, insurance_expires_at, inspection_expires_at)'),
            )
            ->columns([
                TextColumn::make('license_plate')->label('Biển số')->badge()
                    // Điện thoại: khách và các ngày hết hạn ghi gọn dưới biển số (CSS ẩn dòng này từ 768px).
                    ->description(fn (Vehicle $record): Htmlable => self::phoneSummary($record)),
                TextColumn::make('customer.name')->label('Khách')->visibleFrom('md'),
                TextColumn::make('registration_expires_at')->label('ĐKXD')->date('d/m/Y')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('insurance_expires_at')->label('BH')->date('d/m/Y')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('inspection_expires_at')->label('Đăng kiểm')->date('d/m/Y')->placeholder('—')->visibleFrom('md'),
            ])
            ->recordUrl(fn (Vehicle $record): string => VehicleResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('Không có giấy tờ sắp hết hạn');
    }

    /**
     * Dòng phụ dưới biển số, chỉ hiện trên điện thoại: tên khách · từng giấy tờ có ngày hết hạn (đỏ nếu đã hết hạn).
     */
    private static function phoneSummary(Vehicle $record): Htmlable
    {
        $doc = function (string $label, ?CarbonInterface $date): ?HtmlString {
            if ($date === null) {
                return null;
            }

            $text = e($label.' '.$date->format('d/m/Y'));

            $expired = $date->toDateString() < now((string) config('garage.timezone'))->toDateString();

            return new HtmlString($expired ? '<span style="color:#dc2626;font-weight:600">'.$text.'</span>' : $text);
        };

        return PhoneTable::summary([
            $record->customer?->name ?? '—',
            $doc('ĐKXD', $record->registration_expires_at),
            $doc('BH', $record->insurance_expires_at),
            $doc('Đăng kiểm', $record->inspection_expires_at),
        ]);
    }

    public static function canView(): bool
    {
        $user = User::current();

        return $user !== null && ($user->isAdmin() || $user->isServiceAdvisor());
    }
}
