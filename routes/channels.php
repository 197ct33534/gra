<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Bảng điều phối xe / Việc của tôi: mọi nhân viên đã đăng nhập. Tín hiệu chỉ mang mã lệnh,
// dữ liệu thật được tải lại theo quyền của từng người.
Broadcast::channel('workshop', fn (User $user): bool => true);
