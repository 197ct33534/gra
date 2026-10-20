<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            drawing: false,
            ctx: null,
            init() {
                const canvas = this.$refs.canvas;
                const ratio = window.devicePixelRatio || 1;
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                this.ctx = canvas.getContext('2d');
                this.ctx.scale(ratio, ratio);
                this.ctx.lineWidth = 2.5;
                this.ctx.lineCap = 'round';
                this.ctx.strokeStyle = '#111827';
                this.$watch('state', (value) => { if (! value) this.wipe() });
            },
            point(event) {
                const rect = this.$refs.canvas.getBoundingClientRect();
                return { x: event.clientX - rect.left, y: event.clientY - rect.top };
            },
            start(event) {
                this.drawing = true;
                const p = this.point(event);
                this.ctx.beginPath();
                this.ctx.moveTo(p.x, p.y);
            },
            move(event) {
                if (! this.drawing) return;
                const p = this.point(event);
                this.ctx.lineTo(p.x, p.y);
                this.ctx.stroke();
            },
            end() {
                if (! this.drawing) return;
                this.drawing = false;
                this.state = this.$refs.canvas.toDataURL('image/png');
            },
            wipe() {
                const canvas = this.$refs.canvas;
                this.ctx.clearRect(0, 0, canvas.width, canvas.height);
            },
            clear() {
                this.wipe();
                this.state = null;
            },
        }"
        wire:ignore
        style="display: grid; gap: 6px;"
    >
        <canvas
            x-ref="canvas"
            x-on:pointerdown.prevent="start($event)"
            x-on:pointermove.prevent="move($event)"
            x-on:pointerup="end()"
            x-on:pointerleave="end()"
            style="width: 100%; height: 160px; background: #fff; border: 2px dashed #9ca3af; border-radius: 10px; touch-action: none;"
            aria-label="Khung để khách ký nhận xe"
        ></canvas>
        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; opacity: .8;">
            <span>Khách ký bằng ngón tay xác nhận hiện trạng xe và đồ trên xe.</span>
            <x-filament::button size="xs" color="gray" x-on:click="clear()">Ký lại</x-filament::button>
        </div>
    </div>
</x-dynamic-component>
