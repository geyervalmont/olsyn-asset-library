"""Native Kit controls for the OPAL material browser."""
import webbrowser
import omni.ui as ui
from .browser import page_numbers

# Only thumbnail states need custom styling; controls inherit the host's theme.
CARD_STYLE = {
    'Rectangle::thumbnail': {'background_color': 0xFF303030, 'border_width': 1, 'border_color': 0xFF505050},
    'Rectangle::thumbnail:selected': {'border_width': 2, 'border_color': 0xFFCC9955},
    'Rectangle::thumbnail:hovered': {'border_color': 0xFFAAAAAA},
}


def build(e):
    e.window = ui.Window('OPAL Materials', width=1000, height=700)
    ui.Workspace.set_show_window_fn('OPAL Materials', lambda visible: setattr(e.window, 'visible', visible))
    with e.window.frame:
        with ui.VStack(spacing=6):
            with ui.HStack(height=24, spacing=6):
                e.account_status = ui.Label('Not connected', elided_text=True)
                e.connect_button = ui.Button('Connect account', width=120, clicked_fn=lambda: e.run(e.connect()))
                e.disconnect_button = ui.Button('Disconnect', width=90, visible=bool(e.client.token), clicked_fn=lambda: e.run(e.disconnect()))
            ui.Separator(height=1)
            with ui.HStack(height=26, spacing=4):
                ui.Label('Search', width=44)
                e.query = ui.StringField(identifier='opal_search', tooltip='Search material, colourway, supplier or code. Results update as you type.')
                e.query.model.add_value_changed_fn(lambda _: e.search(delay=0.3))
                e.search_button = ui.Button('Search', width=65, clicked_fn=e.search)
                e.refresh_button = ui.Button('Refresh', width=65, clicked_fn=e.refresh)
            with ui.HStack(height=24, spacing=4):
                ui.Label('Category', width=60)
                e.category_frame = ui.Frame()
                with e.category_frame:
                    e.category = ui.ComboBox(0, 'All categories')
                e.clear_button = ui.Button('Reset filters', width=95, clicked_fn=e.clear_search)
            e.page_label = ui.Label('Connect to browse your material library.', height=22, elided_text=True)
            with ui.HStack(spacing=8):
                e.list_frame = ui.ScrollingFrame(horizontal_scrollbar_policy=ui.ScrollBarPolicy.SCROLLBAR_ALWAYS_OFF)
                with e.list_frame:
                    ui.Label('Connect your OPAL account to browse materials.', word_wrap=True, alignment=ui.Alignment.CENTER)
                with ui.VStack(width=230, spacing=6):
                    with ui.CollapsableFrame('Material', height=0):
                        with ui.VStack(spacing=6):
                            e.preview_frame = ui.Frame(height=190)
                            e.details = ui.Label('Select a material to inspect it.', word_wrap=True, height=0)
                    e.apply_button = ui.Button('Apply to selection', height=28, enabled=False, clicked_fn=lambda: e.run(e.apply()))
                    ui.Label('Select geometry in the stage, then apply.\nUses the highest published texture quality.', word_wrap=True, height=42)
                    with ui.CollapsableFrame('Revit to USD', collapsed=True, height=0):
                        with ui.VStack(spacing=6):
                            ui.Label('Upgrade exported OPAL materials while keeping bindings and UVs.', word_wrap=True, height=40)
                            e.mapping = ui.StringField(height=24, tooltip='Optional material-map JSON exported from Revit')
                            ui.Button('Upgrade stage materials', height=26, clicked_fn=lambda: e.run(e.upgrade()))
                    with ui.CollapsableFrame('Connection & storage', collapsed=True, height=0):
                        with ui.VStack(spacing=6):
                            ui.Label('Optional material drive path. Leave empty to use HTTPS.', word_wrap=True, height=40)
                            e.mount = ui.StringField(height=24)
                            ui.Button('Setup & versions', height=26, clicked_fn=lambda: webbrowser.open(e.client.server + '/connect'))
                            ui.Label('Linux sign-in lasts for this Kit session.', word_wrap=True, height=32)
                    ui.Spacer()
            e.pager = ui.Frame(height=26)
            ui.Separator(height=1)
            e.status = ui.Label('Connect your OPAL account to get started.', height=32, word_wrap=True)


def render_cards(e, items, generation, message=None):
    e.list_frame.clear()
    e.cards = {}
    with e.list_frame:
        if not items:
            with ui.VStack(height=120, spacing=8):
                ui.Spacer()
                ui.Label(message or 'No materials found.\nTry another search or reset the category filter.', word_wrap=True, alignment=ui.Alignment.CENTER, height=50)
                if message:
                    ui.Button('Retry', height=26, clicked_fn=lambda: e.search(force=True))
                else:
                    ui.Button('Reset filters', height=26, clicked_fn=e.clear_search)
            return
        with ui.VGrid(column_width=160, height=0):
            for item in items:
                with ui.VStack(width=160, height=202):
                    # Handle the complete tile instead of an invisible button layered
                    # over asynchronous images. Labels and loaded previews share selection.
                    with ui.ZStack(width=152, height=194, style=CARD_STYLE,
                                   mouse_pressed_fn=lambda x, y, button, mods, value=item: e.select(value) if button == 0 else None):
                        outline = ui.Rectangle(name='thumbnail')
                        with ui.VStack(spacing=3):
                            preview = ui.Frame(height=145)
                            with preview:
                                ui.Label('Loading preview...', alignment=ui.Alignment.CENTER)
                            ui.Label(item['material_name'], height=19, elided_text=True, tooltip=item['material_name'])
                            ui.Label(item['name'], height=19, elided_text=True, tooltip=item['name'])
                    ui.Spacer(height=8)
                e.cards[item['uuid']] = (outline, preview)
                e.run(e.load_preview(item, generation, preview))


def render_pager(e):
    e.pager.clear()
    with e.pager:
        with ui.HStack(spacing=4):
            e.previous_button = ui.Button('Previous', width=75, enabled=e.page > 1, clicked_fn=lambda: e.turn_page(-1))
            ui.Spacer()
            for page in page_numbers(e.page, e.last_page):
                if page is None:
                    ui.Label('...', width=18, alignment=ui.Alignment.CENTER)
                elif page == e.page:
                    ui.Label(str(page), width=30, alignment=ui.Alignment.CENTER)
                else:
                    ui.Button(str(page), width=30, clicked_fn=lambda value=page: e.go_page(value))
            ui.Spacer()
            e.next_button = ui.Button('Next', width=75, enabled=e.page < e.last_page, clicked_fn=lambda: e.turn_page(1))
