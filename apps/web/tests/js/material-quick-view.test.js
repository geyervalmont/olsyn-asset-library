import { describe, expect, test, mock } from 'bun:test';
import { materialQuickView } from '../../resources/js/material-quick-view';

function setup() {
    const pending = [];
    const modal = materialQuickView();
    modal.setLocation = mock(() => {});
    modal.$wire = {
        openQuick: mock(() => new Promise((resolve, reject) => pending.push({ resolve, reject }))),
        closeQuick: mock(async () => {}),
    };
    return { modal, pending };
}

describe('immediate material dialog', () => {
    test('opens with the card immediately, without waiting for the server', async () => {
        const { modal, pending } = setup();
        const request = modal.open({ code: 'WOOD', name: 'Oak', variantId: 42, image: '/preview' });
        expect(modal.visible).toBe(true);
        expect(modal.seed.name).toBe('Oak');
        expect(modal.ready).toBe(false);
        expect(modal.$wire.openQuick).toHaveBeenCalledWith('WOOD', 42, 1);
        pending[0].resolve(true);
        await request;
        expect(modal.ready).toBe(true);
    });

    test('a response after close cannot reopen the dialog', async () => {
        const { modal, pending } = setup();
        const request = modal.open({ code: 'WOOD' });
        modal.close();
        pending[0].resolve(true);
        await request;
        expect(modal.visible).toBe(false);
        expect(modal.ready).toBe(false);
    });

    test('an older response cannot replace a newly selected material', async () => {
        const { modal, pending } = setup();
        const first = modal.open({ code: 'WOOD' });
        const second = modal.open({ code: 'STONE' });
        pending[0].resolve(false);
        await first;
        expect(modal.seed.code).toBe('STONE');
        expect(modal.error).toBe('');
        expect(modal.ready).toBe(false);
        pending[1].resolve(true);
        await second;
        expect(modal.ready).toBe(true);
    });

    test('failed requests remain dismissible and can be retried', async () => {
        const { modal, pending } = setup();
        const first = modal.open({ code: 'WOOD' });
        pending[0].reject(new Error('offline'));
        await first;
        expect(modal.visible).toBe(true);
        expect(modal.error).toContain('Check your connection');
        const retry = modal.open(modal.seed);
        pending[1].resolve(true);
        await retry;
        expect(modal.ready).toBe(true);
        expect(modal.error).toBe('');
    });
});
