import { describe, expect, test } from 'bun:test';
import { consumerStatus } from '../../resources/js/consumer-status.js';

const session = (id, platform, secondsAgo = 0) => ({ id, platform, platform_label: platform, machine: 'WORKSTATION', secondsAgo });

describe('consumer presence', () => {
    test('empty and expired sessions say nothing connected', () => {
        expect(consumerStatus({ sessions: [] }).label).toBe('Nothing connected');
        expect(consumerStatus({ sessions: [session(1, 'Revit', 100)] }).state).toBe('off');
    });
    test('consumer names and counts reflect all running apps', () => {
        const state = consumerStatus({ sessions: [session(1, 'Revit'), session(2, 'Omniverse'), session(3, 'Revit')] });
        expect(state.label).toBe('Omniverse · Revit (2)');
        state.merge({ id: 2, live: false });
        expect(state.label).toBe('Revit (2)');
    });
    test('delayed heartbeat events cannot make stale sessions live', () => {
        const state = consumerStatus({ sessions: [] });
        state.merge({ id: 1, platform: 'Omniverse', last_seen_at: new Date(Date.now() - 120000).toISOString() });
        expect(state.label).toBe('Nothing connected');
    });
    test('HTTP refresh finds new consumers without websocket events', async () => {
        const original = globalThis.fetch;
        globalThis.fetch = async () => ({ ok: true, json: async () => ({ data: [session(1, 'Future Editor')] }) });
        try {
            const state = consumerStatus({ sessions: [], url: '/connect/sessions' });
            await state.refresh();
            expect(state.label).toBe('Future Editor');
        } finally { globalThis.fetch = original; }
    });
});
