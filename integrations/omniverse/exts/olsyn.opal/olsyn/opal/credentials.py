"""Windows tokens use current-user DPAPI. Other hosts keep tokens in memory."""
import ctypes
from ctypes import wintypes
import os
from pathlib import Path

class Blob(ctypes.Structure):
    _fields_ = [('size', wintypes.DWORD), ('data', ctypes.POINTER(ctypes.c_ubyte))]

def _crypt(data, decrypt=False):
    buffer = (ctypes.c_ubyte * len(data)).from_buffer_copy(data)
    source = Blob(len(data), buffer)
    target = Blob()
    function = ctypes.windll.crypt32.CryptUnprotectData if decrypt else ctypes.windll.crypt32.CryptProtectData
    # UI_FORBIDDEN; encryption remains scoped to the current Windows user.
    if not function(ctypes.byref(source), None, None, None, None, 1, ctypes.byref(target)):
        raise OSError('Windows could not protect the OPAL credential.')
    try:
        return ctypes.string_at(target.data, target.size)
    finally:
        free = ctypes.windll.kernel32.LocalFree
        free.argtypes = [ctypes.c_void_p]
        free.restype = ctypes.c_void_p
        free(target.data)

def load(path):
    if os.name != 'nt' or not Path(path).is_file():
        return ''
    return _crypt(Path(path).read_bytes(), True).decode()

def save(path, token):
    path = Path(path)
    if not token:
        path.unlink(missing_ok=True)
    elif os.name == 'nt':
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(_crypt(token.encode()))
