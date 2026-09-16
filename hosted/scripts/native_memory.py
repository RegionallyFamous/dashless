"""Fail closed unless native-build memory evidence covers the entire task."""
import math

TASK_MEMORY_LIMIT_BYTES = 1_200_000_000
# These labels are an evidence contract, not a claim that a collector exists.
# Existing Node-tree sampling and process rusage exclude other task processes.
TASK_METHODS = {'platform_task_peak', 'cgroup_memory_peak', 'task_tree_rss_sample_50ms'}


def assess_memory(job):
    peak = job.get('peak_memory_bytes')
    method = job.get('memory_method', 'unavailable')
    valid_number = (isinstance(peak, (int, float)) and not isinstance(peak, bool)
                    and math.isfinite(peak) and peak > 0)
    if not valid_number:
        reason = 'No positive measured peak was reported.'
    elif method not in TASK_METHODS:
        reason = 'Measurement does not establish whole-task memory usage.'
    elif peak >= TASK_MEMORY_LIMIT_BYTES:
        reason = 'Measured memory reached or exceeded the task budget.'
    else:
        reason = None
    return {'passed': reason is None, 'peak_memory_bytes': peak,
            'memory_method': method, 'limit_bytes': TASK_MEMORY_LIMIT_BYTES,
            'reason': reason}
