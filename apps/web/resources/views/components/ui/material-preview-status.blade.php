<div class="ui-material-preview-status" x-show="started || previewWarnings.length" x-cloak role="status" aria-live="polite">
    <span x-text="status === 'loading' ? 'Loading material…' : previewMode"></span>
    <details x-show="previewWarnings.length > 0">
        <summary x-text="status === 'error' ? 'Preview unavailable' : 'Preview limitations'"></summary>
        <template x-for="(warning, index) in previewWarnings" x-bind:key="index"><p x-text="warning"></p></template>
    </details>
</div>
