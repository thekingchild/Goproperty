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

/*
 * Live updates of a Building Group's page (P07-03; Modules/BuildingGroup
 * partials/feed, Live\LiveFeed): asks the page to refresh its feed
 * (`refreshFeed`, which re-renders only when the group changed) every
 * `poll` seconds, and on a VPS also as soon as Reverb nudges the group's
 * private channel. Polling pauses while the tab is hidden and after `idle`
 * seconds without the person touching the page, so a forgotten tab
 * neither loads the host nor keeps the session alive; both catch up at
 * once when the person comes back. If Reverb cannot be reached the page
 * simply keeps polling.
 */
const ACTIVITY = ['pointerdown', 'keydown', 'scroll', 'touchstart', 'focusin'];

document.addEventListener('alpine:init', () => {
    window.Alpine.data('groupLive', (live) => ({
        timer: null,
        busy: false,
        again: false,
        missed: false,
        paused: false,
        lastActive: Date.now(),
        channel: null,
        gone: false,

        init() {
            this.onActivity = () => this.active();
            this.onVisibility = () => {
                if (!document.hidden) {
                    this.active(true);
                }
            };
            ACTIVITY.forEach((name) => document.addEventListener(name, this.onActivity, { passive: true }));
            document.addEventListener('visibilitychange', this.onVisibility);
            this.start();

            if (live.websocket) {
                import('./realtime.js')
                    .then(({ connect }) => {
                        if (this.gone) {
                            return;
                        }

                        const echo = connect(live.websocket);
                        this.channel = live.websocket.channel;
                        echo.private(this.channel).listen('.feed.changed', () => this.nudged());
                        // Back from a dropped connection: what was said meanwhile.
                        echo.connector.pusher.connection.bind('connected', () => this.nudged());
                    })
                    .catch(() => {});
            }
        },

        destroy() {
            this.gone = true;
            clearInterval(this.timer);
            ACTIVITY.forEach((name) => document.removeEventListener(name, this.onActivity));
            document.removeEventListener('visibilitychange', this.onVisibility);

            if (this.channel && window.Echo) {
                window.Echo.leave(this.channel);
            }
        },

        start() {
            clearInterval(this.timer);
            this.timer = setInterval(() => this.tick(), live.poll * 1000);
        },

        tick() {
            if (document.hidden) {
                this.missed = true;

                return;
            }

            if (Date.now() - this.lastActive > live.idle * 1000) {
                this.paused = true;
                clearInterval(this.timer);

                return;
            }

            this.refresh();
        },

        active(shown = false) {
            this.lastActive = Date.now();

            if (this.paused || (shown && this.missed)) {
                this.paused = false;
                this.missed = false;
                this.start();
                this.refresh();
            }
        },

        nudged() {
            if (document.hidden || this.paused) {
                this.missed = true;

                return;
            }

            this.refresh();
        },

        refresh() {
            if (this.gone) {
                return;
            }

            if (this.busy) {
                // A change may have landed after the running refresh read the feed.
                this.again = true;

                return;
            }

            this.busy = true;
            this.again = false;
            Promise.resolve(this.$wire.refreshFeed()).finally(() => {
                this.busy = false;

                if (this.again) {
                    this.refresh();
                }
            });
        },
    }));
});
