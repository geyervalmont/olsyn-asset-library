import asyncio
import json
import os
from pathlib import Path
import socket
import urllib.parse
import webbrowser

import omni.ext
import omni.ui as ui
import omni.usd

from .client import APP_VERSION, Client, discover_drive
from . import credentials, materials

class Extension(omni.ext.IExt):
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
        self.window = ui.Window('OPAL Materials', width=790, height=720)
        with self.window.frame:
            with ui.VStack(spacing=8):
                with ui.HStack(height=30):
                    ui.Button('Connect account', clicked_fn=lambda: self.run(self.connect()))
                    ui.Button('Disconnect', clicked_fn=lambda: self.run(self.disconnect()))
                    ui.Button('Setup & versions', clicked_fn=lambda: webbrowser.open(self.client.server + '/connect'))
                self.status = ui.Label('Connected' if self.client.token else 'Connect your OPAL account to browse materials.', height=40, word_wrap=True)
                with ui.HStack(height=30):
                    self.query = ui.StringField()
                    self.category_frame = ui.Frame(width=170)
                    with self.category_frame:
                        self.category = ui.ComboBox(0, 'All categories')
                    ui.Button('Search', width=90, clicked_fn=self.search)
                self.list_frame = ui.ScrollingFrame(height=230)
                with ui.HStack(height=32):
                    ui.Button('Previous', clicked_fn=lambda: self.turn_page(-1))
                    self.page_label = ui.Label('')
                    ui.Button('Next', clicked_fn=lambda: self.turn_page(1))
                with ui.HStack(height=170):
                    self.preview_frame = ui.Frame(width=170)
                    self.details = ui.Label('Select a material to preview it.', word_wrap=True)
                ui.Label('Material drive (optional). Selected files download over HTTPS if unavailable.', height=20)
                self.mount = ui.StringField(height=26)
                if os.name == 'nt':
                    self.mount.model.set_value('M:\\')
                with ui.HStack(height=34):
                    ui.Button('Apply to selected prims', clicked_fn=lambda: self.run(self.apply()))
                    ui.Button('Upgrade stage materials', clicked_fn=lambda: self.run(self.upgrade()))
                ui.Label('Revit material map (optional): export it from Revit → OPAL → Export Material IDs.', height=20)
                self.mapping = ui.StringField(height=26)
        if self.client.token:
            self.run(self.load_facets())
            self.search()
            self.run(self.heartbeat())

    def run(self, coroutine):
        task = asyncio.ensure_future(self.guard(coroutine))
        self.tasks.add(task)
        task.add_done_callback(self.tasks.discard)

    async def guard(self, coroutine):
        try:
            await coroutine
        except asyncio.CancelledError:
            pass
        except Exception as exc:
            self.status.text = str(exc)

    def on_shutdown(self):
        for task in self.tasks:
            task.cancel()
        self.tasks.clear()
        self.window.destroy()
        self.window = None

    async def connect(self):
        if self.client.token:
            self.status.text = 'Already connected. Disconnect first to change accounts.'
            return
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
                self.status.text = 'Connected as ' + result['user']['name']
                await self.load_facets()
                self.search()
                self.run(self.heartbeat())
                return
            if result['status'] == 'delivered':
                raise RuntimeError('This connection was already consumed. Connect again.')
        self.status.text = 'The connection code expired. Connect again.'

    async def disconnect(self):
        if self.client.token:
            await asyncio.to_thread(self.client.request, '/api/v1/account/token', None, 4096, 'DELETE')
        for task in list(self.tasks):
            if task is not asyncio.current_task(): task.cancel()
        self.client.token = ''
        self.session_id = None
        credentials.save(self.root / 'account.bin', '')
        self.choice = None
        self.list_frame.clear()
        self.preview_frame.clear()
        self.details.text = ''
        self.status.text = 'Disconnected. This device’s token has been revoked.'

    async def heartbeat(self):
        if self.session_id is not None:
            return
        result = await asyncio.to_thread(self.client.request, '/api/v1/sessions', {'platform': 'omniverse', 'machine': socket.gethostname(), 'app_version': 'OPAL Omniverse ' + APP_VERSION})
        self.session_id = result['data']['id']
        account = await asyncio.to_thread(self.client.request, '/api/v1/me')
        self.account_email = account['email']
        while self.client.token:
            try:
                stage = omni.usd.get_context().get_stage()
                document = stage.GetRootLayer().GetDisplayName() if stage else None
                discovered = await asyncio.to_thread(discover_drive, self.client.server, self.account_email)
                if discovered:
                    self.mount.model.set_value(discovered)
                mount = self.mount.model.as_string.strip()
                await asyncio.to_thread(self.client.request, f'/api/v1/sessions/{self.session_id}/heartbeat', {'document': document, 'mount_path': mount, 'drive_status': 'available' if mount and Path(mount).is_dir() else 'missing'})
            except Exception:
                pass  # Browsing/apply surfaces auth and network failures independently.
            await asyncio.sleep(30)

    async def load_facets(self):
        data = await asyncio.to_thread(self.client.request, '/api/v1/library/facets')
        self.categories = [{'code': '', 'name': 'All categories'}] + data['data']['categories']
        self.category_frame.clear()
        with self.category_frame:
            self.category = ui.ComboBox(0, *[item['name'] for item in self.categories])

    def search(self):
        self.page = 1
        self.run(self.browse())

    def turn_page(self, step):
        page = self.page + step
        if 1 <= page <= self.last_page:
            self.page = page
            self.run(self.browse())

    async def browse(self):
        self.generation += 1
        generation = self.generation
        self.status.text = 'Loading materials…'
        index = self.category.model.get_item_value_model().as_int
        response = await asyncio.to_thread(self.client.browse, self.query.model.as_string, self.page, self.categories[index]['code'])
        if generation != self.generation:
            return
        meta = response['meta']
        self.last_page = meta['last_page']
        self.page_label.text = f'Page {meta["current_page"]} / {self.last_page} · {meta["total"]} variants'
        self.list_frame.clear()
        with self.list_frame:
            with ui.VStack(spacing=4):
                for item in response['data']:
                    label = f'{item["material_name"]} — {item["name"]}  ·  {item["supplier"] or ""}'
                    ui.Button(label, height=35, clicked_fn=lambda value=item: self.run(self.select(value)))
        self.status.text = 'Select a material to preview or apply.'
        if response['data']:
            await self.select(response['data'][0])

    async def select(self, item):
        self.choice = item
        self.details.text = f'{item["material_name"]} — {item["name"]}\n{item["code"]}\nVersion {item["material_version"]}\n{item["description"] or ""}'
        self.preview_frame.clear()
        try:
            file = self.root / 'previews' / (item['uuid'] + '.jpg')
            await asyncio.to_thread(self.client.download, item['preview_url'], file, None, None, 8 * 1024 * 1024)
            if self.choice is item:
                with self.preview_frame:
                    ui.Image(str(file))
        except Exception:
            if self.choice is item:
                self.status.text = 'Preview unavailable; material details are still available.'

    async def prepare(self, uuid, version):
        resolved = await asyncio.to_thread(self.client.resolve, uuid, version)
        paths = await asyncio.to_thread(self.client.prepare, resolved, self.root / 'materials-cache', self.mount.model.as_string.strip())
        return resolved, paths

    async def apply(self):
        if self.busy:
            return
        if not self.choice:
            raise ValueError('Select an OPAL material first.')
        context = omni.usd.get_context()
        stage = context.get_stage()
        selected = context.get_selection().get_selected_prim_paths()
        if stage is None or not selected:
            raise ValueError('Select geometry in an open USD stage first.')
        self.busy = True
        try:
            self.status.text = 'Fetching published high-resolution textures…'
            resolved, textures = await self.prepare(self.choice['uuid'], self.choice['material_version'])
            if context.get_stage() != stage:
                raise ValueError('The stage changed during the download. Apply again.')
            material = materials.author(stage, resolved, textures)
            materials.bind(stage, material, selected)
            self.status.text = f'Applied {resolved["quality"]} material to {len(selected)} selected prims. Save the stage to keep it.'
        finally:
            self.busy = False

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
                self.status.text = f'Preparing material {index + 1} / {len(candidates)}…'
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
