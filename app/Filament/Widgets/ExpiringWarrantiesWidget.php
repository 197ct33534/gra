<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\WarrantyCard;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringWarrantiesWidget extends TableWidget
{
    protected static ?string $heading = 'Thẻ BH sắp hết hạn (30 ngày)';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = User::current();

        return $user !== null && ($user->isAdmin() || $user->isServiceAdvisor());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => WarrantyCard::query()->expiringSoon(30)->with(['vehicle', 'customer']))
            ->columns([
                TextColumn::make('code')->label('Mã'),
                TextColumn::make('vehicle.license_plate')->label('Biển số'),
                TextColumn::make('item_name')->label('Hạng mục')->limit(30),
                TextColumn::make('ends_at')->label('Hết hạn')->date('d/m/Y'),
                TextColumn::make('customer.name')->label('Khách'),
            ])
            ->paginated(false);
    }
}
