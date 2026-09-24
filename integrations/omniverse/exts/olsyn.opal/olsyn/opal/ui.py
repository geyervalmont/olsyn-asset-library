"""OPAL's Kit browser layout. Transport and USD edits live in the extension."""
import webbrowser
import omni.ui as ui
from .browser import page_numbers

STYLE = {
    'Window': {'background_color': 0xFF201D19},
    'Label': {'color': 0xFFF0EBE4, 'font_size': 14},
    'Label::muted': {'color': 0xFFABA69E, 'font_size': 12},
    'Label::title': {'font_size': 23},
    'Label::brand': {'color': 0xFFC1D8AD, 'font_size': 20},
    'Button': {'background_color': 0xFF39342D, 'border_radius': 5, 'padding': 8},
    'Button:hovered': {'background_color': 0xFF50473C},
    'Button:disabled': {'background_color': 0xFF2A2723, 'color': 0xFF79756F},
    'Button::primary': {'background_color': 0xFFC1D8AD},
    'Button.Label::primary': {'color': 0xFF252C21},
    'Button::primary:hovered': {'background_color': 0xFFD1E8BD},
    'StringField': {'background_color': 0xFF302B25, 'border_radius': 5, 'padding': 8, 'font_size': 14},
    'ComboBox': {'background_color': 0xFF39342D, 'border_radius': 5},
    'ScrollingFrame': {'background_color': 0xFF201D19, 'secondary_color': 0xFF51483D, 'scrollbar_size': 6},
}


def build(e):
    width = min(1160, ui.Workspace.get_main_window_width() or 1160)
    height = min(830, ui.Workspace.get_main_window_height() or 830)
    e.window = ui.Window('OPAL Materials', width=max(640, width - 30), height=max(580, height - 60), position_x=15, position_y=40)
    e.window.frame.style = STYLE
    with e.window.frame:
        with ui.ZStack():
            ui.Rectangle(style={'background_color': 0xFF201D19})
            with ui.HStack():
                ui.Spacer(width=16)
                with ui.VStack():
                    ui.Spacer(height=12)
                    with ui.VStack(spacing=12, style=STYLE):
                        with ui.HStack(height=46, spacing=18):
                            ui.Label('O P A L', name='brand', width=110)
                            ui.Label('Material library', name='title')
                            e.account_status = ui.Label('Not connected', name='muted', width=200, alignment=ui.Alignment.RIGHT_CENTER)
                            e.connect_button = ui.Button('Connect account', width=130, clicked_fn=lambda: e.run(e.connect()))
                            e.disconnect_button = ui.Button('Disconnect', width=95, visible=bool(e.client.token), clicked_fn=lambda: e.run(e.disconnect()))
                        with ui.HStack(spacing=20):
                            with ui.VStack(spacing=10):
                                with ui.HStack(height=36, spacing=8):
                                    e.query = ui.StringField(tooltip='Search material, colourway, supplier or code')
                                    e.query.model.add_end_edit_fn(lambda _: e.search())
                                    e.category_frame = ui.Frame(width=180)
                                    with e.category_frame:
                                        e.category = ui.ComboBox(0, 'All categories')
                                    ui.Button('Search', width=82, clicked_fn=e.search)
                                    ui.Button('Clear', width=65, clicked_fn=e.clear_search)
                                e.page_label = ui.Label('Discover finishes from your shared library', name='muted', height=22)
                                e.list_frame = ui.ScrollingFrame(horizontal_scrollbar_policy=ui.ScrollBarPolicy.SCROLLBAR_ALWAYS_OFF)
                                with e.list_frame:
                                    ui.Label('Connect your account to explore materials.\nPreviews appear here; select one to see its details.', word_wrap=True, alignment=ui.Alignment.CENTER)
                                e.pager = ui.Frame(height=34)
                            with ui.VStack(width=260, spacing=12):
                                ui.Label('SELECTED MATERIAL', name='muted', height=22)
                                e.preview_frame = ui.Frame(height=240)
                                with e.preview_frame:
                                    ui.Label('Select a preview', name='muted', alignment=ui.Alignment.CENTER)
                                e.details = ui.Label('Find the right finish, then apply it to your selected geometry.', word_wrap=True, height=110, alignment=ui.Alignment.LEFT_TOP)
                                e.apply_button = ui.Button('Apply to selection', name='primary', height=40, enabled=False, clicked_fn=lambda: e.run(e.apply()))
                                ui.Label('Select geometry in the stage first.\nUses the highest published texture quality.', name='muted', word_wrap=True, height=40)
                                with ui.CollapsableFrame('Revit to USD', collapsed=True, height=0):
                                    with ui.VStack(spacing=8):
                                        ui.Label('Upgrade exported OPAL material IDs while keeping the scene’s bindings and UVs.', name='muted', word_wrap=True, height=55)
                                        e.mapping = ui.StringField(height=30, tooltip='Optional OPAL material-map JSON exported from Revit')
                                        ui.Button('Upgrade stage materials', height=34, clicked_fn=lambda: e.run(e.upgrade()))
                                with ui.CollapsableFrame('Connection & storage', collapsed=True, height=0):
                                    with ui.VStack(spacing=8):
                                        ui.Label('Optional material drive. Otherwise, selected textures download over HTTPS.', name='muted', word_wrap=True, height=42)
                                        e.mount = ui.StringField(height=30)
                                        ui.Button('Setup & versions', height=30, clicked_fn=lambda: webbrowser.open(e.client.server + '/connect'))
                                        ui.Label('Linux sign-in lasts for this Kit session.', name='muted', word_wrap=True, height=30)
                                ui.Spacer()
                        e.status = ui.Label('Connect your OPAL account to get started.', name='muted', height=42, word_wrap=True)
                    ui.Spacer(height=12)
                ui.Spacer(width=16)


def render_cards(e, items, generation):
    e.list_frame.clear()
    e.cards = {}
    with e.list_frame:
        if not items:
            with ui.VStack(height=180, spacing=12):
                ui.Spacer()
                ui.Label('No materials found', name='title', alignment=ui.Alignment.CENTER, height=30)
                ui.Label('Try a broader search or another category.', name='muted', alignment=ui.Alignment.CENTER, height=25)
            return
        with ui.VGrid(column_width=190, height=0):
            for item in items:
                with ui.VStack(width=190, height=240):
                    with ui.ZStack(width=178, height=228):
                        outline = ui.Rectangle(style={'background_color': 0xFF302B25, 'border_radius': 7})
                        with ui.VStack(spacing=5):
                            preview = ui.Frame(height=160)
                            with preview:
                                ui.Label('Loading preview...', name='muted', alignment=ui.Alignment.CENTER)
                            with ui.HStack(height=55):
                                ui.Spacer(width=8)
                                with ui.VStack(spacing=5):
                                    ui.Spacer(height=2)
                                    ui.Label(item['material_name'], height=20, elided_text=True, tooltip=item['material_name'])
                                    ui.Label(item['name'], name='muted', height=18, elided_text=True, tooltip=item['name'])
                                ui.Spacer(width=8)
                        ui.Button('', tooltip=f'{item["material_name"]} - {item["name"]}\n{item.get("supplier") or "In-house"}',
                                  style={'background_color': 0x00000000, 'border_width': 0, 'Button:hovered': {'background_color': 0x1850BCAA}},
                                  clicked_fn=lambda value=item: e.run(e.select(value)))
                    ui.Spacer(height=12)
                e.cards[item['uuid']] = (outline, preview)
                e.run(e.load_preview(item, generation, preview))


def render_pager(e):
    e.pager.clear()
    with e.pager:
        with ui.HStack(spacing=5):
            ui.Button('Previous', width=85, enabled=e.page > 1, clicked_fn=lambda: e.turn_page(-1))
            ui.Spacer()
            for page in page_numbers(e.page, e.last_page):
                if page is None:
                    ui.Label('...', width=20, alignment=ui.Alignment.CENTER)
                else:
                    ui.Button(str(page), name='primary' if page == e.page else '', width=34,
                              clicked_fn=lambda value=page: e.go_page(value) if value != e.page else None)
            ui.Spacer()
            ui.Button('Next', width=85, enabled=e.page < e.last_page, clicked_fn=lambda: e.turn_page(1))
