@php
    $user = \App\Models\User::current();
    $canTour = $user?->isAdmin() || $user?->role === \App\Enums\UserRole::ServiceAdvisor;
    $onDashboard = request()->routeIs('filament.admin.pages.dashboard');
    $autostart = $onDashboard && ($user?->onboarding_tour_seen_at === null || request()->boolean('tour'));
    $boss = $user?->isAdmin() ?? false;

    // Kịch bản Robot "Cờ Lê" — giữ khớp với Phần 1 của Cẩm nang sử dụng.
    $steps = [
        [
            'title' => 'Chào sân',
            'text' => ($boss ? 'Chào anh Hai! ' : 'Chào anh em! ').'Em là Cờ Lê, trợ lý của xưởng mình. Cho em 1 phút dẫn anh đi một vòng, sáng ra mở máy là anh nắm hết tình hình xưởng.',
        ],
        [
            'title' => 'Tiền và lãi',
            'heading' => 'Lãi gộp & dấu hiệu thất thoát',
            'optional' => true,
            'text' => 'Đây là lãi gộp thật của tháng, đã trừ giá vốn phụ tùng và tiền trả ngoài. Ô nào báo đỏ là có bán dưới bảng giá, giảm giá hay chỉnh kho tay bất thường, anh hỏi ngay SA phụ trách.',
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
            'menu' => \App\Filament\Resources\Vehicles\VehicleResource::getUrl(),
            'text' => 'Mỗi xe có một cuốn bệnh án: lần nào vào, ODO bao nhiêu, làm gì, khách từ chối món gì. Xe nào báo Đến hạn bảo dưỡng là tiền đang nằm ngoài đường, anh cho SA gọi mời về nhé.',
        ],
        [
            'title' => 'Chỗ nhờ trợ giúp',
            'selector' => '[data-tour="help-button"]',
            'text' => 'Quên chỗ nào thì bấm dấu hỏi này, em dẫn lại từ đầu. Chúc xưởng mình hôm nay đầy cầu nâng!',
        ],
    ];
@endphp

@if ($canTour && $onDashboard)
    <style>
        .gt-blocker { position: fixed; inset: 0; z-index: 9998; background: transparent; }
        .gt-blocker.gt-dim { background: rgba(15, 23, 42, .62); }
        .gt-hole { position: fixed; z-index: 9999; border-radius: 12px; pointer-events: none;
            box-shadow: 0 0 0 3px rgb(245 158 11), 0 0 0 9999px rgba(15, 23, 42, .62);
            transition: top .25s ease, left .25s ease, width .25s ease, height .25s ease; }
        .gt-pop { position: fixed; z-index: 10000; width: min(360px, calc(100vw - 32px)); padding: 16px;
            border-radius: 14px; background: #fff; color: #1f2937; box-shadow: 0 18px 40px rgba(0, 0, 0, .28);
            font-size: 14px; line-height: 1.55; }
        .dark .gt-pop { background: #18181b; color: #f4f4f5; box-shadow: 0 18px 40px rgba(0, 0, 0, .6); }
        .gt-head { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
        .gt-bot { flex: none; width: 44px; height: 44px; }
        .gt-title { font-weight: 700; font-size: 15px; }
        .gt-step { font-size: 12px; color: #6b7280; }
        .dark .gt-step { color: #a1a1aa; }
        .gt-actions { display: flex; align-items: center; gap: 8px; margin-top: 14px; }
        .gt-actions .gt-gap { flex: 1; }
        .gt-btn { border-radius: 8px; padding: 7px 14px; font-weight: 600; font-size: 13px; cursor: pointer; border: 1px solid transparent; }
        .gt-next { background: rgb(245 158 11); color: #1f1300; }
        .gt-next:hover { background: rgb(217 119 6); }
        .gt-prev { background: transparent; border-color: #d1d5db; color: inherit; }
        .dark .gt-prev { border-color: #3f3f46; }
        .gt-skip { background: none; border: 0; padding: 7px 4px; color: #6b7280; font-size: 13px; cursor: pointer; text-decoration: underline; }
        .dark .gt-skip { color: #a1a1aa; }
        @media (max-width: 639px) {
            .gt-pop { left: 16px !important; right: 16px; bottom: 16px; top: auto !important; width: auto; }
        }
    </style>

    <script>
        (() => {
            const STEPS = @js($steps);
            const SEEN_URL = @js(route('onboarding-tour.seen'));
            const AUTOSTART = @js($autostart);

            const BOT = '<svg class="gt-bot" viewBox="0 0 48 48" aria-hidden="true">'
                + '<line x1="24" y1="4" x2="24" y2="10" stroke="#78716c" stroke-width="2"/><circle cx="24" cy="4" r="3" fill="#f59e0b"/>'
                + '<rect x="8" y="10" width="32" height="26" rx="8" fill="#f59e0b"/>'
                + '<rect x="13" y="16" width="22" height="12" rx="5" fill="#1f2937"/>'
                + '<circle cx="19" cy="22" r="2.6" fill="#fde68a"/><circle cx="29" cy="22" r="2.6" fill="#fde68a"/>'
                + '<rect x="16" y="38" width="16" height="8" rx="3" fill="#78716c"/>'
                + '<path d="M40 30l6 6-3 3-6-6" fill="#a8a29e"/></svg>';

            const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
            const isMobile = () => window.matchMedia('(max-width: 639px)').matches;

            const visible = (el) => {
                if (! el) return false;
                const r = el.getBoundingClientRect();
                return r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth;
            };

            const resolve = (step) => {
                if (step.selector) return document.querySelector(step.selector);
                if (step.heading) {
                    return [...document.querySelectorAll('.fi-wi-widget')]
                        .find((widget) => widget.textContent.includes(step.heading)) || null;
                }
                if (step.menu) {
                    const link = [...document.querySelectorAll('.fi-sidebar a')]
                        .find((a) => a.href.split('?')[0] === step.menu);
                    return link ? (link.closest('li') || link) : null;
                }
                return null;
            };

            const sidebar = () => window.Alpine?.store('sidebar');

            let steps = [];
            let index = 0;
            let els = null;
            let openedSidebar = false;

            const reposition = () => {
                if (! els) return;
                const step = steps[index];
                const target = resolve(step);
                const pop = els.pop;

                if (! step.target || ! visible(target)) {
                    els.hole.style.display = 'none';
                    els.blocker.classList.add('gt-dim');
                    pop.style.left = Math.max(16, (window.innerWidth - pop.offsetWidth) / 2) + 'px';
                    pop.style.top = Math.max(16, (window.innerHeight - pop.offsetHeight) / 2) + 'px';
                    return;
                }

                els.blocker.classList.remove('gt-dim');
                const pad = 8;
                const r = target.getBoundingClientRect();
                const hole = { top: r.top - pad, left: r.left - pad, width: r.width + pad * 2, height: r.height + pad * 2 };
                Object.assign(els.hole.style, {
                    display: 'block', top: hole.top + 'px', left: hole.left + 'px', width: hole.width + 'px', height: hole.height + 'px',
                });

                if (isMobile()) return; // CSS ghim khung thoại sát đáy màn hình.

                const gap = 14;
                const pw = pop.offsetWidth;
                const ph = pop.offsetHeight;
                const vw = window.innerWidth;
                const vh = window.innerHeight;
                const clampX = (x) => Math.min(Math.max(16, x), vw - pw - 16);
                const clampY = (y) => Math.min(Math.max(16, y), vh - ph - 16);
                let top;
                let left;

                if (hole.top + hole.height + gap + ph <= vh - 16) {
                    top = hole.top + hole.height + gap; left = clampX(hole.left);
                } else if (hole.top - gap - ph >= 16) {
                    top = hole.top - gap - ph; left = clampX(hole.left);
                } else if (hole.left + hole.width + gap + pw <= vw - 16) {
                    left = hole.left + hole.width + gap; top = clampY(hole.top);
                } else if (hole.left - gap - pw >= 16) {
                    left = hole.left - gap - pw; top = clampY(hole.top);
                } else {
                    left = clampX(hole.left); top = vh - ph - 16;
                }

                pop.style.left = left + 'px';
                pop.style.top = top + 'px';
            };

            const render = async () => {
                const step = steps[index];
                const last = index === steps.length - 1;

                if (openedSidebar && ! step.menu) {
                    sidebar()?.close();
                    openedSidebar = false;
                }

                let target = resolve(step);
                if (step.menu && target && ! visible(target) && sidebar()) {
                    sidebar().open();
                    openedSidebar = true;
                    await sleep(320);
                    target = resolve(step);
                }
                step.target = !! target;

                els.pop.innerHTML = '<div class="gt-head">' + BOT + '<div><div class="gt-title"></div>'
                    + '<div class="gt-step">Cờ Lê dẫn đường · ' + (index + 1) + '/' + steps.length + '</div></div></div>'
                    + '<div class="gt-text"></div>'
                    + '<div class="gt-actions"><button type="button" class="gt-skip" data-act="skip">Bỏ qua</button><span class="gt-gap"></span>'
                    + (index > 0 ? '<button type="button" class="gt-btn gt-prev" data-act="prev">Quay lại</button>' : '')
                    + '<button type="button" class="gt-btn gt-next" data-act="next">' + (last ? 'Xong, vào việc' : 'Tiếp tục') + '</button></div>';
                els.pop.querySelector('.gt-title').textContent = step.title;
                els.pop.querySelector('.gt-text').textContent = step.text;
                if (last) els.pop.querySelector('.gt-skip').remove();

                if (target && visible(target)) {
                    target.scrollIntoView({ block: isMobile() ? 'start' : 'center', behavior: 'smooth' });
                    await sleep(380);
                }
                reposition();
                els.pop.querySelector('.gt-next').focus({ preventScroll: true });
            };

            const markSeen = () => {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                fetch(SEEN_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': token || '', Accept: 'application/json' },
                }).catch(() => {});
            };

            const onKey = (event) => {
                if (event.key === 'Escape') finish();
                else if (event.key === 'ArrowRight') go(1);
                else if (event.key === 'ArrowLeft') go(-1);
            };

            const finish = () => {
                if (! els) return;
                if (openedSidebar) sidebar()?.close();
                openedSidebar = false;
                Object.values(els).forEach((el) => el.remove());
                els = null;
                window.removeEventListener('resize', reposition);
                window.removeEventListener('scroll', reposition, true);
                document.removeEventListener('keydown', onKey);
                markSeen();

                const url = new URL(window.location.href);
                if (url.searchParams.has('tour')) {
                    url.searchParams.delete('tour');
                    history.replaceState(null, '', url);
                }
            };

            const go = (delta) => {
                const next = index + delta;
                if (next < 0) return;
                if (next >= steps.length) return finish();
                index = next;
                render();
            };

            // Widget trên bảng điều khiển nạp chậm (lazy): chờ tối đa ~3 giây cho các khối cần làm sáng.
            const waitForWidgets = async () => {
                for (let i = 0; i < 20; i++) {
                    if (STEPS.every((step) => ! step.optional || resolve(step))) return;
                    await sleep(150);
                }
            };

            const start = async () => {
                if (els) return;
                await waitForWidgets();
                steps = STEPS.filter((step) => ! step.optional || resolve(step)).map((step) => ({ ...step }));
                index = 0;

                const blocker = document.createElement('div');
                blocker.className = 'gt-blocker';
                const hole = document.createElement('div');
                hole.className = 'gt-hole';
                const pop = document.createElement('div');
                pop.className = 'gt-pop';
                pop.setAttribute('role', 'dialog');
                pop.setAttribute('aria-live', 'polite');
                pop.addEventListener('click', (event) => {
                    const act = event.target.closest('[data-act]')?.dataset.act;
                    if (act === 'next') go(1);
                    if (act === 'prev') go(-1);
                    if (act === 'skip') finish();
                });
                document.body.append(blocker, hole, pop);
                els = { blocker, hole, pop };

                window.addEventListener('resize', reposition);
                window.addEventListener('scroll', reposition, true);
                document.addEventListener('keydown', onKey);
                render();
            };

            window.garageTour = { start };

            if (AUTOSTART) {
                if (document.readyState === 'complete') start();
                else window.addEventListener('load', () => start(), { once: true });
            }
        })();
    </script>
@endif
