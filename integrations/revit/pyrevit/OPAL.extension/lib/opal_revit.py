# -*- coding: utf-8 -*-
"""
Revit host for the OPAL workflow (IronPython 2.7 under pyRevit).

Configuration lives in %APPDATA%\\OPAL\\config.json:

    {
      "api": "https://asset-library.test",
      "token": "opal_...",              # Settings → API tokens in the web app
      "drive": "studio-share",
      "mount": "M:\\\\",                  # or "\\\\\\\\server\\\\opal"
      "verify_tls": true
    }
"""

import os
import threading

from Autodesk.Revit import DB  # noqa: E402
from Autodesk.Revit import UI  # noqa: E402
from pyrevit import HOST_APP, forms, revit, script  # noqa: E402

from opal_client import Agent, CommandExecutor, Config, Drive, OpalApi, Workflow  # noqa: E402
from opal_client.host import Host, HostMaterial, SnapshotHost  # noqa: E402


LOG_PATH = os.path.join(os.environ.get("APPDATA", os.path.expanduser("~")), "OPAL", "agent.log")


def log_line(message):
    """Append to %APPDATA%\\OPAL\\agent.log; the only trace that survives a closed output window."""
    try:
        import datetime
        directory = os.path.dirname(LOG_PATH)
        if not os.path.isdir(directory):
            os.makedirs(directory)
        with open(LOG_PATH, "a") as handle:
            handle.write("%s %s\n" % (datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"), message))
    except Exception:
        pass


CONFIG_PATH = Config.default_path()

# Identity parameters we read to recognise a material and write on apply.
PARAMETER_NAMES = {
    "Description": ("ALL_MODEL_DESCRIPTION",),
    "Model": ("ALL_MODEL_MODEL",),
    "Manufacturer": ("ALL_MODEL_MANUFACTURER",),
    "Keywords": ("ALL_MODEL_KEYWORDS", "MATERIAL_KEYWORDS"),
    "Comments": ("ALL_MODEL_INSTANCE_COMMENTS",),
}

# Appearance schema property names, with static-name lookups as Matt's scripts did.
SLOT_PROPERTIES = {
    "base_color": ("Generic", "GenericDiffuse", "generic_diffuse"),
    "bump": ("Generic", "GenericBumpMap", "generic_bump_map"),
    "glossiness": ("Generic", "GenericGlossiness", "generic_glossiness"),
}
GENERIC_SCHEMA = "GenericSchema"

BITMAP_SOURCE = ("UnifiedBitmap", "UnifiedbitmapBitmap", "unifiedbitmap_Bitmap")
BITMAP_SCALE_X = ("UnifiedBitmap", "TextureRealWorldScaleX", "texture_RealWorldScaleX")
BITMAP_SCALE_Y = ("UnifiedBitmap", "TextureRealWorldScaleY", "texture_RealWorldScaleY")
BITMAP_U_REPEAT = ("UnifiedBitmap", "TextureURepeat", "texture_URepeat")
BITMAP_V_REPEAT = ("UnifiedBitmap", "TextureVRepeat", "texture_VRepeat")


def load_config():
    config = Config().load()
    if not config.get("token"):
        forms.alert("OPAL is not linked on this machine.\n\nRun OPAL → Connect first (config: %s)." % CONFIG_PATH, exitscript=True)
    for key in ("api", "drive", "mount"):
        if not config.get(key):
            forms.alert("OPAL config is missing '%s' (%s)." % (key, CONFIG_PATH), exitscript=True)
    return config


def open_url(url):
    """Opens the default browser; on .NET 8 Process.Start needs UseShellExecute."""
    from System.Diagnostics import Process, ProcessStartInfo
    info = ProcessStartInfo(url)
    info.UseShellExecute = True
    Process.Start(info)


def machine_name():
    return os.environ.get("COMPUTERNAME") or "revit"


def app_version():
    try:
        return "Revit %s" % HOST_APP.version
    except Exception:
        return "Revit"


def build_workflow(doc=None):
    config = load_config()
    api = OpalApi(config["api"], config["token"], verify_tls=config.get("verify_tls", True))
    drives = dict((d["slug"], d) for d in api.drives())
    if config["drive"] not in drives:
        forms.alert("Drive '%s' is not known to the library. Available: %s" % (config["drive"], ", ".join(sorted(drives))), exitscript=True)
    drive = Drive(config["drive"], config["mount"], drives[config["drive"]]["root_path"])
    host = RevitHost(doc or revit.doc)
    return Workflow(api, host, drive), config


def _visual_name(class_name, static_name, fallback):
    try:
        value = getattr(getattr(DB.Visual, class_name), static_name)
        if value:
            return value
    except Exception:
        pass
    return fallback


def _schema_of_asset(asset):
    """The asset's BaseSchema (GenericSchema, PrismOpaqueSchema, ...), or ''."""
    try:
        prop = asset.FindByName("BaseSchema")
        return prop.Value if prop is not None else ""
    except Exception:
        return ""


def _schema_of(appearance_element):
    """BaseSchema of an AppearanceAssetElement's rendering asset."""
    try:
        return _schema_of_asset(appearance_element.GetRenderingAsset())
    except Exception:
        return ""


def _find_property(asset, spec):
    names = [_visual_name(*spec), spec[2]]
    for name in names:
        try:
            prop = asset.FindByName(name)
            if prop is not None:
                return prop
        except Exception:
            pass
    return None


def _to_property_units(mm_value, prop):
    try:
        return DB.UnitUtils.Convert(mm_value, DB.UnitTypeId.Millimeters, prop.GetUnitTypeId())
    except Exception:
        return mm_value / 25.4  # texture scale properties are inches when unitless


class RevitHost(Host):
    platform = "revit"

    def __init__(self, doc, uidoc=None):
        self.doc = doc
        self.uidoc = uidoc

    # -- reading -----------------------------------------------------------

    def document_name(self):
        return self.doc.Title if self.doc is not None else ""

    def materials(self):
        collector = DB.FilteredElementCollector(self.doc).OfClass(DB.Material)
        return [self._wrap(material) for material in collector]

    def material(self, host_id):
        element = self._element(host_id)
        return self._wrap(element) if element is not None else None

    def selected_material(self):
        """The material of a picked face, or the single selected material element."""
        uidoc = self.uidoc or revit.uidoc
        selection = [self.doc.GetElement(i) for i in uidoc.Selection.GetElementIds()]
        materials = [e for e in selection if isinstance(e, DB.Material)]
        if len(materials) == 1:
            return self._wrap(materials[0])
        try:
            from Autodesk.Revit.UI.Selection import ObjectType
            reference = uidoc.Selection.PickObject(ObjectType.Face, "Click a face to pick its material")
        except Exception:
            return None
        element = self.doc.GetElement(reference)
        face = element.GetGeometryObjectFromReference(reference)
        material_id = face.MaterialElementId if face is not None else DB.ElementId.InvalidElementId
        if material_id == DB.ElementId.InvalidElementId:
            return None
        return self._wrap(self.doc.GetElement(material_id))

    # -- writing -----------------------------------------------------------

    def apply_textures(self, material, textures, scale_mm):
        element = self._element(material.host_id)
        appearance_id = element.AppearanceAssetId

        with revit.Transaction("OPAL apply textures to %s" % material.name):
            # Stock Revit materials mostly use Advanced (physically based)
            # schemas whose texture slots differ from Generic's. The library's
            # Revit set is authored for Generic (diffuse, bump, glossiness), so
            # give the material a Generic appearance asset of its own.
            if appearance_id == DB.ElementId.InvalidElementId or _schema_of(self.doc.GetElement(appearance_id)) != GENERIC_SCHEMA:
                appearance_id = self._generic_appearance(element)
            else:
                appearance_id = self._own_appearance(element, appearance_id)

            applied, missing = [], []
            scope = DB.Visual.AppearanceAssetEditScope(self.doc)
            try:
                editable = scope.Start(appearance_id)
                for slot, path in textures.items():
                    prop = _find_property(editable, SLOT_PROPERTIES[slot])
                    if prop is None:
                        missing.append(slot)
                        continue
                    bitmap = prop.GetSingleConnectedAsset()
                    if bitmap is None:
                        bitmap = prop.AddConnectedAsset("UnifiedBitmapSchema")
                    source = _find_property(bitmap, BITMAP_SOURCE)
                    if source is None:
                        missing.append(slot)
                        continue
                    source.Value = path
                    if scale_mm:
                        for spec in (BITMAP_SCALE_X, BITMAP_SCALE_Y):
                            scale = _find_property(bitmap, spec)
                            if scale is not None:
                                scale.Value = _to_property_units(float(scale_mm), scale)
                    for spec in (BITMAP_U_REPEAT, BITMAP_V_REPEAT):
                        repeat = _find_property(bitmap, spec)
                        if repeat is not None:
                            repeat.Value = True
                    applied.append(slot)
                scope.Commit(False)
            except Exception:
                if scope.IsActive:
                    scope.Cancel()
                raise

            try:
                element.UseRenderAppearanceForShading = True
            except Exception:
                pass

        if not applied:
            raise RuntimeError("no texture slot could be set on %s (schema %s; missing: %s)" % (
                material.name, _schema_of(self.doc.GetElement(appearance_id)), ", ".join(missing) or "none"))
        log_line("applied %s to %s%s" % (", ".join(applied), material.name, (" (no slot for %s)" % ", ".join(missing)) if missing else ""))
        material.textures = dict((slot, textures[slot]) for slot in applied)
        material.scale_mm = scale_mm

    def write_parameters(self, material, parameters):
        element = self._element(material.host_id)
        with revit.Transaction("OPAL identity on %s" % material.name):
            for name, value in parameters.items():
                parameter = self._parameter(element, name)
                if parameter is not None and not parameter.IsReadOnly:
                    parameter.Set(value)
        material.parameters.update(parameters)

    # -- helpers -----------------------------------------------------------

    def _wrap(self, element):
        parameters = {}
        for name in PARAMETER_NAMES:
            parameter = self._parameter(element, name)
            if parameter is not None and parameter.HasValue:
                parameters[name] = parameter.AsString() or ""
        return HostMaterial(element.UniqueId, element.Name, parameters)

    def _element(self, host_id):
        try:
            return self.doc.GetElement(host_id)
        except Exception:
            return None

    def _parameter(self, element, name):
        parameter = element.LookupParameter(name)
        if parameter is not None:
            return parameter
        for built_in in PARAMETER_NAMES.get(name, ()):
            try:
                parameter = element.get_Parameter(getattr(DB.BuiltInParameter, built_in))
                if parameter is not None:
                    return parameter
            except Exception:
                continue
        return None

    def _generic_appearance(self, element):
        """
        A Generic appearance asset owned by this material: a duplicate of a
        Generic-schema asset already in the document (as Matt's scripts do),
        else one created from the library's base Generic asset.
        """
        name = "%s (OPAL)" % element.Name
        suffix = 1
        while DB.AppearanceAssetElement.GetAppearanceAssetElementByName(self.doc, name) is not None:
            suffix += 1
            name = "%s (OPAL %d)" % (element.Name, suffix)

        created = None
        for candidate in DB.FilteredElementCollector(self.doc).OfClass(DB.AppearanceAssetElement):
            if _schema_of(candidate) == GENERIC_SCHEMA:
                created = candidate.Duplicate(name)
                break
        if created is None:
            for asset in self.doc.Application.GetAssets(DB.Visual.AssetType.Appearance):
                if _schema_of_asset(asset) == GENERIC_SCHEMA:
                    created = DB.AppearanceAssetElement.Create(self.doc, name, asset)
                    break
        if created is None:
            raise RuntimeError("no Generic appearance asset in the document or the Revit library to start from")
        element.AppearanceAssetId = created.Id
        log_line("gave %s a Generic appearance asset %r" % (element.Name, name))
        return created.Id

    def _own_appearance(self, element, appearance_id):
        """Duplicate a shared appearance asset so edits don't leak into other materials."""
        others = [
            m for m in DB.FilteredElementCollector(self.doc).OfClass(DB.Material)
            if m.AppearanceAssetId == appearance_id and m.Id != element.Id
        ]
        if not others:
            return appearance_id
        asset = self.doc.GetElement(appearance_id)
        copy = asset.Duplicate("%s (OPAL)" % element.Name)
        element.AppearanceAssetId = copy.Id
        return copy.Id


def print_plan(plan):
    output = script.get_output()
    output.print_md("```\n%s\n```" % plan.describe())


# -- the live agent inside Revit --------------------------------------------
#
# Revit API calls are only legal on Revit's thread inside a valid API context.
# The realtime client runs on a background thread and merely queues commands;
# an ExternalEvent hands them to `OpalCommandHandler.Execute`, which runs on
# the Revit thread with a fresh RevitHost for the active document.



def current_document_title():
    """Revit thread only."""
    try:
        return revit.doc.Title if revit.doc else ""
    except Exception:
        return ""


class OpalCommandHandler(UI.IExternalEventHandler):
    def __init__(self, runner):
        self.runner = runner

    def Execute(self, uiapp):
        try:
            self.runner.drain(uiapp)
        except Exception as error:  # never let an exception escape into Revit
            self.runner.last_error = str(error)
            log_line("command handler: %s" % error)

    def GetName(self):
        return "OPAL command handler"


class AgentHost(Host):
    """What the agent knows about Revit off the Revit thread: cached only."""

    platform = "revit"

    def __init__(self, runner):
        self.runner = runner

    def document_name(self):
        return self.runner.document_title


class RevitAgentRunner(object):
    def __init__(self):
        self.agent = None
        self.thread = None
        self.event = None
        self.handler = None
        self.config = None
        self.drive = None
        self.document_title = ""
        self.last_error = None
        self._queue = []
        self._notices = []
        self._lock = threading.Lock()

    # -- lifecycle (call from the Revit thread) --------------------------------

    def ensure_event(self):
        """ExternalEvent.Create must run in a valid API context (startup or a button)."""
        if self.event is None:
            self.handler = OpalCommandHandler(self)
            self.event = UI.ExternalEvent.Create(self.handler)
        return self.event

    def start_if_configured(self):
        config = Config().load()
        if config.get("token") and config.get("api"):
            try:
                self.ensure_event()  # startup runs on the Revit thread
                self.start(config, document_title=current_document_title())
            except Exception as error:
                self.last_error = "agent did not start: %s" % error
                log_line("startup: " + self.last_error)

    def start(self, config, document_title=None):
        """Safe on any thread: no Revit API, no WPF. Pass the title from the Revit thread."""
        if self.running():
            return self.agent
        if self.event is None:
            raise RuntimeError("ensure_event() must run on the Revit thread first")
        self.config = config
        if document_title is not None:
            self.document_title = document_title
        api = OpalApi(config["api"], config["token"], verify_tls=config.get("verify_tls", True))
        self.drive = self._drive(api, config)
        self.agent = Agent(
            api, AgentHost(self), realtime=config.get("realtime"),
            machine=machine_name(), app_version=app_version(), dispatch=self.enqueue,
            log=self._log,
        )
        self.thread = threading.Thread(target=self._run)
        self.thread.IsBackground = True  # IronPython exposes the .NET thread flag
        self.thread.daemon = True
        self.thread.start()
        return self.agent

    def stop(self):
        if self.agent is not None:
            self.agent.stop()

    def running(self):
        return self.thread is not None and self.thread.is_alive()

    def status(self):
        status = self.agent.status() if self.agent else {"connected": False}
        status["running"] = self.running()
        status["document"] = self.document_title
        status["queued"] = len(self._queue)
        status["last_error"] = status.get("last_error") or self.last_error
        status["config"] = CONFIG_PATH
        return status

    # -- command flow --------------------------------------------------------

    def enqueue(self, command):
        """Background thread: queue and wake Revit."""
        with self._lock:
            self._queue.append(command)
        self.event.Raise()

    def notify(self, message):
        """Background thread: show a toast, later, on the Revit thread."""
        log_line(message)
        with self._lock:
            self._notices.append(message)
        if self.event is not None:
            self.event.Raise()

    def drain(self, uiapp):
        """Revit thread: show pending notices, then execute everything queued."""
        with self._lock:
            notices, self._notices = self._notices, []
        for message in notices:
            try:
                forms.toast(message, title="OPAL")
            except Exception:
                pass
        uidoc = uiapp.ActiveUIDocument
        doc = uidoc.Document if uidoc is not None else None
        self.document_title = doc.Title if doc is not None else ""
        while True:
            with self._lock:
                if not self._queue:
                    return
                command = self._queue.pop(0)
            if doc is None:
                self.agent.handle(command, _FailingExecutor("no document is open in Revit"))
                continue
            if command.get("type") == "sync":
                # Reading materials needs the Revit thread; resolving them is
                # HTTP, which would freeze Revit for minutes. Snapshot, then
                # finish on a worker.
                snapshot = SnapshotHost("revit", doc.Title, RevitHost(doc, uidoc).materials())
                workflow = Workflow(self.agent.api, snapshot, self.drive)
                worker = threading.Thread(target=self.agent.handle, args=(command, CommandExecutor(workflow)))
                worker.daemon = True
                worker.start()
                continue
            workflow = Workflow(self.agent.api, RevitHost(doc, uidoc), self.drive)
            self.agent.handle(command, CommandExecutor(workflow))

    # -- helpers -------------------------------------------------------------

    def _run(self):
        try:
            self.agent.run()
        except Exception as error:
            self.last_error = str(error)
            log_line("agent thread stopped: %s" % error)

    def _drive(self, api, config):
        drives = dict((d["slug"], d) for d in api.drives())
        if config.get("drive") not in drives:
            raise RuntimeError("drive %r is not known to the library" % config.get("drive"))
        return Drive(config["drive"], config.get("mount") or "", drives[config["drive"]]["root_path"])

    def _log(self, message):
        # File only: this runs on the agent thread, and pyRevit's logger may
        # touch the WPF output window, which is only legal on the Revit thread.
        log_line(message)


class _FailingExecutor(object):
    def __init__(self, message):
        self.message = message

    def execute(self, command):
        raise RuntimeError(self.message)


# One runner per Revit session; startup.py and the buttons share it.
def get_runner():
    """
    One runner per Revit process. pyRevit gives every button its own Python
    engine (and so its own copy of this module), so the instance lives in
    AppDomain data, which all engines share.
    """
    try:
        import System
        domain = System.AppDomain.CurrentDomain
        existing = domain.GetData("OPAL_RUNNER")
        if existing is not None:
            return existing
        created = RevitAgentRunner()
        domain.SetData("OPAL_RUNNER", created)
        return created
    except Exception as error:
        log_line("runner is engine-local (AppDomain data unavailable: %s)" % error)
        return RevitAgentRunner()


runner = get_runner()
