# -*- coding: utf-8 -*-
"""A mounted OPAL drive: the PrismFS namespace as the client machine sees it."""

import hashlib
import os


class Drive(object):
    """
    Maps API drive paths (`/materials/Carpet/...`) onto a local mount point or
    UNC root, and verifies what is actually there.
    """

    def __init__(self, slug, mount_root, root_path="/materials"):
        self.slug = slug
        self.mount_root = mount_root
        self.root_path = "/" + root_path.strip("/")

    def local_path(self, drive_path):
        """`/materials/Carpet/x.png` on a mount at `M:\\` → `M:\\materials\\Carpet\\x.png`."""
        relative = drive_path.lstrip("/")
        parts = [part for part in relative.split("/") if part]
        return os.path.join(self.mount_root, *parts)

    def exists(self, drive_path):
        return os.path.isfile(self.local_path(drive_path))

    def sha256(self, drive_path):
        digest = hashlib.sha256()
        with open(self.local_path(drive_path), "rb") as handle:
            while True:
                chunk = handle.read(1024 * 1024)
                if not chunk:
                    break
                digest.update(chunk)
        return digest.hexdigest()

    def check(self, entries):
        """
        For API path entries, report which are present and whether their
        bytes match the library's hash. Returns a list of dicts.
        """
        report = []
        for entry in entries:
            path = entry["path"]
            present = self.exists(path)
            matches = None
            if present and entry.get("sha256"):
                matches = self.sha256(path) == entry["sha256"]
            report.append({
                "path": path,
                "local_path": self.local_path(path),
                "role": entry.get("role"),
                "target": entry.get("target"),
                "quality": entry.get("quality"),
                "present": present,
                "hash_matches": matches,
            })
        return report

    def mounted(self):
        return os.path.isdir(os.path.join(self.mount_root, self.root_path.strip("/")))
