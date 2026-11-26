"""Process-local, bounded embeddings; never an issue index or authorization source."""
from collections import OrderedDict
from contextlib import contextmanager
from hashlib import sha256
import json
from threading import Lock
from time import monotonic

import torch


class EmbeddingCache:
    # Fixed limits keep deployment simple. Each Uvicorn process owns its own cache.
    def __init__(self, max_entries=10000, max_bytes=32 * 1024 * 1024,
                 ttl_seconds=900, wait_seconds=2, clock=monotonic):
        self.max_entries = max_entries
        self.max_bytes = max_bytes
        self.ttl_seconds = ttl_seconds
        self.wait_seconds = wait_seconds
        self.clock = clock
        self._lock = Lock()
        self._entries = OrderedDict()
        self._bytes = 0
        self._tensor_bytes = 0
        self._counts = dict(hits=0, misses=0, evictions=0, expirations=0, replacements=0)

    @contextmanager
    def inference(self):
        # Single-flight model access prevents concurrent cache fills/CPU oversubscription.
        # Bound waiting: existing generic 503 response makes overload retryable.
        if not self._lock.acquire(timeout=self.wait_seconds):
            raise TimeoutError("NLP inference capacity is busy.")
        try:
            yield
        finally:
            self._lock.release()

    @staticmethod
    def digest(value):
        return sha256(json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode()).digest()

    def _remove(self, key):
        _, vector, _, cost = self._entries.pop(key)
        self._bytes -= cost
        self._tensor_bytes -= vector.numel() * vector.element_size()

    def clear(self):
        """In-process maintenance/testing only; not exposed through the service API."""
        with self.inference():
            self._entries.clear()
            self._bytes = self._tensor_bytes = 0
            self._counts = dict.fromkeys(self._counts, 0)

    def stats(self):
        """Aggregate counters only. No IDs, content or vectors in diagnostics."""
        with self.inference():
            return {**self._counts, "entries": len(self._entries),
                    "tensor_bytes": self._tensor_bytes, "accounted_bytes": self._bytes,
                    "max_entries": self.max_entries, "max_bytes": self.max_bytes}

    def encode(self, model, model_identity, project_id, issues, combine_text):
        with self.inference():
            now = self.clock()
            for key, entry in list(self._entries.items()):
                if now - entry[2] >= self.ttl_seconds:
                    self._remove(key)
                    self._counts["expirations"] += 1

            keys, resolved, missing = [], {}, OrderedDict()
            for issue in issues:
                # Match existing str/int project/issue identity semantics. Hash exact
                # validated fields separately, preserving the existing text normalization.
                identity = self.digest([model_identity, str(project_id), str(issue.issue_id)])
                content = self.digest([issue.title, issue.description])
                key = (identity, content)
                keys.append(key)
                entry = self._entries.get(identity)
                if entry is not None and entry[0] == content:
                    self._counts["hits"] += 1
                    self._entries.move_to_end(identity)
                    resolved[key] = entry[1]
                else:
                    self._counts["misses"] += 1
                    missing[key] = issue

            if missing:
                # Same model/batch size/default precision as baseline; only encode misses.
                encoded = model.encode([combine_text(issue) for issue in missing.values()],
                                       convert_to_tensor=True, show_progress_bar=False)
                if len(encoded) != len(missing) or not torch.isfinite(encoded).all():
                    raise ValueError("Invalid model embeddings.")
                for key, row in zip(missing, encoded):
                    # A detached clone owns one vector, not the entire batch storage.
                    vector = row.detach().cpu().clone()
                    resolved[key] = vector
                    identity, content = key
                    tensor_bytes = vector.numel() * vector.element_size()
                    # Fixed-size hashes + tensor + conservative metadata allowance.
                    # Entry cap additionally bounds Python object/allocator overhead.
                    cost = tensor_bytes + 1024
                    if identity in self._entries:
                        self._remove(identity)
                        self._counts["replacements"] += 1
                    if self.max_entries <= 0 or cost > self.max_bytes:
                        continue
                    while self._entries and (len(self._entries) >= self.max_entries
                                             or self._bytes + cost > self.max_bytes):
                        self._remove(next(iter(self._entries)))
                        self._counts["evictions"] += 1
                    self._entries[identity] = (content, vector, self.clock(), cost)
                    self._bytes += cost
                    self._tensor_bytes += tensor_bytes
            return torch.stack([resolved[key] for key in keys])
