<?php

namespace App\Filament\Resources\WorkOrders;

use App\Enums\InsuranceClaimStatus;
use App\Enums\InvoicePayer;
use App\Enums\PaymentMethod;
use App\Enums\WorkOrderItemSource;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Helpers\LicensePlateHelper;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\CustomerPortalService;
use App\Services\QuoteApprovalService;
use App\Services\WorkOrderService;
use App\Support\VietnameseMoney;
use BackedEnum;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Lệnh sửa chữa';

    protected static ?string $modelLabel = 'lệnh sửa chữa';

    protected static ?string $pluralModelLabel = 'lệnh sửa chữa';

    protected static ?string $recordTitleAttribute = 'code';

    /**
     * @var array<string, string>
     */
    public const FUEL_LEVELS = [
        'E' => 'E',
        '1/4' => '1/4',
        '1/2' => '1/2',
        '3/4' => '3/4',
        'F' => 'F',
    ];

    /**
     * @var array<string, string>
     */
    public const CHECKIN_ITEMS = [
        'warranty_book' => 'Sổ bảo hành',
        'tools' => 'Đồ nghề theo xe',
        'spare_tire' => 'Lốp dự phòng',
        'valuables' => 'Đồ cá nhân giá trị',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Lệnh sửa chữa')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Thông tin tiếp nhận')
                            ->icon(Heroicon::OutlinedClipboardDocumentList)
                            ->schema(self::receptionSchema()),
                        Tab::make('Hiện trạng & Giám định xe')
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->schema(self::inspectionSchema()),
                        Tab::make('Báo giá & Hạng mục sửa chữa')
                            ->icon(Heroicon::OutlinedWrenchScrewdriver)
                            ->schema(self::quoteSchema()),
                    ]),
            ]);
    }

    /**
     * @return list<string>
     */
    public static function liftBays(): array
    {
        return array_values(config('garage.lift_bays', []));
    }

    public static function isInsurance(mixed $orderType): bool
    {
        $value = $orderType instanceof WorkOrderType ? $orderType->value : $orderType;

        return $value === WorkOrderType::Insurance->value;
    }

    /**
     * @return array<int, Component>
     */
    public static function receptionSchema(): array
    {
        return [
            TextInput::make('code')
                ->label('Mã lệnh')
                ->default(fn (): string => app(WorkOrderService::class)->nextCode())
                ->disabled()
                ->dehydrated()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(32),
            Select::make('branch_id')
                ->label('Chi nhánh')
                ->relationship('branch', 'name')
                ->default(fn (): ?int => \App\Models\WorkOrder::defaultBranchIdForCurrentUser())
                ->searchable()
                ->preload()
                ->native(false)
                ->visible(fn (): bool => User::current()?->isAdmin() ?? false),
            Select::make('vehicle_id')
                ->label('Xe')
                ->relationship(
                    name: 'vehicle',
                    titleAttribute: 'license_plate',
                    modifyQueryUsing: fn (Builder $query): Builder => $query->with('customer'),
                )
                ->getOptionLabelFromRecordUsing(fn (Vehicle $record): string => self::vehicleLabel($record))
                ->getSearchResultsUsing(function (string $search): array {
                    return LicensePlateHelper::applyVehicleSearch(Vehicle::query()->with('customer'), $search)
                        ->orderBy('license_plate')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => self::vehicleLabel($vehicle)])
                        ->all();
                })
                ->searchable()
                ->preload()
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if (blank($state)) {
                        return;
                    }

                    $vehicle = Vehicle::query()->find($state);

                    if ($vehicle instanceof Vehicle) {
                        $set('reception_odo', $vehicle->current_odo);
                    }
                }),
            Select::make('service_advisor_id')
                ->label('Cố vấn dịch vụ')
                ->relationship('serviceAdvisor', 'name')
                ->default(fn (): int|string|null => auth()->id())
                ->searchable()
                ->preload()
                ->required()
                ->native(false),
            TextInput::make('reception_odo')
                ->label('ODO tiếp nhận')
                ->numeric()
                ->required()
                ->minValue(0)
                ->suffix('km')
                ->rule(function (Get $get): Closure {
                    return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $vehicle = Vehicle::query()->find($get('vehicle_id'));

                        if (! $vehicle instanceof Vehicle) {
                            return;
                        }

                        if ((int) $value < $vehicle->current_odo) {
                            $fail("ODO tiếp nhận phải lớn hơn hoặc bằng ODO hiện tại của xe ({$vehicle->current_odo} km).");
                        }
                    };
                }),
            Select::make('fuel_level')
                ->label('Mức nhiên liệu')
                ->options(self::FUEL_LEVELS)
                ->native(false),
            DateTimePicker::make('promised_at')
                ->label('Giờ hẹn giao xe')
                ->seconds(false)
                ->displayFormat('H:i d/m/Y')
                ->native(false),
            Select::make('lift_bay')
                ->label('Cầu nâng')
                ->options(fn (): array => array_combine(self::liftBays(), self::liftBays()))
                ->native(false),
            Select::make('order_type')
                ->label('Loại lệnh')
                ->options(WorkOrderType::class)
                ->default(WorkOrderType::Standard)
                ->required()
                ->native(false)
                ->live(),
            TextInput::make('insurance_company')
                ->label('Công ty bảo hiểm')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => self::isInsurance($get('order_type'))),
            MoneyInput::make('insurance_deductible')
                ->label('Khấu trừ khách tự trả')
                ->default(0)
                ->visible(fn (Get $get): bool => self::isInsurance($get('order_type')) && User::currentCanSeeMoney()),
            TextInput::make('insurance_claim_no')
                ->label('Số hồ sơ BH')
                ->maxLength(80)
                ->visible(fn (Get $get): bool => self::isInsurance($get('order_type'))),
            TextInput::make('insurance_adjuster')
                ->label('Giám định viên')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => self::isInsurance($get('order_type'))),
            DatePicker::make('insurance_expected_pay_at')
                ->label('Dự kiến BH chi')
                ->native(false)
                ->visible(fn (Get $get): bool => self::isInsurance($get('order_type'))),
            Select::make('insurance_claim_status')
                ->label('Trạng thái hồ sơ BH')
                ->options(InsuranceClaimStatus::class)
                ->native(false)
                ->visible(fn (Get $get): bool => self::isInsurance($get('order_type'))),
            Textarea::make('customer_request')
                ->label('Yêu cầu khách hàng')
                ->rows(3)
                ->columnSpanFull(),
            Textarea::make('technical_diagnosis')
                ->label('Chẩn đoán kỹ thuật')
                ->rows(3)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function inspectionSchema(): array
    {
        return [
            SpatieMediaLibraryFileUpload::make('reception_photos')
                ->label('Ảnh hiện trạng xe')
                ->collection('reception_photos')
                ->multiple()
                ->image()
                ->imagePreviewHeight('160')
                ->panelLayout('grid')
                ->reorderable()
                ->appendFiles()
                ->columnSpanFull(),
            Repeater::make('body_inspection')
                ->label('Kiểm tra thân vỏ / vùng hư hại')
                ->helperText('Ghi nhận xước, móp, nứt… trước khi sửa — khách và gara đối chiếu.')
                ->schema([
                    Select::make('zone')
                        ->label('Vùng xe')
                        ->options(\App\Support\BodyInspection::zones())
                        ->required()
                        ->native(false)
                        ->searchable(),
                    Select::make('condition')
                        ->label('Tình trạng')
                        ->options(\App\Support\BodyInspection::conditions())
                        ->required()
                        ->native(false)
                        ->default('scratch'),
                    TextInput::make('note')
                        ->label('Ghi chú')
                        ->maxLength(255)
                        ->placeholder('VD: xước dài ~10cm gần đèn'),
                ])
                ->default([])
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => filled($state['zone'] ?? null)
                    ? \App\Support\BodyInspection::zoneLabel((string) $state['zone'])
                        .' — '.\App\Support\BodyInspection::conditionLabel((string) ($state['condition'] ?? 'other'))
                    : null)
                ->addActionLabel('Thêm vùng hư hại')
                ->columnSpanFull(),
            SpatieMediaLibraryFileUpload::make('body_inspection_photos')
                ->label('Ảnh thân vỏ theo vùng')
                ->collection('body_inspection_photos')
                ->multiple()
                ->image()
                ->imagePreviewHeight('140')
                ->panelLayout('grid')
                ->appendFiles()
                ->columnSpanFull(),
            SpatieMediaLibraryFileUpload::make('insurance_docs')
                ->label('Chứng từ bảo hiểm (ảnh/PDF)')
                ->collection('insurance_docs')
                ->multiple()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->openable()
                ->downloadable()
                ->columnSpanFull(),
            CheckboxList::make('checkin_checklist')
                ->label('Đồ trên xe khi tiếp nhận')
                ->options(self::CHECKIN_ITEMS)
                ->columns(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function quoteSchema(): array
    {
        return [
            Repeater::make('items')
                ->label('Hạng mục')
                ->relationship('workOrderItems')
                ->schema([
                    Hidden::make('revision_no')
                        ->default(1),
                    Grid::make(2)
                        ->columnSpanFull()
                        ->disabled(fn (?WorkOrderItem $record): bool => self::isLockedLine($record))
                        ->schema([
                            Select::make('item_type')
                                ->label('Loại hạng mục')
                                ->options([
                                    WorkOrderItemType::Service->value => 'Dịch vụ',
                                    WorkOrderItemType::Part->value => 'Phụ tùng',
                                ])
                                ->required()
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('item_id', null);
                                    $set('unit_price', null);
                                    $set('cost_price', 0);
                                    $set('subtotal', 0);
                                }),
                            Select::make('item_source')
                                ->label('Nguồn hàng / công')
                                ->options(WorkOrderItemSource::class)
                                ->default(WorkOrderItemSource::Stock)
                                ->required()
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                    if (self::sourceValue($state) === WorkOrderItemSource::CustomerSupplied->value) {
                                        $set('unit_price', 0);
                                        $set('cost_price', 0);
                                        $set('discount_amount', 0);
                                        $set('warranty_policy', WorkOrderItemSource::CUSTOMER_SUPPLIED_WARRANTY);
                                    }

                                    self::syncLineSubtotal($get, $set);
                                }),
                            Select::make('item_id')
                                ->label('Hạng mục')
                                ->options(fn (Get $get): array => self::itemOptions($get('item_type')))
                                ->searchable()
                                ->required()
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                    $price = self::catalogPrice($get('item_type'), $state);
                                    $set('unit_price', $price);
                                    self::syncLineSubtotal($get, $set, $price);
                                }),
                            TextInput::make('quantity')
                                ->label('Số lượng')
                                ->numeric()
                                ->required()
                                ->default(1)
                                ->minValue(0.01)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::syncLineSubtotal($get, $set)),
                            MoneyInput::make('unit_price')
                                ->label('Đơn giá bán')
                                ->required()
                                ->visible(fn (): bool => User::currentCanSeeMoney())
                                ->rule(fn (Get $get): Closure => self::advisorPriceRule($get))
                                ->disabled(fn (Get $get): bool => self::isCustomerSupplied($get('item_source')))
                                ->dehydrated()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::syncLineSubtotal($get, $set)),
                            MoneyInput::make('discount_amount')
                                ->label('Giảm giá dòng')
                                ->default(0)
                                ->visible(fn (): bool => User::currentCanSeeMoney())
                                ->rule(fn (Get $get): Closure => self::advisorLineDiscountRule($get))
                                ->disabled(fn (Get $get): bool => self::isCustomerSupplied($get('item_source')))
                                ->dehydrated()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::syncLineSubtotal($get, $set)),
                            MoneyInput::make('subtotal')
                                ->label('Thành tiền')
                                ->visible(fn (): bool => User::currentCanSeeMoney())
                                ->helperText(fn (Get $get): ?string => self::isDeclined($get('is_approved')) ? 'Khách từ chối: không tính tiền.' : null)
                                ->disabled()
                                ->dehydrated()
                                ->default(0),
                            MoneyInput::make('cost_price')
                                ->label('Giá vốn / chi phí trả ngoài')
                                ->default(0)
                                ->helperText('Để 0 với hàng kho: lấy giá vốn trong kho khi duyệt.')
                                ->required(fn (Get $get): bool => in_array(self::sourceValue($get('item_source')), [
                                    WorkOrderItemSource::DirectPurchase->value,
                                    WorkOrderItemSource::Subcontract->value,
                                ], true))
                                ->hidden(fn (Get $get): bool => self::isCustomerSupplied($get('item_source'))
                                    || ! User::currentCanSeeMoney()
                                    || (! User::currentCanSeeCosts() && self::sourceValue($get('item_source')) === WorkOrderItemSource::Stock->value)),
                            Select::make('supplier_id')
                                ->label('Nhà cung cấp')
                                ->relationship(
                                    'supplier',
                                    'name',
                                    fn (\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder => $query->active()->orderBy('name'),
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->displayLabel())
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->visible(fn (Get $get): bool => in_array(self::sourceValue($get('item_source')), [
                                    WorkOrderItemSource::DirectPurchase->value,
                                    WorkOrderItemSource::Subcontract->value,
                                ], true)),
                            TextInput::make('supplier_info')
                                ->label(fn (Get $get): string => self::sourceValue($get('item_source')) === WorkOrderItemSource::Subcontract->value
                                    ? 'Ghi chú xưởng gia công'
                                    : 'Ghi chú nơi mua')
                                ->placeholder('VD: anh Hùng giao tận nơi, CK trước')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => in_array(self::sourceValue($get('item_source')), [
                                    WorkOrderItemSource::DirectPurchase->value,
                                    WorkOrderItemSource::Subcontract->value,
                                ], true)),
                            TextInput::make('warranty_policy')
                                ->label('Chính sách bảo hành')
                                ->maxLength(255),
                            Select::make('technician_id')
                                ->label('Thợ phụ trách')
                                ->options(fn (): array => User::query()->technicians()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->native(false),
                            TextInput::make('actual_minutes')
                                ->label('Giờ thực tế (phút)')
                                ->numeric()
                                ->minValue(0)
                                ->helperText('Tự cộng từ chấm công, hoặc nhập tay.'),
                            Textarea::make('finding_note')
                                ->label('Hiện tượng thợ phát hiện khi tháo')
                                ->placeholder('VD: Tháo bánh thấy càng A dưới trái nứt, cao su bát bèo rách')
                                ->rows(2)
                                ->columnSpanFull()
                                ->visible(fn (Get $get): bool => (int) $get('revision_no') > 1),
                            Toggle::make('is_approved')
                                ->label('Khách đồng ý làm')
                                ->helperText('Tắt khi khách từ chối: dòng không tính tiền, không giữ kho, không in phiếu thợ và được lưu làm khuyến cáo cho lần sau.')
                                ->default(true)
                                ->inline(false)
                                ->live()
                                ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::syncLineSubtotal($get, $set)),
                            TextInput::make('decline_reason')
                                ->label('Lý do khách từ chối')
                                ->placeholder('VD: Để lần sau, khách tự mua đồ')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => self::isDeclined($get('is_approved'))),
                            Toggle::make('is_outside_insurance')
                                ->label('Ngoài phạm vi bảo hiểm (khách tự trả)')
                                ->helperText('Giám định không duyệt hoặc khách làm thêm: tiền hạng mục này tính vào phần khách trả.')
                                ->default(false)
                                ->inline(false)
                                ->visible(fn (Get $get): bool => self::isInsurance($get('../../order_type'))),
                        ]),
                ])
                ->itemLabel(fn (string $key, Repeater $component): Htmlable => self::itemLabel((array) ($component->getRawState()[$key] ?? [])))
                ->collapsible()
                ->columnSpanFull()
                ->addActionLabel('Thêm hạng mục')
                ->reorderable(false)
                ->addable(fn (?WorkOrder $record): bool => ! self::isCommitted($record))
                ->deleteAction(fn (Action $action): Action => $action->visible(
                    fn (array $arguments, Repeater $component): bool => ! self::isLockedLine($component->getChildSchema($arguments['item'])->getRecord()),
                )),
            Actions::make([
                Action::make('addAddendumItem')
                    ->label('Thêm hạng mục phát sinh')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('purple')
                    ->visible(fn (?WorkOrder $record): bool => self::isCommitted($record) && $record->status !== WorkOrderStatus::Delivered)
                    ->action(function (Get $get, Set $set, WorkOrder $record): void {
                        $items = $get('items') ?? [];
                        $items[(string) Str::uuid()] = self::blankLine($record->nextAddendumRevision());
                        $set('items', $items);
                    }),
            ])->columnSpanFull(),
            MoneyInput::make('discount_amount')
                ->label('Giảm giá toàn đơn')
                ->default(0)
                ->visible(fn (): bool => User::currentCanSeeMoney())
                ->rule(fn (Get $get): Closure => self::advisorOrderDiscountRule($get))
                ->live(onBlur: true)
                ->disabled(fn (?WorkOrder $record): bool => $record instanceof WorkOrder && $record->status->stockIsCommitted()),
            TextInput::make('vat_rate')
                ->label('VAT')
                ->numeric()
                ->default(8)
                ->required()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->live(onBlur: true)
                ->disabled(fn (?WorkOrder $record): bool => $record instanceof WorkOrder && $record->status->stockIsCommitted()),
            TextInput::make('preview_net')
                ->label('Tổng tạm tính')
                ->visible(fn (): bool => User::currentCanSeeMoney())
                ->disabled()
                ->dehydrated(false)
                ->suffix('đ')
                ->formatStateUsing(function (?WorkOrder $record, Get $get): string {
                    if ($record instanceof WorkOrder) {
                        return self::formatMoney((float) $record->total_parts_amount + (float) $record->total_labor_amount - (float) $record->discount_amount);
                    }

                    return self::formatMoney(self::summarizeQuote($get)['net']);
                }),
            TextInput::make('preview_final')
                ->label('Tổng thanh toán')
                ->visible(fn (): bool => User::currentCanSeeMoney())
                ->disabled()
                ->dehydrated(false)
                ->suffix('đ')
                ->formatStateUsing(function (?WorkOrder $record, Get $get): string {
                    if ($record instanceof WorkOrder) {
                        return self::formatMoney((float) $record->final_amount);
                    }

                    return self::formatMoney(self::summarizeQuote($get)['final']);
                }),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã lệnh')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('vehicle.license_plate')
                    ->label('Biển số')
                    ->badge()
                    ->color('primary')
                    ->weight(FontWeight::Bold)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'vehicle',
                        fn (Builder $query): Builder => LicensePlateHelper::applyVehicleSearch($query, $search),
                    )),
                TextColumn::make('vehicle.customer.name')
                    ->label('Khách hàng')
                    ->searchable(),
                TextColumn::make('reception_odo')
                    ->label('ODO tiếp nhận')
                    ->numeric(decimalPlaces: 0, locale: 'vi')
                    ->suffix(' km')
                    ->sortable(),
                TextColumn::make('lift_bay')
                    ->label('Cầu nâng')
                    ->placeholder('—'),
                TextColumn::make('promised_at')
                    ->label('Hẹn giao')
                    ->dateTime('H:i d/m')
                    ->placeholder('—')
                    ->color(fn (WorkOrder $record): string => $record->promised_at?->isPast() && $record->status !== WorkOrderStatus::Delivered ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('order_type')
                    ->label('Loại lệnh')
                    ->badge()
                    ->formatStateUsing(fn (WorkOrderType $state): string => $state->getLabel())
                    ->color(fn (WorkOrderType $state): string => $state === WorkOrderType::Insurance ? 'info' : 'gray')
                    ->description(fn (WorkOrder $record): ?string => $record->isInsuranceClaim() ? $record->insurance_company : null)
                    ->toggleable(),
                TextColumn::make('final_amount')
                    ->label('Thanh toán')
                    ->visible(fn (): bool => User::currentCanSeeMoney())
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (WorkOrderStatus $state): string => $state->getLabel())
                    ->color(fn (WorkOrderStatus $state): string => $state->getColor()),
                IconColumn::make('needs_restock')
                    ->label('Thiếu hàng')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedExclamationTriangle)
                    ->trueColor('danger')
                    ->falseIcon(null)
                    ->tooltip(fn (WorkOrder $record): ?string => $record->needs_restock ? 'Đã duyệt khi kho thiếu hàng — cần nhập thêm' : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(WorkOrderStatus::class),
                SelectFilter::make('order_type')
                    ->label('Loại lệnh')
                    ->options(WorkOrderType::class),
                Filter::make('insurance_receivable')
                    ->label('Còn công nợ bảo hiểm')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'invoices',
                        fn (Builder $query): Builder => $query->where('payer_type', InvoicePayer::Insurance)->outstanding(),
                    )),
                SelectFilter::make('service_advisor_id')
                    ->label('Cố vấn dịch vụ')
                    ->relationship('serviceAdvisor', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->label('Ngày tạo')
                    ->schema([
                        DatePicker::make('from')->label('Từ ngày'),
                        DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                self::printJobCardAction(),
                self::printQuotationAction(),
                self::copyQuoteLinkAction(),
                self::copyStatusLinkAction(),
                self::copyFeedbackLinkAction(),
                self::createInvoiceAction(),
                self::printInvoiceAction(),
                self::printInsuranceInvoiceAction(),
                self::approveQuoteAction(),
                self::approveAddendumAction(),
                self::cancelApprovalAction(),
                self::completeDeliveryAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function printJobCardAction(): Action
    {
        return Action::make('printJobCard')
            ->label('In Phiếu Giao Việc')
            ->icon(Heroicon::OutlinedWrench)
            ->color('warning')
            ->url(fn (WorkOrder $record): string => route('work-orders.job-card', $record))
            ->openUrlInNewTab();
    }

    public static function printQuotationAction(): Action
    {
        return Action::make('printQuotation')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label('In Báo giá')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('info')
            ->url(fn (WorkOrder $record): string => route('work-orders.quotation', $record))
            ->openUrlInNewTab();
    }

    public static function copyQuoteLinkAction(): Action
    {
        return Action::make('copyQuoteLink')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label('Link duyệt BG (KH)')
            ->icon(Heroicon::OutlinedLink)
            ->color('purple')
            ->visible(fn (WorkOrder $record): bool => in_array($record->status, [
                WorkOrderStatus::Quoted,
                WorkOrderStatus::Diagnosing,
                WorkOrderStatus::Reception,
            ], true))
            ->action(function (WorkOrder $record): void {
                if ($record->status !== WorkOrderStatus::Quoted) {
                    $record->update(['status' => WorkOrderStatus::Quoted]);
                }

                $url = app(QuoteApprovalService::class)->publicUrl($record->fresh() ?? $record);
                Notification::make()
                    ->title('Link duyệt báo giá cho khách')
                    ->body($url)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public static function copyStatusLinkAction(): Action
    {
        return Action::make('copyStatusLink')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label('Link theo dõi RO')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->action(function (WorkOrder $record): void {
                $url = app(CustomerPortalService::class)->statusUrl($record);
                Notification::make()
                    ->title('Link theo dõi cho khách')
                    ->body($url)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public static function copyFeedbackLinkAction(): Action
    {
        return Action::make('copyFeedbackLink')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label('Link đánh giá')
            ->icon(Heroicon::OutlinedStar)
            ->color('warning')
            ->visible(fn (WorkOrder $record): bool => $record->delivered_at !== null)
            ->action(function (WorkOrder $record): void {
                $url = app(CustomerPortalService::class)->feedbackUrl($record);
                Notification::make()
                    ->title('Link đánh giá sau sửa')
                    ->body($url)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public static function createInvoiceAction(): Action
    {
        return Action::make('createInvoice')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label('Tạo Hóa đơn & Thanh toán')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (WorkOrder $record): bool => in_array($record->status, [
                WorkOrderStatus::CustomerApproved,
                WorkOrderStatus::InProgress,
                WorkOrderStatus::Completed,
            ], true) && (! $record->isInsuranceClaim() || self::pendingPayers($record) !== []))
            ->schema([
                Select::make('payer')
                    ->label('Lập hóa đơn cho')
                    ->options(fn (WorkOrder $record): array => collect(self::pendingPayers($record))
                        ->mapWithKeys(fn (InvoicePayer $payer): array => [$payer->value => self::payerOptionLabel($record, $payer)])
                        ->all())
                    ->default(fn (WorkOrder $record): string => self::defaultPayer($record)->value)
                    ->required()
                    ->native(false)
                    ->live()
                    ->visible(fn (WorkOrder $record): bool => $record->isInsuranceClaim())
                    ->afterStateUpdated(function (WorkOrder $record, Set $set, mixed $state): void {
                        $set('paid_amount', self::defaultPaidAmount($record, self::payerValue($state)));
                    }),
                Select::make('payment_method')
                    ->label('Phương thức thanh toán')
                    ->options(PaymentMethod::class)
                    ->required(fn (Get $get): bool => self::payerValue($get('payer')) === InvoicePayer::Customer)
                    ->hidden(fn (Get $get): bool => self::payerValue($get('payer')) === InvoicePayer::Insurance)
                    ->native(false),
                MoneyInput::make('paid_amount')
                    ->label('Số tiền đã thu')
                    ->required()
                    ->helperText(fn (WorkOrder $record, Get $get): string => self::paidAmountHint($record, self::payerValue($get('payer'))))
                    ->default(fn (WorkOrder $record): int => self::defaultPaidAmount($record, self::defaultPayer($record))),
                Textarea::make('notes')
                    ->label('Ghi chú thanh toán')
                    ->rows(3),
            ])
            ->modalHeading('Tạo hóa đơn và ghi nhận thanh toán')
            ->modalDescription(fn (WorkOrder $record): ?string => self::splitSummary($record))
            ->modalSubmitActionLabel('Tạo hóa đơn')
            ->action(function (WorkOrder $record, array $data): void {
                try {
                    $payer = self::payerValue($data['payer'] ?? null);
                    $method = match (true) {
                        $payer === InvoicePayer::Insurance => PaymentMethod::Insurance,
                        $data['payment_method'] instanceof PaymentMethod => $data['payment_method'],
                        default => PaymentMethod::from((string) $data['payment_method']),
                    };

                    $invoice = app(WorkOrderService::class)->issueInvoice(
                        $record,
                        $method,
                        VietnameseMoney::parse($data['paid_amount']) ?? 0,
                        isset($data['notes']) ? (string) $data['notes'] : null,
                        $payer,
                    );

                    Notification::make()
                        ->title('Đã tạo hóa đơn '.$invoice->invoice_number)
                        ->body($record->isInsuranceClaim()
                            ? $payer->getLabel().' trả '.VietnameseMoney::format($invoice->total_amount)
                            : null)
                        ->success()
                        ->actions([
                            Action::make('viewInvoice')
                                ->label('Xem & In Hóa đơn')
                                ->url(route('invoices.pdf', $invoice))
                                ->openUrlInNewTab(),
                        ])
                        ->send();
                } catch (DomainException $exception) {
                    Notification::make()
                        ->title('Không thể tạo hóa đơn')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function printInvoiceAction(): Action
    {
        return Action::make('printInvoice')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label(fn (WorkOrder $record): string => $record->isInsuranceClaim() ? 'In HĐ khách' : 'In Hóa đơn')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('success')
            ->visible(fn (WorkOrder $record): bool => $record->isInsuranceClaim()
                ? $record->invoiceFor(InvoicePayer::Customer) instanceof Invoice
                : (int) ($record->invoices_count ?? $record->invoices()->count()) > 0)
            ->url(fn (WorkOrder $record): string => route('work-orders.invoice', $record))
            ->openUrlInNewTab();
    }

    public static function printInsuranceInvoiceAction(): Action
    {
        return Action::make('printInsuranceInvoice')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label('In HĐ bảo hiểm')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('info')
            ->visible(fn (WorkOrder $record): bool => $record->isInsuranceClaim() && $record->invoiceFor(InvoicePayer::Insurance) instanceof Invoice)
            ->url(function (WorkOrder $record): ?string {
                $invoice = $record->invoiceFor(InvoicePayer::Insurance);

                return $invoice instanceof Invoice ? route('invoices.pdf', $invoice) : null;
            })
            ->openUrlInNewTab();
    }

    /**
     * Các bên chưa có hóa đơn trên lệnh. Lệnh bảo hiểm: khách và/hoặc công ty bảo hiểm, bỏ bên có số tiền 0.
     *
     * @return list<InvoicePayer>
     */
    public static function pendingPayers(WorkOrder $record): array
    {
        if (! $record->isInsuranceClaim()) {
            return [InvoicePayer::Customer];
        }

        $issued = $record->invoices()
            ->pluck('payer_type')
            ->map(fn (mixed $payer): string => $payer instanceof InvoicePayer ? $payer->value : (string) $payer)
            ->all();
        $split = $record->paymentSplit();

        return array_values(array_filter(
            InvoicePayer::cases(),
            fn (InvoicePayer $payer): bool => ! in_array($payer->value, $issued, true) && $split[$payer->value] > 0,
        ));
    }

    public static function defaultPayer(WorkOrder $record): InvoicePayer
    {
        return self::pendingPayers($record)[0] ?? InvoicePayer::Customer;
    }

    /**
     * Khách thường trả đủ tại quầy; bảo hiểm thường chuyển khoản sau nên mặc định chưa thu.
     */
    public static function defaultPaidAmount(WorkOrder $record, InvoicePayer $payer): int
    {
        return $payer === InvoicePayer::Insurance ? 0 : (int) round($record->amountDueFrom($payer));
    }

    public static function payerValue(mixed $payer): InvoicePayer
    {
        if ($payer instanceof InvoicePayer) {
            return $payer;
        }

        return InvoicePayer::tryFrom((string) $payer) ?? InvoicePayer::Customer;
    }

    public static function payerOptionLabel(WorkOrder $record, InvoicePayer $payer): string
    {
        $name = $payer === InvoicePayer::Insurance && filled($record->insurance_company)
            ? $payer->getLabel().' ('.$record->insurance_company.')'
            : $payer->getLabel();

        return $name.' — '.VietnameseMoney::format($record->amountDueFrom($payer));
    }

    public static function paidAmountHint(WorkOrder $record, InvoicePayer $payer): string
    {
        $hint = 'Phải thu: '.VietnameseMoney::format($record->amountDueFrom($payer));

        return $payer === InvoicePayer::Insurance ? $hint.' — để 0 nếu bảo hiểm chưa chuyển tiền.' : $hint;
    }

    public static function splitSummary(WorkOrder $record): ?string
    {
        if (! $record->isInsuranceClaim()) {
            return null;
        }

        $split = $record->paymentSplit();

        return 'Tổng '.VietnameseMoney::format($record->final_amount)
            .'. Khách trả '.VietnameseMoney::format($split['customer'])
            .' (khấu trừ '.VietnameseMoney::format($split['deductible'])
            .' + ngoài phạm vi BH '.VietnameseMoney::format($split['outside_scope']).')'
            .'. Bảo hiểm trả '.VietnameseMoney::format($split['insurance']).'.';
    }

    public static function approveQuoteAction(): Action
    {
        return Action::make('approveQuote')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label('Duyệt báo giá')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Duyệt báo giá')
            ->modalDescription('Phụ tùng kho được giữ chỗ cho xe này, tồn kho chỉ trừ khi bàn giao xe. Thiếu hàng vẫn duyệt được, lệnh sẽ được gắn cờ cần nhập thêm.')
            ->visible(fn (WorkOrder $record): bool => in_array($record->status, [WorkOrderStatus::Reception, WorkOrderStatus::Diagnosing, WorkOrderStatus::Quoted], true))
            ->action(function (WorkOrder $record): void {
                try {
                    $warnings = app(WorkOrderService::class)->approveWorkOrder($record, self::actorId());

                    if ($warnings !== []) {
                        Notification::make()
                            ->title('Đã duyệt — kho đang thiếu hàng, cần nhập thêm')
                            ->body(implode("\n", $warnings))
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Đã duyệt báo giá, phụ tùng đã được giữ chỗ')
                        ->success()
                        ->send();
                } catch (DomainException $exception) {
                    Notification::make()
                        ->title('Không thể duyệt báo giá')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function approveAddendumAction(): Action
    {
        return Action::make('approveAddendum')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label(fn (WorkOrder $record): string => 'Chốt phát sinh đợt '.max(1, $record->maxRevision() - 1))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('purple')
            ->requiresConfirmation()
            ->modalHeading('Chốt báo giá phát sinh với khách')
            ->modalDescription('Hạng mục phát sinh khách đồng ý sẽ được giữ kho và tính vào tổng tiền. Hạng mục khách từ chối được lưu làm khuyến cáo trong bệnh án xe khi bàn giao.')
            ->visible(fn (WorkOrder $record): bool => $record->status->stockIsCommitted()
                && $record->status !== WorkOrderStatus::Delivered
                && $record->maxRevision() > max(1, (int) $record->approved_revision))
            ->action(function (WorkOrder $record): void {
                try {
                    $warnings = app(WorkOrderService::class)->approveAddendum($record, self::actorId());

                    if ($warnings !== []) {
                        Notification::make()
                            ->title('Đã chốt phát sinh — kho đang thiếu hàng')
                            ->body(implode("\n", $warnings))
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Đã chốt phát sinh với khách')
                        ->success()
                        ->send();
                } catch (DomainException $exception) {
                    Notification::make()
                        ->title('Không thể chốt phát sinh')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function cancelApprovalAction(): Action
    {
        return Action::make('cancelApproval')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label('Hủy duyệt')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Hủy duyệt báo giá')
            ->modalDescription('Phụ tùng đang giữ chỗ được nhả lại, hàng mua ngoài nhập lại kho. Lệnh quay về tiếp nhận.')
            ->visible(fn (WorkOrder $record): bool => $record->status === WorkOrderStatus::CustomerApproved)
            ->action(function (WorkOrder $record): void {
                try {
                    app(WorkOrderService::class)->cancelApproval($record, self::actorId());
                    Notification::make()
                        ->title('Đã hủy duyệt báo giá')
                        ->success()
                        ->send();
                } catch (DomainException $exception) {
                    Notification::make()
                        ->title('Không thể hoàn kho')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function completeDeliveryAction(): Action
    {
        return Action::make('completeDelivery')
            ->authorize(fn (): bool => self::isOfficeUser())
            ->label('Hoàn thành & Bàn giao')
            ->icon(Heroicon::OutlinedTruck)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Bàn giao xe')
            ->modalDescription('Phụ tùng kho được xuất chính thức và chốt lãi gộp của lệnh. ODO xe được cập nhật nếu số km tiếp nhận lớn hơn ODO hiện tại.')
            ->visible(fn (WorkOrder $record): bool => $record->status->stockIsCommitted() && $record->status !== WorkOrderStatus::Delivered)
            ->action(function (WorkOrder $record): void {
                try {
                    $warnings = app(WorkOrderService::class)->completeDelivery($record, self::actorId());

                    if ($warnings !== []) {
                        Notification::make()
                            ->title('Đã bàn giao xe — tồn kho bị âm, cần nhập bù')
                            ->body(implode("\n", $warnings))
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Đã bàn giao xe')
                        ->body(User::currentCanSeeCosts() ? 'Lãi gộp: '.VietnameseMoney::format($record->gross_profit) : null)
                        ->success()
                        ->send();
                } catch (DomainException $exception) {
                    Notification::make()
                        ->title('Không thể bàn giao')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @return array<class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
            'create' => CreateWorkOrder::route('/create'),
            'view' => ViewWorkOrder::route('/{record}'),
            'edit' => EditWorkOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->forCurrentBranch()
            ->with(['vehicle.customer', 'serviceAdvisor', 'branch'])
            ->withCount('invoices');
    }

    public static function vehicleLabel(Vehicle $vehicle): string
    {
        $owner = $vehicle->customer?->name ?? 'Chưa có chủ xe';

        return "{$vehicle->license_plate} — {$owner}";
    }

    /**
     * @return array<int|string, string>
     */
    public static function itemOptions(mixed $itemType): array
    {
        return match (self::itemTypeValue($itemType)) {
            WorkOrderItemType::Part->value => Part::query()
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Part $part): array => [
                    $part->id => "{$part->name} (khả dụng: {$part->availableQuantity()} {$part->unit})",
                ])
                ->all(),
            WorkOrderItemType::Service->value => Service::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            default => [],
        };
    }

    public static function catalogPrice(mixed $itemType, mixed $itemId): ?string
    {
        if (blank($itemId)) {
            return null;
        }

        $price = match (self::itemTypeValue($itemType)) {
            WorkOrderItemType::Part->value => Part::query()->find($itemId)?->selling_price,
            WorkOrderItemType::Service->value => Service::query()->find($itemId)?->labor_cost,
            default => null,
        };

        return $price === null ? null : (string) $price;
    }

    public static function syncLineSubtotal(Get $get, Set $set, mixed $unitPrice = null): void
    {
        if (self::isCustomerSupplied($get('item_source')) || self::isDeclined($get('is_approved'))) {
            $set('subtotal', 0);

            return;
        }

        $price = VietnameseMoney::parse($unitPrice ?? $get('unit_price'));
        $discount = VietnameseMoney::parse($get('discount_amount'));
        $set('subtotal', WorkOrderItem::calculateSubtotal($get('quantity'), $price, $discount));
    }

    public static function isCommitted(?WorkOrder $record): bool
    {
        return $record instanceof WorkOrder && $record->status->stockIsCommitted();
    }

    /**
     * Dòng đã chốt với khách (đã giữ kho) thì khóa; dòng phát sinh chưa chốt vẫn sửa được.
     */
    public static function isLockedLine(mixed $item): bool
    {
        if (! $item instanceof WorkOrderItem || ! $item->exists) {
            return false;
        }

        $order = $item->workOrder;

        return $order instanceof WorkOrder
            && $order->status->stockIsCommitted()
            && (int) $item->revision_no <= max(1, (int) $order->approved_revision);
    }

    public static function isDeclined(mixed $isApproved): bool
    {
        return $isApproved === false || $isApproved === 0 || $isApproved === '0';
    }

    /**
     * @return array<string, mixed>
     */
    public static function blankLine(int $revision): array
    {
        return [
            'revision_no' => $revision,
            'item_type' => null,
            'item_source' => WorkOrderItemSource::Stock->value,
            'item_id' => null,
            'quantity' => 1,
            'unit_price' => null,
            'discount_amount' => 0,
            'subtotal' => 0,
            'cost_price' => 0,
            'supplier_id' => null,
            'supplier_info' => null,
            'warranty_policy' => null,
            'technician_id' => null,
            'finding_note' => null,
            'is_approved' => true,
            'decline_reason' => null,
            'is_outside_insurance' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function itemLabel(array $state): Htmlable
    {
        $revision = (int) ($state['revision_no'] ?? 1);
        $name = self::catalogName($state['item_type'] ?? null, $state['item_id'] ?? null) ?? 'Hạng mục mới';
        $label = $revision > 1
            ? '<span style="background:#7c3aed;color:#fff;border-radius:6px;padding:1px 8px;font-size:12px;font-weight:700;margin-right:6px">[Phát sinh đợt '.($revision - 1).']</span>'
            : '';

        if (self::isDeclined($state['is_approved'] ?? true)) {
            return new HtmlString($label.'<s style="opacity:.6">'.e($name).'</s> <span style="color:#dc2626;font-weight:700;font-size:12px">KHÁCH TỪ CHỐI · 0 đ</span>');
        }

        return new HtmlString($label.e($name).' — '.e(VietnameseMoney::format(VietnameseMoney::parse($state['subtotal'] ?? 0))));
    }

    public static function catalogName(mixed $itemType, mixed $itemId): ?string
    {
        if (blank($itemId)) {
            return null;
        }

        return match (self::itemTypeValue($itemType)) {
            WorkOrderItemType::Part->value => Part::query()->find($itemId)?->name,
            WorkOrderItemType::Service->value => Service::query()->find($itemId)?->name,
            default => null,
        };
    }

    public static function isOfficeUser(): bool
    {
        return User::current()?->isTechnician() !== true;
    }

    public static function advisorDiscountLimitPercent(): float
    {
        return (float) config('garage.advisor_max_discount_percent', 10);
    }

    /**
     * Chủ gara không bị giới hạn; console/test không đăng nhập cũng vậy.
     */
    public static function priceLimitsApply(): bool
    {
        $user = User::current();

        return $user instanceof User && ! $user->isAdmin();
    }

    /**
     * Cố vấn không được bán hàng/công theo bảng giá thấp hơn mức cho phép.
     */
    public static function advisorPriceRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! self::priceLimitsApply() || self::sourceValue($get('item_source')) !== WorkOrderItemSource::Stock->value) {
                return;
            }

            $catalog = (float) self::catalogPrice($get('item_type'), $get('item_id'));
            $price = (float) VietnameseMoney::parse($value);
            $floor = round($catalog * (1 - self::advisorDiscountLimitPercent() / 100));

            if ($catalog > 0 && $price < $floor) {
                $fail('Giá thấp hơn bảng giá ('.VietnameseMoney::format($catalog).') quá '.self::advisorDiscountLimitPercent().'%. Cần chủ gara sửa giá.');
            }
        };
    }

    public static function advisorLineDiscountRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! self::priceLimitsApply()) {
                return;
            }

            $gross = (float) $get('quantity') * (float) VietnameseMoney::parse($get('unit_price'));
            $limit = round($gross * self::advisorDiscountLimitPercent() / 100);

            if ((float) VietnameseMoney::parse($value) > $limit) {
                $fail('Giảm giá dòng tối đa '.self::advisorDiscountLimitPercent().'% ('.VietnameseMoney::format($limit).'). Cần chủ gara duyệt.');
            }
        };
    }

    public static function advisorOrderDiscountRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! self::priceLimitsApply()) {
                return;
            }

            $summary = self::summarizeQuote($get);
            $beforeDiscount = $summary['net'] + (float) VietnameseMoney::parse($get('discount_amount'));
            $limit = round($beforeDiscount * self::advisorDiscountLimitPercent() / 100);

            if ((float) VietnameseMoney::parse($value) > $limit) {
                $fail('Giảm giá toàn đơn tối đa '.self::advisorDiscountLimitPercent().'% ('.VietnameseMoney::format($limit).'). Cần chủ gara duyệt.');
            }
        };
    }

    public static function sourceValue(mixed $source): string
    {
        if ($source instanceof WorkOrderItemSource) {
            return $source->value;
        }

        return is_string($source) && $source !== '' ? $source : WorkOrderItemSource::Stock->value;
    }

    public static function isCustomerSupplied(mixed $source): bool
    {
        return self::sourceValue($source) === WorkOrderItemSource::CustomerSupplied->value;
    }

    /**
     * @return array{net: float, final: float}
     */
    public static function summarizeQuote(Get $get): array
    {
        $parts = 0.0;
        $labor = 0.0;

        foreach ($get('items') ?? [] as $item) {
            if (! is_array($item) || ($item['is_approved'] ?? true) === false) {
                continue;
            }

            $subtotal = (float) VietnameseMoney::parse($item['subtotal'] ?? 0);

            if (self::itemTypeValue($item['item_type'] ?? null) === WorkOrderItemType::Part->value) {
                $parts += $subtotal;
            } elseif (self::itemTypeValue($item['item_type'] ?? null) === WorkOrderItemType::Service->value) {
                $labor += $subtotal;
            }
        }

        $net = round($parts + $labor - (float) VietnameseMoney::parse($get('discount_amount')), 2);
        $vatRate = (int) ($get('vat_rate') ?? 0);
        $final = round($net * (1 + ($vatRate / 100)), 2);

        return ['net' => $net, 'final' => $final];
    }

    public static function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }

    public static function itemTypeValue(mixed $itemType): ?string
    {
        if ($itemType instanceof WorkOrderItemType) {
            return $itemType->value;
        }

        return is_string($itemType) ? $itemType : null;
    }

    public static function actorId(): int
    {
        $actorId = auth()->id();

        if (! is_numeric($actorId) || (int) $actorId < 1) {
            throw new DomainException('Người thực hiện không hợp lệ.');
        }

        return (int) $actorId;
    }
}
