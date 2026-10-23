<?php

namespace App\Support;

use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;

/**
 * Kịch bản Robot "Cờ Lê" theo từng trang — giữ khớp với Cẩm nang sử dụng (Phần 1 và Phần 2).
 *
 * Mỗi bước chỉ vào một chỗ trên màn hình bằng một trong các khóa:
 * selector (CSS), heading (tiêu đề khối/bảng), field (nhãn ô nhập/ô xem), stat (nhãn ô số liệu),
 * button (chữ trên nút), menu (đường dẫn menu bên trái). Không có khóa nào = khung thoại giữa màn hình.
 * optional = không thấy chỗ đó thì bỏ hẳn bước; fallback = không thấy thì vẫn nói, kèm câu này, ở giữa màn hình.
 */
class OnboardingTours
{
    public const KEYS = ['dashboard', 'quick-reception', 'work-order', 'vehicle'];

    private const ROUTES = [
        'filament.admin.pages.dashboard' => 'dashboard',
        'filament.admin.pages.quick-reception' => 'quick-reception',
        'filament.admin.resources.work-orders.view' => 'work-order',
        'filament.admin.resources.work-orders.edit' => 'work-order',
        'filament.admin.resources.vehicles.view' => 'vehicle',
    ];

    /**
     * Tour dành cho người làm văn phòng xưởng (Giám đốc, Cố vấn); thợ dùng màn hình "Việc của tôi".
     */
    public static function availableTo(?User $user): bool
    {
        return $user !== null && ($user->isAdmin() || $user->isServiceAdvisor());
    }

    public static function keyFor(Request $request): ?string
    {
        return self::ROUTES[(string) $request->route()?->getName()] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function steps(string $tour, User $user): array
    {
        return match ($tour) {
            'dashboard' => self::dashboard($user),
            'quick-reception' => self::quickReception(),
            'work-order' => self::workOrder(),
            'vehicle' => self::vehicle(),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function dashboard(User $user): array
    {
        $sample = self::sampleVehicle();

        $steps = [
            [
                'title' => 'Chào sân',
                'text' => ($user->isAdmin() ? 'Chào anh Hai! ' : 'Chào anh em! ').'Em là Cờ Lê, trợ lý của xưởng mình. Cho em 1 phút dẫn anh đi một vòng, sáng ra mở máy là anh nắm hết tình hình xưởng.',
            ],
            [
                'title' => 'Tiền và lãi',
                'heading' => 'Lãi gộp & dấu hiệu thất thoát',
                'optional' => true,
                'ownerOnly' => true,
                'text' => 'Đây là lãi gộp thật của tháng, đã trừ giá vốn phụ tùng và tiền trả ngoài. Ô nào báo đỏ là có bán dưới bảng giá, giảm giá hay chỉnh kho tay bất thường, anh hỏi ngay SA phụ trách.',
            ],
            [
                'title' => 'Doanh thu từng ngày',
                'heading' => 'Doanh thu 7 ngày gần nhất',
                'optional' => true,
                'ownerOnly' => true,
                'text' => 'Biểu đồ này là tiền thu từng ngày trong 7 ngày qua. Ngày nào thấp là xưởng vắng, anh cho SA gọi mời xe đến hạn bảo dưỡng về lấp chỗ trống.',
            ],
            [
                'title' => 'Xe trong xưởng',
                'selector' => '[data-tour="workshop-count"]',
                'optional' => true,
                'text' => 'Xưởng đang ôm bao nhiêu xe là ở đây. Muốn biết xe nào kẹt ở khâu nào thì mở menu Bảng điều phối xe: mỗi cột là một khâu, từ Chờ chẩn đoán tới KCS & Chờ giao xe.',
            ],
            [
                'title' => 'Kho sắp cạn',
                'heading' => 'Cảnh báo phụ tùng chạm ngưỡng tồn kho',
                'optional' => true,
                'text' => 'Món nào hiện ở đây là kho sắp hết, đã trừ phần giữ cho xe khách duyệt rồi. Anh báo thủ kho vào Đề xuất nhập hàng là có sẵn số lượng cần đặt.',
            ],
            [
                'title' => 'Hồ sơ xe',
                'menu' => VehicleResource::getUrl(),
                'text' => 'Mỗi xe có một cuốn bệnh án: lần nào vào, ODO bao nhiêu, làm gì, khách từ chối món gì. Xe nào báo Đến hạn bảo dưỡng là tiền đang nằm ngoài đường, anh cho SA gọi mời về nhé.',
                'link' => $sample instanceof Vehicle
                    ? ['label' => 'Mở thử hồ sơ xe '.$sample->license_plate, 'url' => VehicleResource::getUrl('view', ['record' => $sample, 'tour' => 1])]
                    : null,
            ],
            [
                'title' => 'Chỗ nhờ trợ giúp',
                'selector' => '[data-tour="help-button"]',
                'text' => 'Quên chỗ nào thì bấm dấu hỏi này: xem lại hướng dẫn của trang đang mở, hoặc mở Cẩm nang tra 5 tình huống khó. Chúc xưởng mình hôm nay đầy cầu nâng!',
            ],
        ];

        // Lãi gộp, doanh thu từng ngày: chỉ chủ gara thấy khối đó, SA đi thẳng qua.
        return array_values(array_filter($steps, fn (array $step): bool => ! ($step['ownerOnly'] ?? false) || $user->isAdmin()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function quickReception(): array
    {
        return [
            [
                'title' => 'Nhận xe dưới 90 giây',
                'text' => 'Màn hình này để nhận xe ngay tại đầu xe, cầm điện thoại ra làm luôn. Em chỉ anh đi từ trên xuống, 7 chỗ là xong một xe.',
            ],
            [
                'title' => 'Biển số',
                'field' => 'Biển số',
                'text' => 'Gõ biển số liền một mạch, không cần chấm hay gạch. Xe quen thì dưới ô ODO hiện "Lần trước: … km", khỏi điền lại tên và số điện thoại; xe mới thì bắt buộc có SĐT khách.',
            ],
            [
                'title' => 'ODO',
                'field' => 'ODO hiện tại',
                'text' => 'Đọc đồng hồ rồi gõ đúng số km. Gõ nhỏ hơn lần trước là máy chặn ngay, anh xem lại đồng hồ.',
            ],
            [
                'title' => 'Đồ trên xe và vết trầy',
                'field' => 'Đồ trên xe',
                'text' => 'Máy tick sẵn đủ đồ, anh chỉ bỏ tick món thiếu. Vùng nào trầy, móp thì tick ở ô ngay bên dưới.',
            ],
            [
                'title' => 'Chụp ảnh hiện trạng',
                'field' => 'Ảnh hiện trạng',
                'text' => 'Chụp liên tục 4 góc xe, đồng hồ ODO và chỗ trầy, không cần đặt tên ảnh. Đây là bằng chứng khi khách bắt đền vết cũ.',
            ],
            [
                'title' => 'Lời khách',
                'field' => 'Khách phản ánh',
                'text' => 'Ghi nguyên văn lời khách, đừng tự chẩn đoán vào đây. Thợ đọc đúng lời khách sẽ bắt bệnh nhanh hơn.',
            ],
            [
                'title' => 'Gói dịch vụ, cầu nâng, giờ giao',
                'field' => 'Gói dịch vụ dựng sẵn',
                'optional' => true,
                'text' => 'Xe vào bảo dưỡng thường thì bấm một gói là ra đủ công và phụ tùng. Chọn luôn cầu nâng và giờ hẹn giao ở ngay dưới.',
            ],
            [
                'title' => 'Tiếp nhận và in phiếu',
                'button' => 'Tiếp nhận xe',
                'text' => 'Xong bấm Tiếp nhận xe, rồi bấm In Phiếu Giao Việc trên thông báo hiện ra. Phiếu không in giá, kẹp lên tấm che nắng đưa thợ.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function workOrder(): array
    {
        $later = 'Nút này hiện ở hàng nút trên cùng khi lệnh tới đúng bước.';

        return [
            [
                'title' => 'Lệnh sửa chữa',
                'text' => 'Báo giá, phát sinh, thu tiền, giao xe đều làm trên lệnh này. Em chỉ anh những chỗ hay dùng nhất, theo đúng thứ tự một chiếc xe đi qua xưởng.',
            ],
            [
                'title' => 'Hạng mục',
                'field' => 'Hạng mục',
                'optional' => true,
                'text' => 'Mỗi dòng là một món công hoặc phụ tùng. Dòng đã duyệt bị khóa; thợ tháo ra thấy thêm bệnh thì dùng nút Thêm hạng mục phát sinh, không sửa báo giá cũ.',
            ],
            [
                'title' => 'Nguồn hàng / công',
                'field' => 'Nguồn hàng / công',
                'optional' => true,
                'text' => 'Ô quan trọng nhất: Kho, Mua ngoài, Gia công ngoài hay Khách mang. Mua ngoài hoặc gia công thì phải ghi nhà cung cấp và giá vốn, không là lãi báo sai.',
            ],
            [
                'title' => 'Khách chê món nào',
                'field' => 'Khách đồng ý làm',
                'optional' => true,
                'text' => 'Khách không làm món nào thì tắt công tắc này và ghi lý do. Món đó không tính tiền, không giữ kho, giao xe xong tự vào bệnh án làm khuyến cáo.',
            ],
            [
                'title' => 'Báo giá cho khách',
                'button' => 'In Báo giá',
                'fallback' => $later,
                'text' => 'In báo giá đưa khách cầm, hoặc dùng Link duyệt BG gửi Zalo cho khách xem trên điện thoại.',
            ],
            [
                'title' => 'Khách chốt',
                'button' => 'Duyệt báo giá',
                'fallback' => $later,
                'text' => 'Khách chốt thì bấm Duyệt báo giá: máy giữ hàng trong kho và cho thợ làm. Có phát sinh thì Thêm hạng mục phát sinh rồi bấm Chốt phát sinh khi khách đồng ý.',
            ],
            [
                'title' => 'Thu tiền',
                'button' => 'Tạo Hóa đơn & Thanh toán',
                'fallback' => $later,
                'text' => 'Sửa xong bấm đây lập hóa đơn; hóa đơn in ra có mã VietQR cho khách quét. Xe bảo hiểm làm 2 lần: phần khách trả và phần bảo hiểm trả.',
            ],
            [
                'title' => 'Giao xe',
                'button' => 'Hoàn thành & Bàn giao',
                'fallback' => $later,
                'text' => 'Bấm khi trao chìa khóa cho khách: lúc này máy mới trừ kho, cập nhật ODO, chốt lãi và tính tiền khoán cho thợ.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function vehicle(): array
    {
        return [
            [
                'title' => 'Báo đến hạn',
                'field' => 'Bảo dưỡng',
                'text' => 'Ô này đỏ "Đến hạn bảo dưỡng" là xe đã chạy quá chu kỳ, mặc định 5.000 km hoặc 3 tháng. Gọi mời khách về, đừng đợi khách tự nhớ.',
            ],
            [
                'title' => 'Lần gần nhất',
                'stat' => 'Lần bảo dưỡng gần nhất',
                'optional' => true,
                'text' => 'Lần cuối xe vào làm gì, cách đây bao lâu. Nhìn đây là biết nên chào khách gói nào.',
            ],
            [
                'title' => 'Món khách còn nợ xưởng',
                'heading' => 'Các hạng mục khách đã từ chối',
                'optional' => true,
                'text' => 'Món khách chê ở các lần trước nằm hết ở đây. Xe vào lại, nhắc đúng món này là dễ chốt thêm việc.',
            ],
            [
                'title' => 'Bệnh án sửa chữa',
                'heading' => 'Bệnh án sửa chữa',
                'optional' => true,
                'text' => 'Đủ từng lần xe vào xưởng: ngày, ODO lúc vào, tổng tiền. Khách hỏi "lần trước thay gì" là mở ra đây.',
            ],
        ];
    }

    /**
     * Xe mẫu cho nút "Mở thử hồ sơ xe": ưu tiên xe đang đến hạn và vào xưởng nhiều lần.
     */
    private static function sampleVehicle(): ?Vehicle
    {
        return Vehicle::query()->maintenanceDue()->withCount('workOrders')->orderByDesc('work_orders_count')->first()
            ?? Vehicle::query()->withCount('workOrders')->orderByDesc('work_orders_count')->first();
    }
}
