/** Account-scoped presence, refreshed by events and an HTTP fallback. */
export function consumerStatus(config) {
    const timestamp = (session) => session.secondsAgo !== undefined
        ? Date.now() - session.secondsAgo * 1000
        : Date.parse(session.last_seen_at);

    return {
        sessions: (config.sessions ?? []).map((session) => ({ ...session, seenAt: timestamp(session) })),
        liveSeconds: config.liveSeconds ?? 90,
        now: Date.now(),
        timer: null,
        channel: null,
        listener: null,
        request: null,
        destroyed: false,

        init() {
            this.listener = (event) => this.merge(event);
            this.channel = window.Echo?.private(`user.${config.userId}`);
            this.channel?.listen('.session.updated', this.listener);
            this.timer = setInterval(() => { this.now = Date.now(); this.refresh(); }, 15000);
            this.refresh();
        },

        destroy() {
            this.destroyed = true;
            clearInterval(this.timer);
            this.request?.abort();
            this.channel?.stopListening('.session.updated', this.listener);
        },

        async refresh() {
            if (this.request || this.destroyed) return;
            const request = new AbortController();
            this.request = request;
            try {
                const response = await fetch(config.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: request.signal });
                if (!response.ok) return;
                const result = await response.json();
                if (!this.destroyed) this.sessions = result.data.map((session) => ({ ...session, seenAt: timestamp(session) }));
            } catch { /* Existing heartbeats still expire while the connection is unavailable. */ }
            finally { this.request = null; this.now = Date.now(); }
        },

        merge(event) {
            const rest = this.sessions.filter((session) => session.id !== event.id);
            this.sessions = event.live === false ? rest : [...rest, { ...event, seenAt: timestamp(event) }];
            this.now = Date.now();
        },

        get live() {
            return this.sessions.filter((session) => session.live !== false && this.now - session.seenAt <= this.liveSeconds * 1000);
        },

        get state() { return this.live.length ? 'live' : 'off'; },

        get label() {
            const counts = new Map();
            for (const session of this.live) {
                const name = session.platform_label || session.platform;
                counts.set(name, (counts.get(name) || 0) + 1);
            }
            return [...counts].sort(([a], [b]) => a.localeCompare(b))
                .map(([name, count]) => count > 1 ? `${name} (${count})` : name).join(' · ') || 'Nothing connected';
        },

        get title() {
            return this.live.map((session) => session.label || [session.platform_label || session.platform, session.machine, session.document].filter(Boolean).join(' · ')).join('\n')
                || 'Connect an OPAL extension to browse and apply materials in your application.';
        },
    };
}
