@props(['scans', 'selectable' => false, 'height' => '72vh'])
{{--
  Перегляд скану (ТЗ 4.3, 8.1): масштаб, поворот, переміщення, перехід між сторінками,
  підсвічування фрагмента поля та виділення області. Оригінал JPG не змінюється — усе лише CSS.
  Батьківський Livewire-компонент має властивості viewerIdx, highlight і (для виділення) pendingRegion.
--}}
<div class="bk-viewer" style="--bk-h: {{ $height }}"
     x-data="{
        scans: @js($scans),
        idx: $wire.entangle('viewerIdx'),
        highlight: $wire.entangle('highlight'),
        zoom: 1, rot: 0, x: 0, y: 0, drag: null, selecting: false, sel: null, selStart: null,
        get scan() { return this.scans[this.idx] ?? null },
        reset() { this.zoom = 1; this.rot = 0; this.x = 0; this.y = 0 },
        go(d) { const n = this.idx + d; if (n >= 0 && n < this.scans.length) { this.idx = n; this.reset() } },
        wheel(e) { const f = e.deltaY < 0 ? 1.12 : 1 / 1.12; this.zoom = Math.min(8, Math.max(0.2, this.zoom * f)) },
        frac(e) {
            const r = this.$refs.img.getBoundingClientRect();
            return { x: Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), y: Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)) };
        },
        down(e) {
            if (this.selecting) { if (this.rot % 360 !== 0) return; this.selStart = this.frac(e); this.sel = { ...this.selStart, w: 0, h: 0 }; return }
            this.drag = { sx: e.clientX - this.x, sy: e.clientY - this.y };
        },
        move(e) {
            if (this.selecting && this.selStart) {
                const p = this.frac(e);
                this.sel = { x: Math.min(p.x, this.selStart.x), y: Math.min(p.y, this.selStart.y), w: Math.abs(p.x - this.selStart.x), h: Math.abs(p.y - this.selStart.y) };
                return;
            }
            if (this.drag) { this.x = e.clientX - this.drag.sx; this.y = e.clientY - this.drag.sy }
        },
        up() {
            if (this.selecting && this.selStart) {
                this.selStart = null;
                if (this.sel && this.sel.w > 0.005 && this.sel.h > 0.005) {
                    $wire.set('pendingRegion', { scan_id: this.scan.id, x: +this.sel.x.toFixed(4), y: +this.sel.y.toFixed(4), w: +this.sel.w.toFixed(4), h: +this.sel.h.toFixed(4) });
                }
                this.selecting = false;
            }
            this.drag = null;
        },
        box(b) { return b ? `left:${b.x * 100}%;top:${b.y * 100}%;width:${b.w * 100}%;height:${b.h * 100}%` : 'display:none' },
        hlOnThis() { return this.highlight && this.scan && (!this.highlight.scan_id || this.highlight.scan_id === this.scan.id) },
     }"
     x-init="$watch('highlight', h => { if (h && h.scan_id) { const i = scans.findIndex(s => s.id === h.scan_id); if (i >= 0 && i !== idx) { idx = i; reset() } } })"
     @keydown.window="if (['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)) return;
        if ($event.key === 'ArrowRight' && $event.altKey) go(1); if ($event.key === 'ArrowLeft' && $event.altKey) go(-1)"
     @mouseup.window="up()"
     @if ($selectable) @bk-pencil.window="selecting = true; sel = null" @endif>

    <div class="bk-viewer__bar">
        <div class="bk-viewer__group">
            <button type="button" class="bk-btn" @click="go(-1)" :disabled="idx <= 0" title="Попередня сторінка (Alt+←)">‹</button>
            <span class="bk-viewer__page"><span x-text="scans.length ? idx + 1 : 0"></span> / <span x-text="scans.length"></span></span>
            <button type="button" class="bk-btn" @click="go(1)" :disabled="idx >= scans.length - 1" title="Наступна сторінка (Alt+→)">›</button>
        </div>
        <div class="bk-viewer__label" x-text="scan ? (scan.label || scan.filename) : 'Немає сканів'"></div>
        <div class="bk-viewer__group">
            <button type="button" class="bk-btn" @click="zoom = Math.max(0.2, zoom / 1.25)" title="Зменшити">−</button>
            <span class="bk-viewer__zoom" x-text="Math.round(zoom * 100) + '%'"></span>
            <button type="button" class="bk-btn" @click="zoom = Math.min(8, zoom * 1.25)" title="Збільшити">+</button>
            <button type="button" class="bk-btn" @click="rot = (rot + 90) % 360" title="Повернути на 90°">⟳</button>
            <button type="button" class="bk-btn" @click="reset()" title="Скинути вигляд">⤢</button>
            @if ($selectable)
                <button type="button" class="bk-btn" :class="selecting && 'bk-btn--on'" @click="selecting = !selecting; sel = null"
                        title="Олівець: обвести на скані місце з помилкою">✎ Олівець</button>
            @endif
            <a class="bk-btn" :href="scan && scan.url" target="_blank" rel="noopener" title="Відкрити оригінал">↗</a>
        </div>
    </div>

    <div class="bk-viewer__stage" :class="selecting ? 'is-selecting' : (drag ? 'is-dragging' : '')" @wheel.prevent="wheel($event)" @mousedown.prevent="down($event)" @mousemove="move($event)">
        <template x-if="scan">
            <div class="bk-viewer__canvas" :style="`transform: translate(${x}px, ${y}px) scale(${zoom}) rotate(${rot}deg)`">
                <img x-ref="img" :src="scan.url" :alt="scan.filename" draggable="false">
                <div class="bk-viewer__hl" x-show="hlOnThis()" :style="hlOnThis() ? box(highlight) : ''"></div>
                <template x-for="(r, i) in (scan.regions || [])" :key="i">
                    <div class="bk-viewer__mark" :style="box(r)" :title="r.title"><span x-show="r.n" x-text="r.n"></span></div>
                </template>
                <div class="bk-viewer__sel" x-show="sel" :style="box(sel)"></div>
            </div>
        </template>
        <div class="bk-viewer__hint" x-show="selecting">✎ Обведіть мишею на скані місце з помилкою<span x-show="rot % 360 !== 0"> (спершу поверніть сторінку в 0°)</span></div>
    </div>

    <div class="bk-viewer__thumbs">
        <template x-for="(s, i) in scans" :key="s.id">
            <button type="button" class="bk-thumb" :class="i === idx && 'is-active'" @click="idx = i; reset()" :title="s.label || s.filename">
                <img :src="s.thumb" loading="lazy" alt="">
                <span x-text="s.page_order"></span>
            </button>
        </template>
    </div>
</div>
