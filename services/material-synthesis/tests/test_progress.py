"""Exercise the worker's authenticated progress contract without a GPU."""
import unittest
from unittest.mock import Mock, patch

from worker import Worker, Cancelled


class ProgressTest(unittest.TestCase):
    def test_map_counts_are_sent_with_stage_and_role(self):
        with patch.dict('os.environ', {'OPAL_URL': 'https://opal.test', 'OPAL_RUN': 'run', 'OPAL_TOKEN': 'test'}):
            worker = Worker()
        worker.request = Mock()
        worker.progress('estimating_material', completed=2, total=4, role='roughness')
        worker.request.assert_called_once_with('POST', '/progress', json={
            'stage': 'estimating_material', 'completed': 2, 'total': 4, 'role': 'roughness',
        })
        self.assertEqual(worker.stage, 'estimating_material')
        worker.cancelled.set()
        with self.assertRaises(Cancelled):
            worker.progress('uploading_maps', completed=0, total=5, role='base_color')
        self.assertEqual(worker.request.call_count, 1)
