# Keep transport and USD helpers importable without an installed Kit UI.
try:
    import omni.ext
except ImportError:
    pass
else:
    from .extension import Extension
