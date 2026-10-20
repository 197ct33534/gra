<?php

namespace App\Filament\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRecommendation;
use App\Models\WorkOrder;
use App\Services\QuickReceptionService;
use App\Support\VietnameseMoney;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/**
 * Tiếp nhận xe trên điện thoại trong khoảng 90 giây: biển số, ODO, ảnh, ký nhận, gói dịch vụ.
 *
 * @property-read Schema $form
 */
class QuickReception extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = -1;

    protected static ?string $navigationLabel = 'Tiếp nhận nhanh';

    protected static ?string $title = 'Tiếp nhận nhanh';

    protected static ?string $slug = 'quick-reception';

    protected string $view = 'filament.pages.quick-reception';

    protected Width|string|null $maxContentWidth = Width::ThreeExtraLarge;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?int $lastOrderId = null;

    public static function canAccess(): bool
    {
        return WorkOrderResource::isOfficeUser() && (User::current()?->can('create', WorkOrder::class) ?? false);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(WorkOrder::class)
            ->statePath('data')
            ->components([
                Section::make('Xe & khách')
                    ->icon(Heroicon::OutlinedTruck)
                    ->schema([
                        TextInput::make('license_plate')
                            ->label('Biển số')
                            ->placeholder('VD: 51F12345')
                            ->helperText('Gõ liền, không cần dấu chấm hay gạch ngang.')
                            ->required()
                            ->maxLength(20)
                            ->autofocus()
                            ->extraInputAttributes(['style' => 'font-size: 22px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;', 'autocomplete' => 'off'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set): mixed => self::fillFromHistory($state, $set)),
                        Text::make(fn (Get $get): Htmlable => self::vehicleSummary($get('license_plate'))),
                        Grid::make(['default' => 1, 'sm' => 2])
                            ->visible(fn (Get $get): bool => filled($get('license_plate')) && ! QuickReceptionService::findVehicle($get('license_plate')) instanceof Vehicle)
                            ->schema([
                                TextInput::make('phone')
                                    ->label('SĐT khách')
                                    ->tel()
                                    ->required()
                                    ->minLength(9)
                                    ->maxLength(15),
                                TextInput::make('customer_name')
                                    ->label('Tên khách (bổ sung sau cũng được)')
                                    ->maxLength(255),
                                TextInput::make('brand')
                                    ->label('Hãng xe')
                                    ->placeholder('Toyota')
                                    ->maxLength(255),
                                TextInput::make('model')
                                    ->label('Dòng xe')
                                    ->placeholder('Vios')
                                    ->maxLength(255),
                            ]),
                        TextInput::make('reception_odo')
                            ->label('ODO hiện tại')
                            ->numeric()
                            ->inputMode('numeric')
                            ->required()
                            ->minValue(0)
                            ->suffix('km')
                            ->helperText(fn (Get $get): ?string => ($vehicle = QuickReceptionService::findVehicle($get('license_plate'))) instanceof Vehicle
                                ? 'Lần trước: '.number_format($vehicle->current_odo, 0, ',', '.').' km'
                                : null),
                    ]),
                Section::make('Tình trạng lúc nhận')
                    ->icon(Heroicon::OutlinedCamera)
                    ->schema([
                        ToggleButtons::make('fuel_level')
                            ->label('Nhiên liệu')
                            ->options(WorkOrderResource::FUEL_LEVELS)
                            ->inline()
                            ->default('1/2'),
                        CheckboxList::make('checkin_checklist')
                            ->label('Đồ trên xe (bỏ tick món thiếu)')
                            ->options(WorkOrderResource::CHECKIN_ITEMS)
                            ->default(array_keys(WorkOrderResource::CHECKIN_ITEMS))
                            ->columns(2),
                        SpatieMediaLibraryFileUpload::make('reception_photos')
                            ->label('Ảnh hiện trạng (chụp liên tục, không cần đặt tên)')
                            ->collection('reception_photos')
                            ->multiple()
                            ->image()
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('1600')
                            ->panelLayout('grid')
                            ->appendFiles(),
                        Textarea::make('customer_request')
                            ->label('Khách phản ánh (ghi nguyên văn)')
                            ->placeholder('VD: Kêu lụp cụp bánh trước phải khi ôm cua')
                            ->rows(2),
                    ]),
                Section::make('Công việc & hẹn giao')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->schema([
                        CheckboxList::make('package_ids')
                            ->label('Gói dịch vụ dựng sẵn (1 chạm ra đủ công + phụ tùng)')
                            ->options(fn (): array => ServicePackage::query()->active()->get()
                                ->mapWithKeys(fn (ServicePackage $package): array => [
                                    $package->id => $package->name.(User::currentCanSeeMoney() ? ' — '.VietnameseMoney::format($package->catalogTotal()) : ''),
                                ])
                                ->all())
                            ->descriptions(fn (): array => ServicePackage::query()->active()->pluck('vehicle_hint', 'id')->filter()->all()),
                        ToggleButtons::make('lift_bay')
                            ->label('Cầu nâng')
                            ->options(fn (): array => array_combine(WorkOrderResource::liftBays(), WorkOrderResource::liftBays()))
                            ->inline(),
                        ToggleButtons::make('promise_preset')
                            ->label('Hẹn giao xe')
                            ->options([
                                'plus1' => '+1 giờ',
                                'plus2' => '+2 giờ',
                                'today17' => '17:00 hôm nay',
                                'tomorrow9' => '9:00 sáng mai',
                            ])
                            ->inline()
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(fn (?string $state, Set $set): mixed => $set('promised_at', self::presetTime($state))),
                        DateTimePicker::make('promised_at')
                            ->label('Hoặc chọn giờ cụ thể')
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('H:i d/m/Y'),
                    ]),
                Section::make('Khách ký nhận xe')
                    ->icon(Heroicon::OutlinedPencil)
                    ->schema([
                        ViewField::make('signature')
                            ->hiddenLabel()
                            ->view('filament.forms.signature-pad'),
                    ]),
            ]);
    }

    public function receive(): void
    {
        $data = $this->form->getState();

        try {
            $order = app(QuickReceptionService::class)->receive($data, WorkOrderResource::actorId());
        } catch (DomainException $exception) {
            Notification::make()
                ->title('Chưa tiếp nhận được')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->form->model($order)->saveRelationships();
        $this->storeSignature($order, $data['signature'] ?? null);

        Notification::make()
            ->title("Đã tiếp nhận {$order->vehicle?->license_plate} — {$order->code}")
            ->body('Báo giá chi tiết làm sau khi thợ kiểm tra.')
            ->success()
            ->actions([
                Action::make('openOrder')
                    ->label('Mở lệnh')
                    ->url(WorkOrderResource::getUrl('edit', ['record' => $order])),
                Action::make('printJobCard')
                    ->label('In Phiếu Giao Việc')
                    ->url(route('work-orders.job-card', $order))
                    ->openUrlInNewTab(),
            ])
            ->persistent()
            ->send();

        $this->lastOrderId = $order->id;
        $this->form->fill();
    }

    public function getLastOrder(): ?WorkOrder
    {
        return $this->lastOrderId === null ? null : WorkOrder::query()->with('vehicle')->find($this->lastOrderId);
    }

    public static function presetTime(?string $preset): ?string
    {
        $now = Carbon::now((string) config('garage.timezone'));

        $time = match ($preset) {
            'plus1' => $now->copy()->addHour(),
            'plus2' => $now->copy()->addHours(2),
            'today17' => $now->copy()->setTime(17, 0),
            'tomorrow9' => $now->copy()->addDay()->setTime(9, 0),
            default => null,
        };

        // Trạng thái DateTimePicker lưu theo giờ ứng dụng (UTC); ô chọn tự hiển thị lại theo giờ Việt Nam.
        return $time?->seconds(0)->utc()->format('Y-m-d H:i:s');
    }

    private static function fillFromHistory(?string $plate, Set $set): void
    {
        $vehicle = QuickReceptionService::findVehicle($plate);

        if ($vehicle instanceof Vehicle) {
            $set('license_plate', $vehicle->license_plate);
        }
    }

    public static function vehicleSummary(?string $plate): Htmlable
    {
        if (blank($plate)) {
            return new HtmlString('<span style="opacity:.7">Gõ biển số để tra xe cũ. Xe mới chỉ cần thêm SĐT khách.</span>');
        }

        $vehicle = QuickReceptionService::findVehicle($plate);

        if (! $vehicle instanceof Vehicle) {
            return new HtmlString('<span style="color:#0284c7;font-weight:600">Xe mới — nhập SĐT khách, các thông tin khác bổ sung sau.</span>');
        }

        $timezone = (string) config('garage.timezone');
        $last = $vehicle->latestVisit();
        $lines = [
            '<div style="font-weight:700">'.e(trim($vehicle->brand.' '.$vehicle->model.' '.($vehicle->year ?? ''))).' · '.e($vehicle->customer?->name ?? '').' · '.e($vehicle->customer?->phone ?? '').'</div>',
        ];

        if ($last instanceof WorkOrder) {
            $lines[] = '<div>Lần trước: '.e($last->created_at?->timezone($timezone)->format('d/m/Y') ?? '').' ('.e($last->code).'), ODO '.number_format($vehicle->current_odo, 0, ',', '.').' km</div>';
        }

        if ($vehicle->isMaintenanceDue()) {
            $lines[] = '<div style="color:#dc2626;font-weight:600">⚠ Xe đến hạn bảo dưỡng — gợi ý gói bảo dưỡng.</div>';
        }

        $open = QuickReceptionService::openOrderFor($vehicle);

        if ($open instanceof WorkOrder) {
            $lines[] = '<div style="color:#dc2626;font-weight:700">Xe đang có lệnh '.e($open->code).' ('.e($open->status->getLabel()).') chưa bàn giao.</div>';
        }

        try {
            $declined = $vehicle->recommendations()->open()->latest('declined_at')->limit(5)->get();
        } catch (Throwable) {
            $declined = collect();
        }

        if ($declined->isNotEmpty()) {
            $items = $declined->map(fn (VehicleRecommendation $item): string => '<li>'.e($item->item_name).' <span style="opacity:.7">('.e($item->declined_at->timezone($timezone)->format('d/m/Y')).(filled($item->decline_reason) ? ', '.e($item->decline_reason) : '').')</span></li>')->implode('');
            $lines[] = '<div style="color:#7c3aed;font-weight:600;margin-top:4px">Lần trước khách chưa làm — tư vấn lại:</div><ul style="margin:0 0 0 18px;list-style:disc">'.$items.'</ul>';
        }

        return new HtmlString('<div style="display:grid;gap:2px;font-size:14px">'.implode('', $lines).'</div>');
    }

    private function storeSignature(WorkOrder $order, mixed $signature): void
    {
        if (! is_string($signature) || ! str_starts_with($signature, 'data:image/png;base64,')) {
            return;
        }

        $order->addMediaFromBase64($signature, 'image/png')
            ->usingFileName('chu-ky-nhan-xe-'.$order->code.'.png')
            ->toMediaCollection('reception_signature');
    }
}
