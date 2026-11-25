{{--
    Nghe tín hiệu 'board.changed' (kênh riêng 'workshop', gửi qua Reverb) và làm mới component Livewire
    của trang đang mở. Không có Reverb (Echo chưa cấu hình / server tắt) thì không làm gì: trang vẫn tự
    làm mới theo wire:poll dự phòng.
--}}
<div
    x-data="{ timer: null }"
    x-init="
        const refresh = () => { clearTimeout(timer); timer = setTimeout(() => $wire.$refresh(), 300) };
        const listen = () => window.Echo?.private('workshop').listen('.board.changed', refresh);
        window.Echo ? listen() : window.addEventListener('EchoLoaded', listen, { once: true });
    "
    style="display: none"
></div>
