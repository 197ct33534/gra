@php
    use App\Models\User;
    use App\Support\OnboardingTours;

    $user = User::current();
    $tour = OnboardingTours::availableTo($user) ? OnboardingTours::keyFor(request()) : null;
    $steps = $tour !== null ? OnboardingTours::steps($tour, $user) : [];
    $autostart = $tour !== null && (! $user->hasSeenTour($tour) || request()->boolean('tour'));
@endphp

@if ($tour !== null && $steps !== [])
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
        .gt-note { margin-top: 6px; font-size: 13px; color: #92400e; }
        .dark .gt-note { color: #fcd34d; }
        .gt-link { display: inline-block; margin-top: 10px; font-weight: 600; color: rgb(180 83 9); text-decoration: underline; cursor: pointer; background: none; border: 0; padding: 0; font-size: 14px; }
        .dark .gt-link { color: rgb(251 191 36); }
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
            const TOUR = @js($tour);
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
            const clean = (text) => (text || '').replace(/\s+/g, ' ').trim();
            const topbarBottom = () => document.querySelector('.fi-topbar')?.getBoundingClientRect().bottom || 0;

            const visible = (el) => {
                if (! el) return false;
                const r = el.getBoundingClientRect();
                return r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth;
            };

            // Chỗ được chỉ: trả về phần tử đầu tiên khớp, hoặc null.
            const resolve = (step) => {
                if (step.selector) return document.querySelector(step.selector);
                if (step.field) {
                    const label = [...document.querySelectorAll('.fi-fo-field-label-content, .fi-in-entry-label')]
                        .find((el) => clean(el.textContent).startsWith(step.field));
                    return label ? (label.closest('.fi-sc-component') || label) : null;
                }
                if (step.stat) {
                    const label = [...document.querySelectorAll('.fi-wi-stats-overview-stat-label')]
                        .find((el) => clean(el.textContent).startsWith(step.stat));
                    return label ? label.closest('.fi-wi-stats-overview-stat') : null;
                }
                if (step.heading) {
                    const widget = [...document.querySelectorAll('.fi-wi-widget')]
                        .find((el) => el.textContent.includes(step.heading));
                    if (widget) return widget;
                    const table = [...document.querySelectorAll('.fi-ta-header-heading')]
                        .find((el) => clean(el.textContent).startsWith(step.heading));
                    return table ? (table.closest('.fi-ta-ctn') || table) : null;
                }
                if (step.button) {
                    return [...document.querySelectorAll('.fi-header button, .fi-header a, main button.fi-btn')]
                        .find((el) => visible(el) && clean(el.textContent).startsWith(step.button)) || null;
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
            let target = null;
            let openedSidebar = false;
            let watcher = null;
            let rendering = 0;

            const reposition = () => {
                if (! els || els.pop.style.visibility === 'hidden') return;
                const pop = els.pop;
                const vw = window.innerWidth;
                const vh = window.innerHeight;
                const pw = pop.offsetWidth;
                const ph = pop.offsetHeight;

                if (! target || ! visible(target)) {
                    els.hole.style.display = 'none';
                    els.blocker.classList.add('gt-dim');
                    if (! isMobile()) {
                        pop.style.left = Math.max(16, (vw - pw) / 2) + 'px';
                        pop.style.top = Math.max(16, (vh - ph) / 2) + 'px';
                    }
                    return;
                }

                els.blocker.classList.remove('gt-dim');
                const pad = 8;
                const gap = 14;
                const r = target.getBoundingClientRect();
                // Chỗ nằm trong thanh trên cùng / menu bên thì không bị thanh trên cùng che.
                const minTop = target.closest('.fi-topbar, .fi-sidebar') ? 4 : topbarBottom() + 4;
                const hole = { top: Math.max(r.top - pad, minTop), left: r.left - pad, width: r.width + pad * 2, bottom: r.bottom + pad };
                const clampX = (x) => Math.min(Math.max(16, x), vw - pw - 16);
                let left;
                let top;

                if (isMobile()) {
                    // Khung thoại ghim đáy màn hình (CSS); khung sáng không được chui xuống dưới nó.
                    hole.bottom = Math.min(hole.bottom, vh - ph - 16 - 10);
                } else if (hole.bottom + gap + ph <= vh - 16) {
                    top = hole.bottom + gap; left = clampX(hole.left);
                } else if (hole.top - gap - ph >= minTop) {
                    top = hole.top - gap - ph; left = clampX(hole.left);
                } else if (hole.left + hole.width + gap + pw <= vw - 16) {
                    left = hole.left + hole.width + gap; top = Math.min(Math.max(minTop, hole.top), vh - ph - 16);
                } else if (hole.left - gap - pw >= 16) {
                    left = hole.left - gap - pw; top = Math.min(Math.max(minTop, hole.top), vh - ph - 16);
                } else {
                    // Khối cao quá màn hình: khung thoại xuống đáy, khung sáng chỉ phần phía trên.
                    top = vh - ph - 16; left = clampX(hole.left + hole.width - pw);
                    hole.bottom = Math.min(hole.bottom, top - gap);
                }

                Object.assign(els.hole.style, {
                    display: 'block',
                    top: hole.top + 'px',
                    left: hole.left + 'px',
                    width: hole.width + 'px',
                    height: Math.max(24, hole.bottom - hole.top) + 'px',
                });

                if (top !== undefined) {
                    pop.style.left = left + 'px';
                    pop.style.top = top + 'px';
                }
            };

            // Cuộn để chỗ được chỉ nằm ngay dưới thanh trên cùng, chừa chỗ cho khung thoại.
            const bringIntoView = async (el) => {
                if (el.closest('.fi-topbar')) return;
                if (el.closest('.fi-sidebar')) {
                    el.scrollIntoView({ block: 'center' });
                    await sleep(60);
                    return;
                }
                const r = el.getBoundingClientRect();
                const top = topbarBottom() + 16;
                const room = window.innerHeight - top - (isMobile() ? els.pop.offsetHeight + 40 : 0);
                const wanted = r.height < room && ! isMobile() ? top + (room - r.height) / 3 : top;
                if (Math.abs(r.top - wanted) > 4) {
                    // Cuộn tức thì: cuộn mượt trên máy yếu làm khung sáng đặt lệch chỗ.
                    window.scrollBy({ top: r.top - wanted, behavior: 'instant' });
                    await sleep(60);
                }
            };

            // Khối nạp chậm đẩy nội dung xuống mà không phát sự kiện cuộn: tự căn lại, bị đẩy khỏi màn hình thì kéo lại.
            let busy = false;
            const watch = async () => {
                if (! els || busy || ! target || els.pop.style.visibility === 'hidden') return;
                const r = target.getBoundingClientRect();
                const offscreen = ! target.closest('.fi-topbar, .fi-sidebar') && (r.top > window.innerHeight - 40 || r.bottom < topbarBottom());
                if (offscreen) {
                    busy = true;
                    await bringIntoView(target);
                    busy = false;
                }
                reposition();
            };

            const render = async () => {
                const token = ++rendering;
                const stale = () => token !== rendering || ! els;
                const step = steps[index];
                const last = index === steps.length - 1;

                if (openedSidebar && ! step.menu) {
                    sidebar()?.close();
                    openedSidebar = false;
                }

                target = resolve(step);
                if (step.menu && target && ! visible(target) && sidebar()) {
                    sidebar().open();
                    openedSidebar = true;
                    await sleep(320);
                    if (stale()) return;
                    target = resolve(step);
                }
                const missing = ! target || ! visible(target);

                els.pop.innerHTML = '<div class="gt-head">' + BOT + '<div><div class="gt-title"></div>'
                    + '<div class="gt-step">Cờ Lê dẫn đường · ' + (index + 1) + '/' + steps.length + '</div></div></div>'
                    + '<div class="gt-text"></div>'
                    + (missing && step.fallback ? '<div class="gt-note"></div>' : '')
                    + (step.link ? '<button type="button" class="gt-link" data-act="link"></button>' : '')
                    + '<div class="gt-actions"><button type="button" class="gt-skip" data-act="skip">Bỏ qua</button><span class="gt-gap"></span>'
                    + (index > 0 ? '<button type="button" class="gt-btn gt-prev" data-act="prev">Quay lại</button>' : '')
                    + '<button type="button" class="gt-btn gt-next" data-act="next">' + (last ? 'Xong, vào việc' : 'Tiếp tục') + '</button></div>';
                els.pop.querySelector('.gt-title').textContent = step.title;
                els.pop.querySelector('.gt-text').textContent = step.text;
                if (missing && step.fallback) els.pop.querySelector('.gt-note').textContent = step.fallback;
                if (step.link) els.pop.querySelector('.gt-link').textContent = step.link.label + ' →';
                if (last) els.pop.querySelector('.gt-skip').remove();

                if (! missing) {
                    els.pop.style.visibility = 'hidden';
                    els.hole.style.display = 'none';
                    busy = true;
                    await bringIntoView(target);
                    busy = false;
                    if (stale()) return;
                    els.pop.style.visibility = '';
                }
                reposition();
                els.pop.querySelector('.gt-next').focus({ preventScroll: true });
            };

            const markSeen = () => {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                return fetch(SEEN_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': token || '', 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ tour: TOUR }),
                }).catch(() => {});
            };

            const onKey = (event) => {
                if (event.key === 'Escape') finish();
                else if (event.key === 'ArrowRight') go(1);
                else if (event.key === 'ArrowLeft') go(-1);
            };

            const finish = () => {
                if (! els) return null;
                if (openedSidebar) sidebar()?.close();
                openedSidebar = false;
                Object.values(els).forEach((el) => el.remove());
                els = null;
                target = null;
                window.removeEventListener('resize', reposition);
                window.removeEventListener('scroll', reposition, true);
                document.removeEventListener('keydown', onKey);
                clearInterval(watcher);

                const url = new URL(window.location.href);
                if (url.searchParams.has('tour')) {
                    url.searchParams.delete('tour');
                    history.replaceState(null, '', url);
                }

                return markSeen();
            };

            const go = (delta) => {
                const next = index + delta;
                if (next < 0) return;
                if (next >= steps.length) return finish();
                index = next;
                render();
            };

            // Khối trên trang nạp chậm (lazy): chờ tối đa ~3 giây cho các chỗ cần chỉ.
            const waitForPage = async () => {
                for (let i = 0; i < 20; i++) {
                    if (STEPS.every((step) => ! step.optional || resolve(step))) return;
                    await sleep(150);
                }
            };

            const start = async () => {
                if (els) return;
                await waitForPage();
                steps = STEPS.filter((step) => ! step.optional || resolve(step));
                index = 0;

                const blocker = document.createElement('div');
                blocker.className = 'gt-blocker';
                const hole = document.createElement('div');
                hole.className = 'gt-hole';
                const pop = document.createElement('div');
                pop.className = 'gt-pop';
                pop.setAttribute('role', 'dialog');
                pop.setAttribute('aria-live', 'polite');
                pop.addEventListener('click', async (event) => {
                    const act = event.target.closest('[data-act]')?.dataset.act;
                    if (act === 'next') go(1);
                    if (act === 'prev') go(-1);
                    if (act === 'skip') finish();
                    if (act === 'link') {
                        const href = steps[index].link.url;
                        await finish();
                        window.location.href = href;
                    }
                });
                document.body.append(blocker, hole, pop);
                els = { blocker, hole, pop };

                window.addEventListener('resize', reposition);
                window.addEventListener('scroll', reposition, true);
                document.addEventListener('keydown', onKey);
                watcher = setInterval(watch, 300);
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
