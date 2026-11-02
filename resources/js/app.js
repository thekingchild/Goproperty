/*
 * The Building Group's rich-text box (P07-02; Modules/BuildingGroup
 * partials/rich-editor): a contenteditable area with a small toolbar —
 * bold, italic, bulleted and numbered lists, links — whose HTML is
 * entangled with a Livewire property. Pastes come in as plain text. The
 * server keeps only what Modules\BuildingGroup\Posts\RichText allows, so
 * nothing here is a security control.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('richEditor', (model) => ({
        html: model,

        init() {
            document.execCommand('defaultParagraphSeparator', false, 'p');
            this.$refs.area.innerHTML = this.html || '';
            this.$watch('html', (value) => {
                if ((value || '') !== this.$refs.area.innerHTML) {
                    this.$refs.area.innerHTML = value || '';
                }
            });
        },

        sync() {
            this.html = this.$refs.area.textContent.trim() === '' ? '' : this.$refs.area.innerHTML;
        },

        run(command, value = null) {
            this.$refs.area.focus();
            document.execCommand(command, false, value);
            this.sync();
        },

        link(prompt) {
            const address = (window.prompt(prompt) || '').trim();

            if (address === '') {
                return;
            }

            this.run('createLink', /^(https?:|mailto:)/i.test(address) ? address : `https://${address}`);
        },

        paste(event) {
            event.preventDefault();
            document.execCommand('insertText', false, (event.clipboardData || window.clipboardData).getData('text/plain'));
            this.sync();
        },
    }));
});
