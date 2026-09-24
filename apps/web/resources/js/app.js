import { consumerStatus } from './consumer-status';
import { materialViewer } from './material-viewer';

/**
 * A plain click opens the material quick view; a modifier or middle click is
 * left alone so the full record still opens in a new tab.
 */
function quickOpen(component, event, code, variantId = null) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) {
        return;
    }

    event.preventDefault();
    component.$wire?.openQuick(code, variantId);
}

document.addEventListener('alpine:init', () => {
    /**
     * A material swatch previews only the colourway being inspected.
     */
    window.Alpine.data('swatchCard', (config, qrOptions = []) => ({
        variants: config.variants ?? [],
        // The committed choice: what apply acts on, and what shows at rest.
        selected: config.active ?? 0,
        // A transient look at another colourway while the pointer is on its chip.
        hovered: null,
        hovering: false,
        qrOpen: false,
        qrOptions,
        qrSelection: 0,
        qrCopied: false,

        /** What the preview shows: the hovered colourway, else the chosen one. */
        get current() {
            return this.variants[this.hovered ?? this.selected] ?? null;
        },

        /** The chosen colourway, whatever the pointer is doing. */
        get chosen() {
            return this.variants[this.selected] ?? null;
        },

        get qrOption() {
            return this.qrOptions[this.qrSelection] ?? this.qrOptions[0] ?? null;
        },

        openQr() {
            this.qrSelection = 0;
            this.qrCopied = false;
            this.qrOpen = true;
        },

        closeQr() {
            this.qrOpen = false;
            this.qrCopied = false;
        },

        async copyQrLink() {
            if (! this.qrOption?.target || ! navigator.clipboard) {
                return;
            }

            await navigator.clipboard.writeText(this.qrOption.target);
            this.qrCopied = true;
            window.setTimeout(() => { this.qrCopied = false; }, 1800);
        },

        enter() {
            this.hovering = true;
        },

        leave() {
            this.hovering = false;
            this.hovered = null;
        },

        preview(index) {
            this.hovered = index;
        },

        clearPreview() {
            this.hovered = null;
        },

        pick(index) {
            this.selected = index;
            this.hovered = null;
        },

        quickOpen(event, code) {
            quickOpen(this, event, code, this.chosen?.id);
        },
    }));

    // Table rows want the quick view without the swatch behaviour.
    window.Alpine.data('quickLink', () => ({
        quickOpen(event, code) {
            quickOpen(this, event, code);
        },
    }));
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('materialViewer', materialViewer);
    window.Alpine.data('consumerStatus', consumerStatus);
});

import './echo';
