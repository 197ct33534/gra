<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('license_plate')->label('Biển số')->badge(),
                TextColumn::make('customer.name')->label('Khách'),
                TextColumn::make('registration_expires_at')->label('ĐKXD')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('insurance_expires_at')->label('BH')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('inspection_expires_at')->label('Đăng kiểm')->date('d/m/Y')->placeholder('—'),
            ])
            ->recordUrl(fn (Vehicle $record): string => VehicleResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('Không có giấy tờ sắp hết hạn');
    }

    public static function canView(): bool
    {
        $user = User::current();

        return $user !== null && ($user->isAdmin() || $user->isServiceAdvisor());
    }
}
