<?php

/*
 * Chỉ ghi đè khoá 'broadcasting'; các khoá khác lấy mặc định của Filament.
 *
 * Echo trong panel kết nối Reverb khi có REVERB_APP_KEY. Không có key → không tạo Echo, các màn
 * Bảng điều phối xe / Việc của tôi tự làm mới theo chu kỳ như trước.
 */

$reverbScheme = env('VITE_REVERB_SCHEME', env('REVERB_SCHEME', 'http'));

return [

    'broadcasting' => [
        'echo' => filled(env('REVERB_APP_KEY')) ? [
            'broadcaster' => 'reverb',
            'key' => env('VITE_REVERB_APP_KEY', env('REVERB_APP_KEY')),
            'wsHost' => env('VITE_REVERB_HOST', env('REVERB_HOST', 'localhost')),
            'wsPort' => (int) env('VITE_REVERB_PORT', env('REVERB_PORT', 8080)),
            'wssPort' => (int) env('VITE_REVERB_PORT', env('REVERB_PORT', 443)),
            'forceTLS' => $reverbScheme === 'https',
            'enabledTransports' => ['ws', 'wss'],
            'authEndpoint' => '/broadcasting/auth',
            'disableStats' => true,
        ] : null,
    ],

];
