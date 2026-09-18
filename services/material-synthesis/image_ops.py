"""Deterministic material operations, independently testable without CUDA."""
import numpy as np
from PIL import Image, ImageOps


def prepare(image: Image.Image, crop: dict, resolution: int) -> Image.Image:
    image = ImageOps.exif_transpose(image).convert("RGB")
    side = max(1, round(min(image.size) * float(crop["size"]) / 100))
    left = round((image.width - side) * float(crop["x"]) / 100)
    top = round((image.height - side) * float(crop["y"]) / 100)
    return image.crop((left, top, left + side, top + side)).resize((resolution, resolution), Image.Resampling.LANCZOS)


def normalize_normals(encoded: np.ndarray) -> np.ndarray:
    n = encoded * 2 - 1
    n /= np.maximum(np.linalg.norm(n, axis=-1, keepdims=True), 1e-6)
    return n * 0.5 + 0.5


def height_from_normals(encoded: np.ndarray) -> np.ndarray:
    """Periodic least-squares integration. Input OpenGL (+Y up), image rows down.

    This recovers relative relief, not physical millimetres or absolute height.
    """
    n = normalize_normals(encoded) * 2 - 1
    nz = np.maximum(n[..., 2], 0.1)
    dx = -n[..., 0] / nz
    dy = n[..., 1] / nz
    h, w = dx.shape
    wx = 2 * np.pi * np.fft.fftfreq(w)[None, :]
    wy = 2 * np.pi * np.fft.fftfreq(h)[:, None]
    denom = wx * wx + wy * wy
    denom[0, 0] = 1
    spectrum = (-1j * wx * np.fft.fft2(dx) - 1j * wy * np.fft.fft2(dy)) / denom
    spectrum[0, 0] = 0
    height = np.fft.ifft2(spectrum).real
    span = float(np.ptp(height))
    return np.full_like(height, 0.5) if span < 1e-6 else (height - height.min()) / span


def encode_png(array: np.ndarray, path, height=False):
    if height:
        Image.fromarray(np.round(np.clip(array, 0, 1) * 65535).astype(np.uint16)).save(path)
    else:
        Image.fromarray(np.round(np.clip(array, 0, 1) * 255).astype(np.uint8)).save(path)
