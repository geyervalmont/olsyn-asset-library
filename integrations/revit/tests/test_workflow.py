# -*- coding: utf-8 -*-
import json
import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from opal_client import Drive, UnixHost, Workflow  # noqa: E402
from fakes import FakeApi, sha  # noqa: E402


class WorkflowTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.mount = os.path.join(self.tmp, "mnt")
        self.api = FakeApi()
        self.drive = Drive("studio-share", self.mount, "/materials")
        self.bytes = {"base": b"base-bytes", "gloss": b"gloss-bytes", "bump": b"bump-bytes", "pbr": b"pbr-base"}
        self.api.files = [
            self._entry("revit", "2k", "base_color", "CPT-TARKETT-ACADEMIX-ASHEN_base_color.png", self.bytes["base"]),
            self._entry("revit", "2k", "glossiness", "CPT-TARKETT-ACADEMIX-ASHEN_glossiness.png", self.bytes["gloss"]),
            self._entry("revit", "2k", "bump", "CPT-TARKETT-ACADEMIX-ASHEN_bump.png", self.bytes["bump"]),
            self._entry("pbr", "4k", "base_color", "CPT-TARKETT-ACADEMIX-ASHEN_base_color.png", self.bytes["pbr"], folder="pbr"),
        ]
        for entry, data in zip(self.api.files, [self.bytes["base"], self.bytes["gloss"], self.bytes["bump"], self.bytes["pbr"]]):
            local = self.drive.local_path(entry["path"])
            os.makedirs(os.path.dirname(local), exist_ok=True)
            with open(local, "wb") as handle:
                handle.write(data)
        self.project = os.path.join(self.tmp, "project.json")
        self.host = UnixHost(self.project)
        self.host.add_material("mat-1", "Carpet - Academix Ashen", {"Description": "old"})
        self.host.add_material("mat-2", "Concrete generic")

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def _entry(self, target, quality, role, name, data, folder=None):
        return {"path": "/materials/Carpet/Academix/Ashen/%s/%s" % (folder or target, name), "target": target, "quality": quality, "role": role, "sha256": sha(data), "bytes": len(data), "mime_type": "image/png"}

    def test_drive_maps_paths_and_verifies_hashes(self):
        self.assertTrue(self.drive.mounted())
        local = self.drive.local_path("/materials/Carpet/Academix/Ashen/revit/x.png")
        self.assertEqual(local, os.path.join(self.mount, "materials", "Carpet", "Academix", "Ashen", "revit", "x.png"))
        report = self.drive.check(self.api.files)
        self.assertTrue(all(r["present"] and r["hash_matches"] for r in report))

    def test_sync_resolves_by_name_and_reports_unmatched(self):
        report = Workflow(self.api, self.host, self.drive).sync()
        self.assertEqual(report.summary(), "1 matched, 1 unmatched")
        material, variant, reference = report.matched[0]
        self.assertEqual(variant["code"], "CPT-TARKETT-ACADEMIX-ASHEN")
        self.assertEqual(reference, "Carpet - Academix Ashen")
        self.assertEqual(report.unmatched[0].name, "Concrete generic")

    def test_plan_prefers_revit_target_and_maps_slots(self):
        workflow = Workflow(self.api, self.host, self.drive)
        plan = workflow.plan(self.host.material("mat-1"), "CPT-TARKETT-ACADEMIX-ASHEN")
        self.assertTrue(plan.ready(), plan.describe())
        self.assertEqual(plan.target, "revit")
        self.assertEqual(plan.quality, "2k")
        self.assertEqual(sorted(plan.textures), ["base_color", "bump", "glossiness"])
        self.assertTrue(plan.textures["base_color"].endswith("_base_color.png"))
        self.assertEqual(plan.scale_mm, 500.0)
        self.assertEqual(plan.parameters["Model"], "CPT-TARKETT-ACADEMIX-ASHEN")
        self.assertIn("[CPT-TARKETT-ACADEMIX-ASHEN]", plan.parameters["Description"])

    def test_plan_reports_missing_and_mismatched_files(self):
        os.remove(self.drive.local_path(self.api.files[1]["path"]))
        with open(self.drive.local_path(self.api.files[2]["path"]), "wb") as handle:
            handle.write(b"tampered")
        plan = Workflow(self.api, self.host, self.drive).plan(self.host.material("mat-1"), "CPT-TARKETT-ACADEMIX-ASHEN")
        self.assertFalse(plan.ready())
        self.assertEqual([m["role"] for m in plan.missing], ["glossiness"])
        self.assertEqual([m["role"] for m in plan.mismatched], ["bump"])
        with self.assertRaises(RuntimeError):
            Workflow(self.api, self.host, self.drive).apply(plan)

    def test_plan_falls_back_to_canonical_and_refuses_unmounted_drive(self):
        self.api.files = [f for f in self.api.files if f["target"] == "pbr"]
        plan = Workflow(self.api, self.host, self.drive).plan(self.host.material("mat-1"), "CPT-TARKETT-ACADEMIX-ASHEN")
        self.assertEqual(plan.target, "pbr")
        self.assertEqual(sorted(plan.textures), ["base_color"])

        unmounted = Drive("studio-share", os.path.join(self.tmp, "nowhere"), "/materials")
        plan = Workflow(self.api, self.host, unmounted).plan(self.host.material("mat-1"), "CPT-TARKETT-ACADEMIX-ASHEN")
        self.assertFalse(plan.ready())
        self.assertIn("not mounted", plan.problems[0])

    def test_apply_writes_textures_parameters_and_identity(self):
        workflow = Workflow(self.api, self.host, self.drive)
        plan = workflow.plan(self.host.material("mat-1"), "CPT-TARKETT-ACADEMIX-ASHEN")
        identity = workflow.apply(plan)

        self.assertEqual(identity["external_id"], "mat-1")
        self.assertEqual(self.api.identities[0]["platform"], "revit")
        self.assertEqual(self.api.identities[0]["payload"]["document"], "project.json")

        with open(self.project) as handle:
            saved = json.load(handle)
        record = saved["materials"][0]
        self.assertEqual(record["scale_mm"], 500.0)
        self.assertEqual(record["parameters"]["Model"], "CPT-TARKETT-ACADEMIX-ASHEN")
        self.assertTrue(record["textures"]["base_color"].endswith("_base_color.png"))

        # The next sync resolves the same material through its written-back code.
        report = Workflow(self.api, UnixHost(self.project), self.drive).sync()
        self.assertEqual(report.matched[0][2], "mat-1")


if __name__ == "__main__":
    unittest.main()
