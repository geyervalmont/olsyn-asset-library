import asyncio
import json
import os
from pathlib import Path
import socket
import urllib.parse
import webbrowser

from omni.kit.menu.utils import MenuHelperExtension
import omni.ext
import omni.ui as ui
import omni.usd
from pxr import UsdShade

from .client import APP_VERSION, ApiError, Client, discover_drive
from .commands import CommandConsumer
from .browser import preview_name
from . import ui as browser_ui
from . import credentials, materials

class Extension(omni.ext.IExt, MenuHelperExtension):
    def on_startup(self, ext_id):
        self.root = Path(os.environ.get('LOCALAPPDATA', Path.home() / '.cache')) / 'OPAL' / 'omniverse'
        self.root.mkdir(parents=True, exist_ok=True)
        self.client = Client()
        try:
            self.client.token = credentials.load(self.root / 'account.bin')
        except OSError:
            pass
        self.tasks = set()
        self.generation = 0
        self.page = 1
        self.last_page = 1
        self.choice = None
        self.categories = [{'code': '', 'name': 'All categories'}]
        self.busy = False
        self.session_id = None
        self.account_email = ''
        self.connecting = False
        self.cards = {}
        self.preview_slots = asyncio.Semaphore(4)
        self.items = []
        self.loading = False
        self._browse_task = None
        self._requested_key = None
        self._filters_muted = False
        browser_ui.build(self)
        self.menu_startup('OPAL Materials', 'OPAL Materials', 'Window')
        self.window.set_visibility_changed_fn(lambda _: self.menu_refresh())
        if os.name == 'nt':
            self.mount.model.set_value('M:\\')
        if self.client.token:
            self.run(self.start_connected())

    async def start_connected(self):
        self.account_status.text = 'Connected · HTTPS'
        self.connect_button.visible = False
        self.disconnect_button.visible = True
        self.run(self.heartbeat())
        await self.load_facets()
        self.search()

    def run(self, coroutine):
        task = asyncio.ensure_future(self.guard(coroutine))
        self.tasks.add(task)
        task.add_done_callback(self.tasks.discard)
        return task

    async def guard(self, coroutine):
        try:
            await coroutine
        except asyncio.CancelledError:
            pass
        except Exception as exc:
            if getattr(self, 'window', None):
                self.status.text = str(exc)

    def on_shutdown(self):
        for task in self.tasks:
            task.cancel()
        self.tasks.clear()
        self.menu_shutdown()
        ui.Workspace.set_show_window_fn('OPAL Materials', None)
        self.window.destroy()
        self.window = None

    async def connect(self):
        if self.connecting:
            return
        if self.client.token:
            if self.session_id is None:
                await self.start_connected()
            return
        self.connecting = True
        self.connect_button.enabled = False
        try:
            client = Client()
            link = await asyncio.to_thread(client.request, '/api/v1/link', {'client': 'omniverse', 'machine': socket.gethostname(), 'app_version': APP_VERSION})
            self.status.text = 'Approve code ' + link['code'] + ' in your browser.'
            webbrowser.open(client.server + '/link?code=' + urllib.parse.quote(link['code']))
            for _ in range(300):
                await asyncio.sleep(max(2, int(link.get('poll_interval', 2))))
                result = await asyncio.to_thread(client.request, '/api/v1/link/' + link['code'] + '?' + urllib.parse.urlencode({'secret': link['secret']}))
                if result['status'] == 'claimed':
                    client.token = result['token']
                    self.client = client
                    credentials.save(self.root / 'account.bin', client.token)
                    await self.start_connected()
                    return
                if result['status'] == 'delivered':
                    raise RuntimeError('This connection was already consumed. Connect again.')
            self.status.text = 'The connection code expired. Connect again.'
        finally:
            self.connecting = False
            if getattr(self, 'window', None):
                self.connect_button.enabled = True

    async def disconnect(self):
        client, session_id = self.client, self.session_id
        if client.token:
            if session_id is not None:
                await asyncio.to_thread(client.request, f'/api/v1/sessions/{session_id}', None, 4096, 'DELETE')
            await asyncio.to_thread(client.request, '/api/v1/account/token', None, 4096, 'DELETE')
        for task in list(self.tasks):
            if task is not asyncio.current_task():
                task.cancel()
        self.generation += 1
        self.client = Client()
        self.session_id = None
        self.account_email = ''
        credentials.save(self.root / 'account.bin', '')
        self.choice = None
        self.items = []
        self._requested_key = None
        self.loading = False
        self.list_frame.enabled = True
        self.pager.enabled = True
        self.cards.clear()
        self.list_frame.clear()
        self.preview_frame.clear()
        self.pager.clear()
        self.details.text = 'Connect your account to explore materials.'
        self.page_label.text = ''
        self.account_status.text = 'Not connected'
        self.connect_button.visible = True
        self.disconnect_button.visible = False
        self.apply_button.enabled = False
        self.status.text = 'Disconnected. This device’s token has been revoked.'

    async def heartbeat(self):
        if self.session_id is not None:
            return
        client = self.client
        result = await asyncio.to_thread(client.request, '/api/v1/sessions', {
            'platform': 'omniverse', 'machine': socket.gethostname(), 'app_version': APP_VERSION,
            'capabilities': ['material.apply', 'draft.apply'],
        })
        self.session_id = result['data']['id']
        session_id = self.session_id
        self.run(self.listen_commands(client, session_id))
        account = await asyncio.to_thread(client.request, '/api/v1/me')
        self.account_email = account['email']
        self.account_status.text = self.account_email
        while self.client is client and client.token and self.session_id == session_id:
            try:
                stage = omni.usd.get_context().get_stage()
                document = stage.GetRootLayer().GetDisplayName() if stage else None
                discovered = await asyncio.to_thread(discover_drive, client.server, self.account_email)
                if discovered:
                    self.mount.model.set_value(discovered)
                mount = self.mount.model.as_string.strip()
                await asyncio.to_thread(client.request, f'/api/v1/sessions/{session_id}/heartbeat', {
                    'document': document, 'mount_path': mount,
                    'drive_status': 'available' if mount and Path(mount).is_dir() else 'missing',
                    'capabilities': ['material.apply', 'draft.apply'],
                })
                self.account_status.text = self.account_email
            except ApiError as exc:
                if exc.status in (401, 403, 404, 409):
                    self.session_id = None
                    if exc.status in (401, 403):
                        client.token = ''
                        credentials.save(self.root / 'account.bin', '')
                    self.connect_button.visible = True
                    self.account_status.text = 'Not connected'
                    self.status.text = 'This connection ended. Connect again to receive materials.'
                    return
                self.account_status.text = 'Reconnecting...'
            except Exception:
                self.account_status.text = 'Reconnecting...'
            await asyncio.sleep(30)

    async def listen_commands(self, client, session_id):
        consumer = CommandConsumer(client, session_id, self.apply_payload)
        while self.client is client and self.session_id == session_id:
            if not self.busy:
                try:
                    await consumer.poll_once()
                except Exception:
                    self.account_status.text = 'Connection interrupted · retrying'
            await asyncio.sleep(3)

    async def load_facets(self):
        data = await asyncio.to_thread(self.client.request, '/api/v1/library/facets')
        self.categories = [{'code': '', 'name': 'All categories'}] + data['data']['categories']
        self.category_frame.clear()
        with self.category_frame:
            self.category = ui.ComboBox(0, *[item['name'] for item in self.categories])
        self.category.model.add_item_changed_fn(lambda *_: self.search())

    def clear_search(self):
        self._filters_muted = True
        try:
            self.query.model.set_value('')
            self.category.model.get_item_value_model().set_value(0)
        finally:
            self._filters_muted = False
        self.search(force=True)

    def search(self, delay=0, force=False):
        if self._filters_muted:
            return
        self.go_page(1, delay=delay, force=force)

    def refresh(self):
        self.go_page(self._requested_key[1] if self._requested_key else 1, force=True)

    def turn_page(self, step):
        self.go_page(self.page + step)

    def go_page(self, page, delay=0, force=False):
        if not self.client.token:
            self.status.text = 'Connect your OPAL account to search the library.'
            return
        if not 1 <= page <= self.last_page:
            return
        index = self.category.model.get_item_value_model().as_int
        key = (self.query.model.as_string.strip(), page, self.categories[index]['code'])
        if key == self._requested_key and not force:
            return
        self._requested_key = key
        # Invalidate old requests immediately, including during the typing delay.
        self.generation += 1
        generation = self.generation
        if self._browse_task:
            self._browse_task.cancel()
        self.loading = True
        self.list_frame.enabled = False
        self.pager.enabled = False
        self.apply_button.enabled = False
        self.page_label.text = 'Searching...'
        self.status.text = 'Searching your library...'
        self._browse_task = self.run(self.browse(generation, key, delay))

    async def browse(self, generation=None, key=None, delay=0):
        if generation is None:
            self.search(force=True)
            if self._browse_task:
                await self._browse_task
            return
        try:
            if delay:
                await asyncio.sleep(delay)
            response = await asyncio.to_thread(self.client.browse, *key)
            if generation != self.generation or not getattr(self, 'window', None):
                return
            meta = response['meta']
            self.page = meta['current_page']
            self.last_page = meta['last_page']
            total = meta['total']
            first = (self.page - 1) * meta.get('per_page', 24) + 1 if total else 0
            last = first + len(response['data']) - 1 if total else 0
            filters = ' · '.join(value for value in (f'"{key[0]}"' if key[0] else '', self.categories[self.category.model.get_item_value_model().as_int]['name']) if value)
            self.page_label.text = f'{first:,}-{last:,} of {total:,} · {filters} · Page {self.page}/{self.last_page}'
            selected_uuid = self.choice['uuid'] if self.choice else None
            self.items = response['data']
            self.clear_selection()
            self.list_frame.scroll_y = 0
            browser_ui.render_cards(self, self.items, generation)
            browser_ui.render_pager(self)
            self.loading = False
            for item in self.items:
                if item['uuid'] == selected_uuid:
                    self.select(item)
                    break
            self.status.text = 'Select a material preview to inspect it, then apply to selected geometry.' if total else 'No matching materials. Reset filters to browse the whole library.'
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            if generation == self.generation and getattr(self, 'window', None):
                self.items = []
                self.clear_selection()
                browser_ui.render_cards(self, [], generation, 'Could not load materials. Check your connection and retry.')
                self.pager.clear()
                self.page_label.text = 'Search failed'
                self.status.text = str(exc)
                self._requested_key = None
        finally:
            if generation == self.generation and getattr(self, 'window', None):
                self.loading = False
                self.list_frame.enabled = True
                self.pager.enabled = True
                self.apply_button.enabled = self.choice is not None and not self.busy

    def clear_selection(self):
        self.choice = None
        self.apply_button.enabled = False
        self.preview_frame.clear()
        self.details.text = 'Select a material to inspect it.'
        self.details.tooltip = ''

    def preview_path(self, item):
        return self.root / 'previews' / preview_name(item)

    async def load_preview(self, item, generation, frame):
        async with self.preview_slots:
            if generation != self.generation:
                return
            file = self.preview_path(item)
            try:
                if not file.is_file():
                    await asyncio.to_thread(self.client.download, item['preview_url'], file, None, None, 8 * 1024 * 1024)
                if generation != self.generation or self.window is None:
                    return
                frame.clear()
                with frame:
                    ui.Image(str(file), fill_policy=ui.FillPolicy.PRESERVE_ASPECT_CROP)
                if self.choice is item:
                    self.show_selected_preview(file)
            except Exception:
                if generation == self.generation and self.window:
                    frame.clear()
                    with frame:
                        ui.Label('Preview unavailable', alignment=ui.Alignment.CENTER)
                    if self.choice is item:
                        self.preview_frame.clear()
                        with self.preview_frame:
                            ui.Label('Preview unavailable', alignment=ui.Alignment.CENTER)

    def show_selected_preview(self, file):
        self.preview_frame.clear()
        with self.preview_frame:
            ui.Image(str(file), fill_policy=ui.FillPolicy.PRESERVE_ASPECT_FIT)

    def select(self, item):
        if self.loading or not any(value is item for value in self.items):
            return
        self.choice = item
        self.apply_button.enabled = not self.busy
        self.details.text = f'{item["material_name"]}\n{item["name"]}\n{item.get("supplier") or "In-house"} · Version {item["material_version"]}\n\n{item["code"]}'
        self.details.tooltip = item.get('description') or ''
        self.preview_frame.clear()
        for uuid, (outline, _) in self.cards.items():
            outline.selected = uuid == item['uuid']
        file = self.preview_path(item)
        if file.is_file():
            self.show_selected_preview(file)
        else:
            with self.preview_frame:
                ui.Label('Loading preview...', name='muted', alignment=ui.Alignment.CENTER)

    async def prepare(self, uuid, version):
        resolved = await asyncio.to_thread(self.client.resolve, uuid, version)
        paths = await asyncio.to_thread(self.client.prepare, resolved, self.root / 'materials-cache', self.mount.model.as_string.strip())
        return resolved, paths

    async def apply(self):
        if self.busy:
            return
        if not self.choice:
            raise ValueError('Select an OPAL material first.')
        await self.apply_payload({'variant_uuid': self.choice['uuid'], 'material_version': self.choice['material_version']})

    async def apply_payload(self, payload):
        if self.busy:
            raise ValueError('A material operation is already running. Try again when it finishes.')
        context = omni.usd.get_context()
        stage = context.get_stage()
        selected = context.get_selection().get_selected_prim_paths()
        selected = [path for path in selected if stage and stage.GetPrimAtPath(path) and not stage.GetPrimAtPath(path).IsA(UsdShade.Material)]
        if stage is None or not selected:
            raise ValueError('Select geometry in an open Omniverse stage, then apply again.')
        self.busy = True
        self.apply_button.enabled = False
        try:
            self.status.text = 'Downloading and verifying material textures...'
            if 'studio' in payload:
                draft = payload['studio']
                textures = await asyncio.to_thread(self.client.prepare_draft, draft, self.root / 'materials-cache')
                resolved = dict(draft, draft_id=draft['id'], name=draft['label'], material_name='Studio draft')
                quality = 'draft'
            else:
                uuid = payload.get('variant_uuid')
                if not uuid and payload.get('variant'):
                    legacy = await asyncio.to_thread(self.client.browse, payload['variant'])
                    match = next((item for item in legacy['data'] if item['code'] == payload['variant']), None)
                    if match:
                        uuid = match['uuid']
                if not uuid:
                    raise ValueError('The material command has no published variant identity.')
                resolved, textures = await self.prepare(uuid, payload.get('material_version'))
                quality = resolved['quality']
            if context.get_stage() != stage or any(not stage.GetPrimAtPath(path) for path in selected):
                raise ValueError('The stage or selected geometry changed during download. Apply again.')
            material = materials.author(stage, resolved, textures)
            materials.bind(stage, material, selected)
            message = f'Applied {quality} material to {len(selected)} selected prims. Save the stage to keep it.'
            self.status.text = message
            return {'message': message, 'material_path': str(material.GetPath()), 'count': len(selected)}
        finally:
            self.busy = False
            if getattr(self, 'window', None):
                self.apply_button.enabled = self.choice is not None and not self.loading

    async def upgrade(self):
        if self.busy:
            return
        stage = omni.usd.get_context().get_stage()
        if stage is None:
            raise ValueError('Open a USD stage first.')
        map_path = self.mapping.model.as_string.strip()
        mapping = json.loads(Path(map_path).read_text(encoding='utf-8-sig')) if map_path else None
        if mapping and mapping.get('contract') != 'opal-revit-material-map/1':
            raise ValueError('Choose an OPAL material map exported from Revit.')
        candidates = materials.find_upgrades(stage, mapping)
        if not candidates:
            raise ValueError('No OPAL identities found. Provide the Revit material map if the exporter omitted them.')
        self.busy = True
        upgraded = 0
        try:
            # Resolve and download every material first; failures leave the stage unchanged.
            prepared = []
            for index, (old_path, identity) in enumerate(candidates):
                self.status.text = f'Preparing material {index + 1} / {len(candidates)}...'
                resolved, textures = await self.prepare(identity['variant_uuid'], identity['material_version'])
                if resolved['material_uuid'] != identity['material_uuid'] or resolved['source_package_sha256'] != identity['source_package_sha256']:
                    raise ValueError('The exported identity does not match its published source package.')
                prepared.append((old_path, resolved, textures))
            if omni.usd.get_context().get_stage() != stage:
                raise ValueError('The stage changed. Run the upgrade again.')
            for old_path, resolved, textures in prepared:
                material = materials.author(stage, resolved, textures)
                upgraded += materials.replace_bindings(stage, old_path, material)
            self.status.text = f'Upgraded {len(prepared)} materials, {upgraded} bindings. UVs and face assignments are preserved. Save the stage to keep changes.'
        finally:
            self.busy = False
