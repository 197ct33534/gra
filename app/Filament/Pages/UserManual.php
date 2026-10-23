<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\OnboardingTours;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Cẩm nang sử dụng: quy trình chuẩn của SA + bảng tra cứu tình huống khó, mở từ menu dấu hỏi.
 * Nội dung ở resources/markdown/cam-nang.md — sửa nhãn nút trên phần mềm thì sửa cả file đó.
 */
class UserManual extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Cẩm nang sử dụng';

    protected static ?string $slug = 'cam-nang';

    protected string $view = 'filament.pages.user-manual';

    public static function canAccess(): bool
    {
        return OnboardingTours::availableTo(User::current());
    }

    public function manualHtml(): HtmlString
    {
        return new HtmlString(Str::markdown(
            (string) file_get_contents(resource_path('markdown/cam-nang.md')),
            ['html_input' => 'strip', 'allow_unsafe_links' => false],
        ));
    }
}
