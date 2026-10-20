<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ActivityEvent: string implements HasColor, HasLabel
{
    case BelowCatalog = 'below_catalog';
    case PriceChanged = 'price_changed';
    case DiscountChanged = 'discount_changed';
    case ItemDeclined = 'item_declined';
    case ItemDeleted = 'item_deleted';
    case OrderDeleted = 'order_deleted';
    case StatusChanged = 'status_changed';
    case InvoiceIssued = 'invoice_issued';
    case PaymentRecorded = 'payment_recorded';
    case InvoiceDeleted = 'invoice_deleted';
    case StockAdjusted = 'stock_adjusted';
    case CatalogPriceChanged = 'catalog_price_changed';
    case GoodsReceived = 'goods_received';

    public function getLabel(): string
    {
        return match ($this) {
            self::BelowCatalog => 'Bán dưới bảng giá',
            self::PriceChanged => 'Sửa đơn giá',
            self::DiscountChanged => 'Đổi giảm giá',
            self::ItemDeclined => 'Khách từ chối hạng mục',
            self::ItemDeleted => 'Xóa hạng mục',
            self::OrderDeleted => 'Xóa lệnh sửa chữa',
            self::StatusChanged => 'Đổi trạng thái lệnh',
            self::InvoiceIssued => 'Lập hóa đơn',
            self::PaymentRecorded => 'Thu tiền',
            self::InvoiceDeleted => 'Xóa hóa đơn',
            self::StockAdjusted => 'Chỉnh tồn kho tay',
            self::CatalogPriceChanged => 'Sửa bảng giá',
            self::GoodsReceived => 'Nhập kho từ NCC',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BelowCatalog, self::ItemDeleted, self::OrderDeleted, self::InvoiceDeleted, self::StockAdjusted => 'danger',
            self::PriceChanged, self::DiscountChanged, self::CatalogPriceChanged => 'warning',
            self::ItemDeclined => 'purple',
            self::InvoiceIssued, self::PaymentRecorded, self::GoodsReceived => 'success',
            self::StatusChanged => 'gray',
        };
    }

    /**
     * Sự kiện chủ gara cần soi khi nghi thất thoát.
     *
     * @return list<self>
     */
    public static function leakageSignals(): array
    {
        return [self::PriceChanged, self::ItemDeleted, self::OrderDeleted, self::InvoiceDeleted];
    }
}
