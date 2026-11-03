"""Regression tests for complete suite registration and honest runner results."""
import contextlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
import test_inventory as inventory


class InventoryTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="lucky-test-inventory-")
        self.root = Path(self.temporary.name)
        (self.root / "tests").mkdir()
        self.manifest = self.root / "tests/suites.json"
        self.groups = {name: {"timeout_seconds": 30, "tests": []} for name in inventory.GROUPS}
        self.add("example.py", "raise SystemExit(0)\n")

    def tearDown(self):
        self.temporary.cleanup()

    def add(self, name, source, group="core"):
        path = "tests/" + name
        (self.root / path).write_text(source, encoding="utf-8")
        self.groups[group]["tests"].append(path)
        self.save()
        return path

    def save(self):
        self.manifest.write_text(json.dumps({"version": 1, "groups": self.groups}), encoding="utf-8")

    def load(self):
        return inventory.load_inventory(self.root)

    def run_core(self, fail_fast=False):
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            return inventory.run_group(self.root, "core", self.load()["core"], fail_fast)

    def test_valid_inventory_and_per_test_timeout(self):
        self.groups["core"]["tests"] = [{"path": "tests/example.py", "timeout_seconds": 7}]
        self.save()
        self.assertEqual(self.load()["core"], [{"path": "tests/example.py", "timeout_seconds": 7}])

    def test_unregistered_file_is_error(self):
        (self.root / "tests/forgotten.php").write_text("<?php\n", encoding="utf-8")
        with self.assertRaisesRegex(inventory.InventoryError, "Unregistered.*forgotten.php"):
            self.load()

    def test_missing_registered_file_is_error(self):
        (self.root / "tests/example.py").unlink()
        (self.root / "tests/other.py").write_text("", encoding="utf-8")
        with self.assertRaisesRegex(inventory.InventoryError, "Registered test is missing"):
            self.load()

    def test_duplicate_within_group_is_error(self):
        self.groups["core"]["tests"].append("tests/example.py")
        self.save()
        with self.assertRaisesRegex(inventory.InventoryError, "Duplicate assignment"):
            self.load()

    def test_duplicate_between_groups_is_error(self):
        self.groups["video"]["tests"].append("tests/example.py")
        self.save()
        with self.assertRaisesRegex(inventory.InventoryError, "Duplicate assignment"):
            self.load()

    def test_duplicate_json_keys_are_error(self):
        self.manifest.write_text('{"version":1,"version":1,"groups":{}}', encoding="utf-8")
        with self.assertRaisesRegex(inventory.InventoryError, "Duplicate JSON key"):
            self.load()

    def test_manifest_version_and_group_shape_are_checked(self):
        self.manifest.write_text('{"version":true,"groups":{}}', encoding="utf-8")
        with self.assertRaisesRegex(inventory.InventoryError, "Unsupported inventory version"):
            self.load()
        self.groups.pop("video")
        self.save()
        with self.assertRaisesRegex(inventory.InventoryError, "must define"):
            self.load()

    def test_paths_cannot_escape_or_reach_fixture_directories(self):
        for path in ("../example.py", "tests/../example.py", "tests/fixtures/helper.py", "/tests/example.py"):
            with self.subTest(path=path):
                self.groups["core"]["tests"] = [path]
                self.save()
                with self.assertRaisesRegex(inventory.InventoryError, "Invalid root-level test path"):
                    self.load()

    def test_timeout_must_be_bounded_integer_not_bool(self):
        for timeout in (0, -1, 3601, True, 1.5, "30"):
            with self.subTest(timeout=timeout):
                self.groups["core"]["timeout_seconds"] = timeout
                self.save()
                with self.assertRaisesRegex(inventory.InventoryError, "integer between"):
                    self.load()

    def test_fixtures_and_nonexecutables_are_not_counted(self):
        fixture_dir = self.root / "tests/fixtures"
        fixture_dir.mkdir()
        (fixture_dir / "sample.py").write_text("not a suite", encoding="utf-8")
        (self.root / "tests/README.md").write_text("Documentation", encoding="utf-8")
        self.assertEqual(len(self.load()["core"]), 1)

    @unittest.skipUnless(os.name == "posix", "POSIX symlink behavior")
    def test_executable_symlink_is_rejected(self):
        (self.root / "tests/link.py").symlink_to(self.root / "tests/example.py")
        with self.assertRaisesRegex(inventory.InventoryError, "regular file"):
            self.load()

    def test_runtime_commands_use_node_test_and_current_python(self):
        self.assertEqual(inventory.command_for("tests/example.js"), ["node", "--test", "tests/example.js"])
        self.assertEqual(inventory.command_for("tests/example.php"), ["php", "tests/example.php"])
        self.assertEqual(inventory.command_for("tests/example.py"), [sys.executable, "-u", "tests/example.py"])

    def test_success_and_nonzero_results_propagate(self):
        self.assertEqual(self.run_core(), 0)
        self.add("fails.py", "raise SystemExit(7)\n")
        self.assertEqual(self.run_core(), 1)

    def test_missing_runtime_is_failure_not_skip(self):
        with mock.patch.object(inventory.subprocess, "Popen", side_effect=FileNotFoundError("runtime unavailable")):
            self.assertEqual(self.run_core(), 1)

    def test_timeout_is_failure_not_success(self):
        (self.root / "tests/example.py").write_text("import time\ntime.sleep(60)\n", encoding="utf-8")
        self.groups["core"]["timeout_seconds"] = 1
        self.save()
        self.assertEqual(self.run_core(), 1)

    def test_fail_fast_does_not_run_later_suites(self):
        (self.root / "tests/example.py").write_text("raise SystemExit(3)\n", encoding="utf-8")
        self.add("later.py", "from pathlib import Path\nPath('executed').write_text('yes')\n")
        self.assertEqual(self.run_core(fail_fast=True), 1)
        self.assertFalse((self.root / "executed").exists())
        self.assertEqual(self.run_core(), 1)
        self.assertTrue((self.root / "executed").exists())

    def test_installed_application_is_never_used_for_test_execution(self):
        (self.root / "storage").mkdir()
        (self.root / "storage/config.php").write_text("<?php return [];", encoding="utf-8")
        self.load()  # Read-only inventory verification remains safe.
        with self.assertRaisesRegex(inventory.InventoryError, "installed application"):
            self.run_core()

    def test_cli_failure_exit_status(self):
        result = subprocess.run(
            [sys.executable, str(ROOT / "tools/test_inventory.py"), "--root", str(self.root), "--check"],
            capture_output=True, text=True, timeout=10,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        (self.root / "tests/forgotten.js").write_text("", encoding="utf-8")
        result = subprocess.run(
            [sys.executable, str(ROOT / "tools/test_inventory.py"), "--root", str(self.root), "--check"],
            capture_output=True, text=True, timeout=10,
        )
        self.assertEqual(result.returncode, 2)
        self.assertIn("forgotten.js", result.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)
