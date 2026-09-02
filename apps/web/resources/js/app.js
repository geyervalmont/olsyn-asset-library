document.addEventListener('alpine:init', () => {
    /**
     * A material swatch: lifts and tilts toward the pointer, and previews
     * whichever colourway the pointer rests on.
     */
    window.Alpine.data('swatchCard', (config) => ({
        variants: config.variants ?? [],
        active: config.active ?? 0,
        tilt: '',
        hovering: false,
        reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,

        get current() {
            return this.variants[this.active] ?? null;
        },

        move(event) {
            if (this.reduced) {
                return;
            }

            const rect = this.$el.getBoundingClientRect();
            const x = (event.clientX - rect.left) / rect.width - 0.5;
            const y = (event.clientY - rect.top) / rect.height - 0.5;

            this.tilt = `perspective(900px) rotateX(${(-y * 7).toFixed(2)}deg) rotateY(${(x * 9).toFixed(2)}deg) translateY(-4px) scale(1.035)`;
        },

        enter() {
            this.hovering = true;
        },

        leave() {
            this.hovering = false;
            this.tilt = '';
            this.active = config.active ?? 0;
        },

        pick(index) {
            this.active = index;
        },
    }));
});
