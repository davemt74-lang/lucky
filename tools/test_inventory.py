#!/usr/bin/env python3
"""Validate and run Lucky's explicit regression inventory (standard library only).

Every executable tests/*.{php,py,js} belongs to exactly one CI group. Fixtures
belong in tests/fixtures/, not alongside executable suites. No test is silently
skipped when its runtime is unavailable or its timeout expires.

  python3 tools/test_inventory.py --check
  python3 tools/test_inventory.py --group core
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import time


ROOT = Path(__file__).resolve().parents[1]
GROUPS = ("core", "database", "browsers", "video")
SUFFIXES = {".php", ".py", ".js"}
TEST_PATH = re.compile(r"tests/[A-Za-z0-9][A-Za-z0-9_.-]*\.(?:php|py|js)\Z")


class InventoryError(ValueError):
    """The inventory cannot safely or completely describe the repository."""


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise InventoryError("Duplicate JSON key: " + key)
        result[key] = value
    return result


def seconds(value, label):
    if type(value) is not int or not 1 <= value <= 3600:
        raise InventoryError(label + " must be an integer between 1 and 3600 seconds")
    return value


def load_inventory(root=ROOT, manifest=None):
    """Return normalized groups after validating complete, unique membership."""
    root = Path(root).resolve()
    manifest = Path(manifest) if manifest is not None else root / "tests/suites.json"
    if not manifest.is_absolute():
        manifest = root / manifest
    try:
        data = json.loads(manifest.read_text(encoding="utf-8"), object_pairs_hook=unique_object)
    except (OSError, json.JSONDecodeError) as error:
        raise InventoryError(f"Cannot read inventory {manifest}: {error}") from error
    if not isinstance(data, dict) or set(data) != {"version", "groups"}:
        raise InventoryError("Inventory must contain only version and groups")
    if type(data["version"]) is not int or data["version"] != 1:
        raise InventoryError("Unsupported inventory version (expected 1)")
    groups = data["groups"]
    if not isinstance(groups, dict) or set(groups) != set(GROUPS):
        raise InventoryError("Inventory must define core, database, browsers, and video groups")
    tests_dir = root / "tests"
    if not tests_dir.is_dir() or tests_dir.is_symlink():
        raise InventoryError("Repository tests directory is missing or is a symlink")
    executable = set()
    for path in tests_dir.iterdir():
        if path.suffix not in SUFFIXES:
            continue
        if not path.is_file() or path.is_symlink():
            raise InventoryError("Executable test must be a regular file: tests/" + path.name)
        executable.add("tests/" + path.name)
    if not executable:
        raise InventoryError("No executable regression suites found")
    assigned = {}
    normalized = {}
    for group in GROUPS:
        definition = groups[group]
        if not isinstance(definition, dict) or set(definition) != {"timeout_seconds", "tests"}:
            raise InventoryError(group + " must contain timeout_seconds and tests")
        timeout = seconds(definition["timeout_seconds"], group + " timeout")
        if not isinstance(definition["tests"], list):
            raise InventoryError(group + " tests must be a list")
        normalized[group] = []
        for item in definition["tests"]:
            test_timeout = timeout
            if isinstance(item, str):
                path = item
            elif isinstance(item, dict) and set(item) == {"path", "timeout_seconds"}:
                path = item["path"]
                test_timeout = seconds(item["timeout_seconds"], str(path) + " timeout")
            else:
                raise InventoryError(group + " contains an invalid test entry")
            if not isinstance(path, str) or TEST_PATH.fullmatch(path) is None:
                raise InventoryError("Invalid root-level test path: " + repr(path))
            if path in assigned:
                raise InventoryError(f"Duplicate assignment: {path} ({assigned[path]} and {group})")
            assigned[path] = group
            if path not in executable:
                raise InventoryError("Registered test is missing: " + path)
            normalized[group].append({"path": path, "timeout_seconds": test_timeout})
    unregistered = sorted(executable - assigned.keys())
    if unregistered:
        raise InventoryError("Unregistered executable tests: " + ", ".join(unregistered))
    return normalized


def command_for(path):
    suffix = Path(path).suffix
    if suffix == ".php":
        return ["php", path]
    if suffix == ".py":
        return [sys.executable, "-u", path]
    if suffix == ".js":
        # node:test also fails tests left pending after the event loop exits.
        return ["node", "--test", path]
    raise InventoryError("Unsupported test runtime: " + path)


def stop_process(process):
    """Timeout cleanup includes browser/FFmpeg children on POSIX CI runners."""
    if os.name == "posix":
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            process.wait(timeout=2)
        except subprocess.TimeoutExpired:
            pass
        # The test may exit before its browser children; kill the whole group.
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
    else:
        process.kill()
    process.wait()


def run_group(root, group, entries, fail_fast=False):
    root = Path(root).resolve()
    if (root / "storage/config.php").exists():
        raise InventoryError("Refusing tests in an installed application; use an isolated clean checkout")
    failures = []
    ran = 0
    for index, entry in enumerate(entries, 1):
        path = entry["path"]
        timeout = entry["timeout_seconds"]
        print(f"[{group} {index}/{len(entries)}] {path} (timeout {timeout}s)", flush=True)
        started = time.monotonic()
        ran += 1
        try:
            process = subprocess.Popen(
                command_for(path), cwd=root, stdin=subprocess.DEVNULL,
                start_new_session=(os.name == "posix"),
            )
            try:
                code = process.wait(timeout=timeout)
                result = "PASS" if code == 0 else f"FAIL (exit {code})"
            except subprocess.TimeoutExpired:
                stop_process(process)
                code = 124
                result = f"FAIL (timeout after {timeout}s)"
            except BaseException:
                stop_process(process)
                raise
        except OSError as error:
            code = 127
            result = f"FAIL (cannot start runtime: {error})"
        elapsed = time.monotonic() - started
        print(f"{result}: {path} [{elapsed:.2f}s]", flush=True)
        if code != 0:
            failures.append(path)
            if fail_fast:
                break
    print(f"{group}: {ran - len(failures)}/{ran} suites passed; "
          f"{len(entries) - ran} not run", flush=True)
    if failures:
        print("Failed suites: " + ", ".join(failures), file=sys.stderr)
    return 1 if failures else 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    action = parser.add_mutually_exclusive_group()
    action.add_argument("--check", action="store_true", help="validate membership only (default)")
    action.add_argument("--group", choices=GROUPS, help="run every test in this group")
    parser.add_argument("--root", type=Path, default=ROOT, help="isolated repository root")
    parser.add_argument("--manifest", type=Path, help="inventory path, relative to root or absolute")
    parser.add_argument("--fail-fast", action="store_true", help="stop after the first failed suite")
    args = parser.parse_args(argv)
    try:
        inventory = load_inventory(args.root, args.manifest)
        counts = ", ".join(f"{name}={len(inventory[name])}" for name in GROUPS)
        print(f"Inventory verified: {sum(map(len, inventory.values()))} suites ({counts})", flush=True)
        if args.group:
            return run_group(args.root, args.group, inventory[args.group], args.fail_fast)
        return 0
    except InventoryError as error:
        print("TEST INVENTORY ERROR: " + str(error), file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        print("Test run interrupted; no passing result recorded.", file=sys.stderr)
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
