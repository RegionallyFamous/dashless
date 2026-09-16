import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from native_memory import assess_memory, TASK_MEMORY_LIMIT_BYTES


class MemoryEvidenceTests(unittest.TestCase):
    def test_absent_and_nonmeasurements_fail(self):
        for peak in [None, 0, -1, False, '100', float('nan'), float('inf')]:
            with self.subTest(peak=peak):
                self.assertFalse(assess_memory({'peak_memory_bytes': peak, 'memory_method': 'platform_task_peak'})['passed'])

    def test_partial_and_unknown_methods_fail(self):
        for method in ['unavailable', 'getrusage', 'proc_tree_rss_sample_50ms', 'unknown', None]:
            with self.subTest(method=method):
                self.assertFalse(assess_memory({'peak_memory_bytes': 200_000_000, 'memory_method': method})['passed'])

    def test_whole_task_below_budget_passes(self):
        self.assertTrue(assess_memory({'peak_memory_bytes': 200_000_000, 'memory_method': 'platform_task_peak'})['passed'])

    def test_budget_boundary_fails(self):
        for peak in [TASK_MEMORY_LIMIT_BYTES, TASK_MEMORY_LIMIT_BYTES + 1]:
            self.assertFalse(assess_memory({'peak_memory_bytes': peak, 'memory_method': 'platform_task_peak'})['passed'])


if __name__ == '__main__':
    unittest.main()
