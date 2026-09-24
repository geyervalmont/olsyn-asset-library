import { describe, expect, test, mock } from 'bun:test';
import { materialViewer } from '../../resources/js/material-viewer';

function setup(loadStage) {
    const viewer = materialViewer({ sets: { 1: { key: 'first' }, 2: { key: 'second' } }, autoStart: false }, loadStage);
    viewer.$refs = { stage: { isConnected: true } };
    viewer.$watch = () => {};
    viewer.init();
    return viewer;
}

function stageStub() {
    return { attach: mock(async () => {}), detach: mock(() => {}), show: mock(async () => {}), setShape: mock(() => {}) };
}

describe('on-demand material preview', () => {
    test('browsing and choosing colourways do not load WebGL; starting uses the latest choice', async () => {
        const stage = stageStub();
        const load = mock(async () => ({ stage }));
        const viewer = setup(load);
        await viewer.show(1);
        await viewer.show(2);
        expect(load).not.toHaveBeenCalled();
        await viewer.start();
        expect(stage.show).toHaveBeenCalledWith({ key: 'second' }, 1000);
        expect(viewer.status).toBe('ready');
    });

    test('closing while the renderer downloads cannot attach a stale preview', async () => {
        const stage = stageStub();
        let resolve;
        const viewer = setup(() => new Promise((done) => { resolve = done; }));
        const pending = viewer.start();
        viewer.destroy();
        resolve({ stage });
        await pending;
        expect(stage.attach).not.toHaveBeenCalled();
        expect(stage.show).not.toHaveBeenCalled();
    });

    test('closing during setup prevents maps being loaded afterwards', async () => {
        const stage = stageStub();
        let resolve;
        stage.attach = mock(() => new Promise((done) => { resolve = done; }));
        const viewer = setup(async () => ({ stage }));
        const pending = viewer.start();
        await Promise.resolve();
        viewer.destroy();
        expect(stage.attach.mock.calls[0][1].isCurrent()).toBe(false);
        resolve();
        await pending;
        expect(stage.show).not.toHaveBeenCalled();
    });
});
