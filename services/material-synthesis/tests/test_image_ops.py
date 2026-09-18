import tempfile
import unittest
from pathlib import Path

import numpy as np
from PIL import Image

from image_ops import prepare, normalize_normals, height_from_normals, encode_png


class ImageOperationsTest(unittest.TestCase):
    def test_crop_selects_the_requested_part_without_stretching(self):
        image = Image.new('RGB', (200, 100), 'red')
        image.paste('blue', (100, 0, 200, 100))
        sample = prepare(image, {'x': 100, 'y': 0, 'size': 100}, 64)
        self.assertEqual(sample.size, (64, 64))
        self.assertEqual(sample.getpixel((32, 32)), (0, 0, 255))

    def test_normal_integration_recovers_a_periodic_surface(self):
        x, y = np.meshgrid(np.arange(64), np.arange(64))
        height = np.sin(2 * np.pi * x / 64) + 0.4 * np.cos(2 * np.pi * y / 64)
        dx = (2 * np.pi / 64) * np.cos(2 * np.pi * x / 64)
        dy = -0.4 * (2 * np.pi / 64) * np.sin(2 * np.pi * y / 64)
        normal = np.stack([-dx, dy, np.ones_like(dx)], axis=-1)
        normal /= np.linalg.norm(normal, axis=-1, keepdims=True)
        actual = height_from_normals((normal + 1) / 2)
        self.assertGreater(np.corrcoef(height.flatten(), actual.flatten())[0, 1], 0.999)
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'height.png'
            encode_png(actual, path, height=True)
            self.assertEqual(path.read_bytes()[24], 16)
            self.assertGreater(np.unique(np.asarray(Image.open(path))).size, 256)

    def test_flat_normals_produce_neutral_finite_height(self):
        normal = np.broadcast_to([0.5, 0.5, 1.0], (32, 32, 3))
        height = height_from_normals(normal)
        np.testing.assert_allclose(height, 0.5)
        output = normalize_normals(normal)
        np.testing.assert_allclose(np.linalg.norm(output * 2 - 1, axis=-1), 1)


if __name__ == '__main__':
    unittest.main()
