export function materialQuickView(initial = null) {
    return {
        seed: initial,
        visible: Boolean(initial),
        ready: Boolean(initial),
        request: 0,
        error: '',

        init() {
            const seed = JSON.parse(this.$el.dataset.initial || 'null');
            if (seed) {
                this.seed = seed;
                this.visible = true;
                this.ready = true;
            }
        },

        async open(seed) {
            this.seed = seed;
            this.visible = true;
            this.ready = false;
            this.error = '';
            const request = ++this.request;
            this.setLocation(seed.code);
            try {
                const available = await this.$wire.openQuick(seed.code, seed.variantId ?? null, request);
                if (!this.visible || request !== this.request) return;
                this.ready = available;
                if (!available) this.error = 'This material is no longer available to you.';
            } catch {
                if (this.visible && request === this.request) {
                    this.error = 'Could not load this material. Check your connection and try again.';
                }
            }
        },

        close() {
            this.visible = false;
            this.ready = false;
            this.request++;
            this.setLocation(null);
            // Dismissal and renderer cleanup happen locally, before this request.
            this.$wire.closeQuick().catch(() => {});
        },

        setLocation(code) {
            const url = new URL(window.location.href);
            if (code) url.searchParams.set('material', code);
            else url.searchParams.delete('material');
            window.history.replaceState(window.history.state, '', url);
        },

        syncLocation() {
            const code = new URL(window.location.href).searchParams.get('material');
            if (!code && this.visible) this.close();
            else if (code && (!this.visible || code !== this.seed?.code)) this.open({ code });
        },
    };
}
