#!/usr/bin/env python3
"""Behavioral release tests using isolated, disposable Git repositories."""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
import warnings
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('lucky_release', ROOT / 'tools/release.py')
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)
MANIFEST = {'version': 'V5Q32G1', 'installerVersion': '5.32.7.1',
            'sdkVersion': 'V5Q24', 'section': 'Isolated packaging test'}


class PackagingTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='lucky-release-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'source'
        self.root.mkdir()
        self.output = Path(self.temp.name) / 'output'
        self.git('init', '-q')
        self.git('config', 'user.name', 'Release Test')
        self.git('config', 'user.email', 'release-test@example.invalid')
        for name in release.REQUIRED + release.SDK_FILES:
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('fixture ' + name + '\n', encoding='utf-8')
        (self.root / 'release.json').write_bytes(release.encoded(MANIFEST))
        for name in ('tests/fixture.php', '.github/workflows/fixture.yml', '.gitignore'):
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('development-only\n', encoding='utf-8')
        self.commit()

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.root), *args], check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15).stdout.decode().strip()

    def commit(self):
        self.git('add', '--all')
        self.git('commit', '-qm', 'isolated fixture')
        self.sha = self.git('rev-parse', 'HEAD')

    def build(self):
        return release.build(self.root, self.output, self.sha)

    def rewrite_zip(self, transform):
        path = self.output / release.names(MANIFEST)['deploy']
        with zipfile.ZipFile(path) as archive:
            entries = {name: archive.read(name) for name in archive.namelist()}
        transform(entries)
        release.write_zip(path, entries)

    def refresh_hashes(self):
        metadata_path = self.output / 'BUILD_INFO.json'
        metadata = json.loads(metadata_path.read_text())
        for name in metadata['artifacts']:
            metadata['artifacts'][name] = release.digest(self.output / name)
        metadata_path.write_bytes(release.encoded(metadata))
        hashes = metadata['artifacts']
        (self.output / 'SHA256SUMS.txt').write_text(''.join(f'{hashes[name]}  {name}\n' for name in sorted(hashes)))

    def test_single_extraction_installs_at_root_and_preserves_existing_data(self):
        config = self.root / 'storage/config.php'
        config.write_text('PRIVATE DATABASE SETTINGS')
        media = self.root / 'storage/claim-media/proof.webm'
        media.parent.mkdir(parents=True, exist_ok=True)
        media.write_bytes(b'PRIVATE UPLOADED RECORDING')
        result = self.build()
        installed = Path(self.temp.name) / 'installed'
        (installed / 'storage/claim-media').mkdir(parents=True)
        installed_config = installed / 'storage/config.php'
        installed_config.write_text('KEEP MY LIVE CONFIG')
        installed_media = installed / 'storage/claim-media/proof.webm'
        installed_media.write_bytes(b'KEEP MY LIVE RECORDING')
        with zipfile.ZipFile(self.output / result['deploy']) as archive:
            self.assertIn('index.php', archive.namelist())
            self.assertIn('api/director-live.php', archive.namelist())
            self.assertNotIn('storage/config.php', archive.namelist())
            self.assertNotIn('storage/claim-media/proof.webm', archive.namelist())
            self.assertNotIn('tests/fixture.php', archive.namelist())
            self.assertNotIn('.github/workflows/fixture.yml', archive.namelist())
            self.assertNotIn('.gitignore', archive.namelist())
            archive.extractall(installed)  # Only our validated, locally generated fixture ZIP.
        self.assertTrue((installed / 'index.php').is_file())
        self.assertEqual(installed_config.read_text(), 'KEEP MY LIVE CONFIG')
        self.assertEqual(installed_media.read_bytes(), b'KEEP MY LIVE RECORDING')
        self.assertEqual(json.loads((installed / 'build-info.json').read_text()),
                         {'version': MANIFEST['version'], 'commit': self.sha,
                          'tree': self.git('rev-parse', 'HEAD^{tree}')})

    def test_same_commit_build_is_byte_reproducible(self):
        first = self.build()
        second = release.build(self.root, Path(self.temp.name) / 'second', self.sha)
        self.assertEqual(first, second)
        self.assertEqual(first['artifacts'], self.build()['artifacts'])

    def test_wrong_certified_commit_is_rejected(self):
        with self.assertRaisesRegex(release.ReleaseError, 'certified workflow commit'):
            release.build(self.root, self.output, '0' * 40)

    def test_tracked_uncommitted_changes_are_not_certified(self):
        (self.root / 'index.php').write_text('uncommitted change')
        with self.assertRaisesRegex(release.ReleaseError, 'Commit tracked changes'):
            self.build()

    def test_export_ignore_cannot_drop_committed_runtime_files(self):
        endpoint = 'api/nonrequired-important.php'
        expected = b'<?php echo "Required by an application feature";\n'
        (self.root / endpoint).write_bytes(expected)
        (self.root / '.gitattributes').write_text(endpoint + ' export-ignore\n')
        self.commit()
        result = self.build()
        with zipfile.ZipFile(self.output / result['deploy']) as archive:
            self.assertIn(endpoint, archive.namelist())
            self.assertEqual(archive.read(endpoint), expected)
            self.assertNotIn('.gitattributes', archive.namelist())

    def test_export_substitution_cannot_change_committed_runtime_bytes(self):
        endpoint = 'api/source-identity.php'
        expected = b'<?php $literal = "$Format:%H$";\n'
        (self.root / endpoint).write_bytes(expected)
        (self.root / '.gitattributes').write_text(endpoint + ' export-subst\n')
        self.commit()
        result = self.build()
        with zipfile.ZipFile(self.output / result['deploy']) as archive:
            self.assertEqual(archive.read(endpoint), expected)

    def test_git_submodule_is_rejected_instead_of_silently_omitted(self):
        # A gitlink is enough to exercise source mode handling; no clone,
        # remote URL, or execution of third-party module code is needed.
        (self.root / 'vendor/plugin').mkdir(parents=True)
        self.git('update-index', '--add', '--cacheinfo', '160000,' + self.sha + ',vendor/plugin')
        self.git('commit', '-qm', 'isolated gitlink fixture')
        self.sha = self.git('rev-parse', 'HEAD')
        with self.assertRaises(release.ReleaseError):
            self.build()

    @unittest.skipUnless(os.name == 'posix', 'POSIX symlink fixture')
    def test_tracked_symlink_is_rejected(self):
        (self.root / 'linked-index.php').symlink_to('index.php')
        self.commit()
        with self.assertRaises(release.ReleaseError):
            self.build()

    def test_tracked_runtime_config_is_rejected(self):
        (self.root / 'storage/config.php').write_text('secret')
        self.commit()
        with self.assertRaisesRegex(release.ReleaseError, 'Private runtime data'):
            self.build()

    def test_tracked_environment_secrets_are_rejected(self):
        (self.root / '.env.production').write_text('secret')
        self.commit()
        with self.assertRaisesRegex(release.ReleaseError, 'Environment/secret'):
            self.build()

    def test_runtime_index_file_outside_explicit_placeholders_is_rejected(self):
        path = self.root / 'storage/user-upload/index.html'
        path.parent.mkdir()
        path.write_text('private user page')
        self.commit()
        with self.assertRaisesRegex(release.ReleaseError, 'Private runtime data'):
            self.build()

    def test_tracked_stale_build_info_is_rejected(self):
        (self.root / 'build-info.json').write_text('{}')
        self.commit()
        with self.assertRaisesRegex(release.ReleaseError, 'generated'):
            self.build()

    def test_missing_required_application_file_is_rejected(self):
        (self.root / 'director.php').unlink()
        self.commit()
        with self.assertRaisesRegex(release.ReleaseError, 'Required deployment file'):
            self.build()

    def test_different_source_cannot_reuse_previous_output_directory(self):
        first = self.build()
        (self.root / 'index.php').write_text('new committed source')
        self.commit()
        with self.assertRaisesRegex(release.ReleaseError, 'different build'):
            self.build()
        self.assertEqual(release.verify(self.output)['commit'], first['commit'])

    def test_outer_wrapper_directory_is_rejected(self):
        self.build()
        def wrap(entries):
            changed = {'wrapper/' + name: data for name, data in entries.items()}
            entries.clear()
            entries.update(changed)
        self.rewrite_zip(wrap)
        with self.assertRaisesRegex(release.ReleaseError, 'directly at its root'):
            release.verify(self.output)

    def test_nested_zip_instead_of_application_files_is_rejected(self):
        result = self.build()
        path = self.output / result['deploy']
        inner = path.read_bytes()
        release.write_zip(path, {'inner.zip': inner})
        with self.assertRaisesRegex(release.ReleaseError, 'directly at its root'):
            release.verify(self.output)

    def test_tampered_application_bytes_are_rejected_by_digest(self):
        self.build()
        self.rewrite_zip(lambda entries: entries.update({'index.php': b'tampered'}))
        with self.assertRaisesRegex(release.ReleaseError, 'digest mismatch'):
            release.verify(self.output)

    def test_duplicate_deployment_zip_entries_are_rejected(self):
        result = self.build()
        with warnings.catch_warnings():
            warnings.simplefilter('ignore', UserWarning)
            with zipfile.ZipFile(self.output / result['deploy'], 'a') as archive:
                archive.writestr('index.php', b'duplicate entry')
        with self.assertRaisesRegex(release.ReleaseError, 'duplicate'):
            release.verify(self.output)

    def test_symlink_zip_entry_is_rejected_before_extraction(self):
        result = self.build()
        path = self.output / result['deploy']
        with zipfile.ZipFile(path) as archive:
            original = [(member, archive.read(member.filename)) for member in archive.infolist()]
        with zipfile.ZipFile(path, 'w') as archive:
            for member, content in original:
                if member.filename == 'index.php':
                    member.external_attr = (stat.S_IFLNK | 0o777) << 16
                    content = b'../outside/config.php'
                archive.writestr(member, content)
        with self.assertRaisesRegex(release.ReleaseError, 'regular files'):
            release.verify(self.output)

    def test_tampered_embedded_build_identity_is_rejected(self):
        self.build()
        self.rewrite_zip(lambda entries: entries.update({'build-info.json': release.encoded(
            {'version': MANIFEST['version'], 'commit': '0' * 40, 'tree': '1' * 40})}))
        with self.assertRaisesRegex(release.ReleaseError, 'identity disagrees'):
            release.verify(self.output)

    def test_tampered_checksum_inventory_is_rejected(self):
        self.build()
        path = self.output / 'BUILD_INFO.json'
        metadata = json.loads(path.read_text())
        metadata['artifacts'].pop(release.names(MANIFEST)['sdk'])
        path.write_bytes(release.encoded(metadata))
        with self.assertRaisesRegex(release.ReleaseError, 'checksum inventory'):
            release.verify(self.output)

    def test_tampered_human_build_notes_are_rejected(self):
        self.build()
        (self.output / 'BUILD_INFO.txt').write_text('different source')
        with self.assertRaisesRegex(release.ReleaseError, 'Human-readable'):
            release.verify(self.output)

    def test_verifier_requires_requested_commit(self):
        self.build()
        with self.assertRaisesRegex(release.ReleaseError, 'certified source commit'):
            release.verify(self.output, '0' * 40)

    def test_sdk_must_match_application_even_with_updated_checksum(self):
        result = self.build()
        release.write_zip(self.output / result['sdk'], {name: b'wrong source' for name in release.SDK_FILES})
        self.refresh_hashes()
        with self.assertRaisesRegex(release.ReleaseError, 'differs from the certified'):
            release.verify(self.output)

    def test_unsafe_paths_are_rejected(self):
        for name in ('../config.php', '/index.php', 'folder/../index.php',
                     'folder\\index.php', 'C:/index.php', './index.php', ''):
            with self.subTest(name=name), self.assertRaises(release.ReleaseError):
                release.safe_path(name)

    def test_manifest_and_filenames_use_one_version(self):
        value = release.names(MANIFEST)
        self.assertEqual(value['deploy'], 'Lucky_Game_Hub_V5Q32G1_Full_Deploy.zip')
        self.assertEqual(value['installer'], 'Lucky_Game_Hub_HomeServer_V5Q32G1_Setup.exe')
        self.assertEqual(value['tag'], 'v5q32g1')
        for version in ('../release', '<script>', 'Vwith spaces', ''):
            with self.subTest(version=version), self.assertRaises(release.ReleaseError):
                release.manifest({**MANIFEST, 'version': version})
        with self.assertRaises(release.ReleaseError):
            release.manifest({**MANIFEST, 'installerVersion': '5.1.0.65536'})


if __name__ == '__main__':
    unittest.main(verbosity=2)
