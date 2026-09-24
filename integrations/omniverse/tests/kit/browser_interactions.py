"""Run with Kit --exec in a disposable host (see README).

Exercises real mouse/keyboard input and asynchronous browser responses. Uses
local coloured thumbnails and no account, network, or changes to a USD stage.
"""
import asyncio
import gc
import json
import os
from pathlib import Path
import struct
import tempfile
import time
import traceback
import zlib

import carb.input
import omni.appwindow
import omni.kit.app
import omni.kit.renderer_capture
import omni.ui as ui
from olsyn.opal.extension import Extension

OUTPUT = Path(os.environ.get('OPAL_UI_TEST_OUTPUT') or tempfile.mkdtemp(prefix='opal-ui-test-'))
OUTPUT.mkdir(parents=True, exist_ok=True)


class LibraryFixture:
    token = 'local-interaction-fixture'
    server = 'https://opal.olsyn.com'

    def browse(self, query='', page=1, category=''):
        if query == 'broken':
            raise RuntimeError('Simulated connection failure')
        if query == 'slow':
            time.sleep(1.2)
        names = ['Timber', 'Brick', 'Concrete', 'Fabric']
        items = [dict(
            uuid=f'0195bd23-9123-7000-8000-{i:012d}', material_name=names[i % 4],
            name=f'Finish {i}', supplier='Test supplier', code=f'TEST{i}',
            description='Local interaction fixture', category=names[i % 4],
            material_version=1, preview_url=str(i),
        ) for i in range(60)]
        items = [item for item in items
                 if (not query or query.lower() in item['material_name'].lower())
                 and (not category or item['category'] == category)]
        return {
            'data': items[(page - 1) * 24:page * 24],
            'meta': dict(current_page=page, last_page=max(1, (len(items) + 23) // 24), total=len(items), per_page=24),
        }

    def download(self, url, destination, *args):
        colour = [(120, 80, 40), (185, 62, 42), (130, 135, 140), (60, 100, 180)][int(url) % 4]

        def chunk(kind, data):
            return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)

        png = (b'\x89PNG\r\n\x1a\n'
               + chunk(b'IHDR', struct.pack('>IIBBBBB', 64, 64, 8, 2, 0, 0, 0))
               + chunk(b'IDAT', zlib.compress((b'\0' + bytes(colour) * 64) * 64))
               + chunk(b'IEND', b''))
        Path(destination).parent.mkdir(parents=True, exist_ok=True)
        Path(destination).write_bytes(png)


async def frames(count=5):
    for _ in range(count):
        await omni.kit.app.get_app().next_update_async()


async def settled(extension):
    for _ in range(300):
        await frames(1)
        if not extension.loading:
            await frames()
            return
    raise AssertionError('Browser never finished loading')


async def click(widget, y_fraction=0.5):
    x = widget.screen_position_x + widget.computed_width / 2
    y = widget.screen_position_y + widget.computed_height * y_fraction
    provider = carb.input.acquire_input_provider()
    mouse = omni.appwindow.get_default_app_window().get_mouse()
    for event in (carb.input.MouseEventType.MOVE, carb.input.MouseEventType.LEFT_BUTTON_DOWN,
                  carb.input.MouseEventType.LEFT_BUTTON_UP):
        provider.buffer_mouse_event(mouse, event, (x / ui.Workspace.get_main_window_width(),
                                                  y / ui.Workspace.get_main_window_height()), 0, (x, y))
        await frames(3)


async def check():
    result = {'passed': False}
    try:
        await asyncio.sleep(3)
        e = next(obj for obj in gc.get_objects() if type(obj) is Extension and getattr(obj, 'window', None))
        # This must run in a disposable test host, never an already signed-in host.
        assert not e.client.token and not e.session_id, 'Use a signed-out, disposable Kit host'
        e.client = LibraryFixture()
        e.root = OUTPUT / 'cache'
        e.account_status.text = 'Local interaction test'
        e.window.width, e.window.height = 1050, 750
        e.window.position_x, e.window.position_y = 10, 30
        await e.browse()
        await asyncio.sleep(0.5)
        assert e.choice is None, 'First material was selected without user input'
        await click(list(e.cards.values())[1][0])
        assert e.choice['name'] == 'Finish 1', 'Preview click failed'
        await click(list(e.cards.values())[2][0], y_fraction=0.9)
        assert e.choice['name'] == 'Finish 2', 'Label click failed'

        await click(e.query)
        provider = carb.input.acquire_input_provider()
        keyboard = omni.appwindow.get_default_app_window().get_keyboard()
        for char in 'brick':
            provider.buffer_keyboard_char_event(keyboard, char, 0)
            await frames(2)
        # Searching must work without Enter or clicking another widget.
        await settled(e)
        assert len(e.items) == 15 and all(item['material_name'] == 'Brick' for item in e.items)
        await click(list(e.cards.values())[1][0])
        assert e.choice['name'] == 'Finish 5', 'Search losing focus reset selection'
        generation = e.generation
        await click(e.search_button)
        await settled(e)
        assert e.generation == generation and e.choice['name'] == 'Finish 5'
        await click(e.clear_button)
        await settled(e)
        assert e.query.model.as_string == '' and len(e.items) == 24 and e.page == 1

        e.query.focus_keyboard()
        await frames()
        await click(e.next_button)
        await settled(e)
        assert e.page == 2 and e.items[0]['name'] == 'Finish 24'
        e.query.focus_keyboard()
        await frames()
        await click(list(e.cards.values())[1][0])
        assert e.page == 2 and e.choice['name'] == 'Finish 25'
        await click(e.refresh_button)
        await settled(e)
        assert e.page == 2 and e.choice['name'] == 'Finish 25', 'Refresh lost page or selection'

        e.query.model.set_value('slow')
        await asyncio.sleep(0.45)  # Let the delayed server response start.
        e.query.model.set_value('concrete')
        await settled(e)
        await asyncio.sleep(1.3)  # The superseded response must not replace this grid.
        assert len(e.items) == 15 and all(item['material_name'] == 'Concrete' for item in e.items)
        stale_item = e.items[0]
        e.query.model.set_value('missing')
        await settled(e)
        e.select(stale_item)
        assert not e.cards and e.choice is None and not e.apply_button.enabled
        e.query.model.set_value('broken')
        await settled(e)
        assert not e.cards and e.page_label.text == 'Search failed' and not e.apply_button.enabled
        await click(e.clear_button)
        await settled(e)
        assert len(e.items) == 24, 'Could not recover after failed search'

        e.categories = [{'code': '', 'name': 'All categories'}, {'code': 'Fabric', 'name': 'Fabric'}]
        e.category_frame.clear()
        with e.category_frame:
            e.category = ui.ComboBox(0, 'All categories', 'Fabric')
        e.category.model.add_item_changed_fn(lambda *_: e.search())
        e.category.model.get_item_value_model().set_value(1)
        await settled(e)
        assert len(e.items) == 15 and all(item['material_name'] == 'Fabric' for item in e.items)
        ui.Workspace.show_window('OPAL Materials', False)
        await frames()
        assert not e.window.visible
        ui.Workspace.show_window('OPAL Materials', True)
        await frames()
        assert e.window.visible
        await click(e.clear_button)
        await settled(e)
        await click(list(e.cards.values())[2][0])
        capture = omni.kit.renderer_capture.acquire_renderer_capture_interface()
        capture.capture_next_frame_swapchain(str(OUTPUT / 'browser.png'))
        await frames()
        capture.wait_async_capture()
        result['passed'] = True
    except Exception:
        result['error'] = traceback.format_exc()
    finally:
        (OUTPUT / 'result.json').write_text(json.dumps(result, indent=2))
        print(f'OPAL UI regression: {result}; artifacts: {OUTPUT}')
        omni.kit.app.get_app().post_quit(0 if result['passed'] else 1)


asyncio.ensure_future(check())
