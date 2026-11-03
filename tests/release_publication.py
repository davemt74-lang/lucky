#!/usr/bin/env python3
"""Immutable publication tests. Every Git/GitHub subprocess is mocked; no network."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import publish_release as publisher
import release

COMMIT = 'a' * 40
TREE = 'b' * 40
SPEC = {'version': 'V9Q1', 'installerVersion': '9.1.0.0',
        'sdkVersion': 'V9Q1', 'section': 'Publication fixture'}
ENV = {'GITHUB_REPOSITORY': publisher.REPOSITORY, 'GITHUB_REF': 'refs/heads/main',
       'GITHUB_SHA': COMMIT, 'GH_TOKEN': 'test-token-never-transmitted',
       'GITHUB_ACTIONS': 'true', 'GITHUB_EVENT_NAME': 'push'}


class MockProcesses:
    def __init__(self):
        self.calls = []
        self.mutations = []
        self.downloaded = set()
        self.record = None
        self.assets = {}
        self.next_asset = 101
        self.tag_object = None
        self.annotations = {}
        self.failures = {}
        self.corrupt_download = None
        self.upload_failure = False
        self.hide_draft_on_first_page = False
        self.publish_concurrently = False
        self.git_commit = COMMIT
        self.git_tree = TREE
        self.dirty = False
        source = {name: ('source fixture ' + name + '\n').encode()
                  for name in release.REQUIRED + release.SDK_FILES}
        source['release.json'] = release.encoded(SPEC)
        self.tree_entries = []
        self.blobs = {}
        for name, data in sorted(source.items()):
            object_id = hashlib.sha1(f'blob {len(data)}\0'.encode() + data).hexdigest()
            self.tree_entries.append((name, object_id))
            self.blobs[object_id] = data

    def response(self, args, value=None, status=200):
        raw = f'HTTP/2.0 {status} Test\r\nContent-Type: application/json\r\n\r\n'.encode()
        raw += json.dumps(value).encode()
        return subprocess.CompletedProcess(args, 0 if status < 400 else 1, stdout=raw, stderr=b'')

    def asset_list(self):
        return [{key: value for key, value in asset.items() if key != 'content'}
                for asset in self.assets.values()]

    def add_asset(self, name, content):
        asset_id = self.next_asset
        self.next_asset += 1
        self.assets[asset_id] = {'id': asset_id, 'name': name, 'size': len(content),
                                 'state': 'uploaded', 'digest': 'sha256:' + hashlib.sha256(content).hexdigest(),
                                 'content': content}

    def __call__(self, args, **kwargs):
        self.calls.append(list(args))
        if args[0] == 'git':
            operation = args[3:]
            if operation == ['rev-parse', 'HEAD']:
                data = self.git_commit.encode()
            elif operation == ['rev-parse', 'HEAD^{tree}']:
                data = self.git_tree.encode()
            elif operation == ['show', 'HEAD:release.json']:
                data = release.encoded(SPEC)
            elif operation == ['ls-tree', '-r', '-z', '--full-tree', COMMIT]:
                data = b''.join(f'100644 blob {object_id}\t{name}\0'.encode()
                                for name, object_id in self.tree_entries)
            elif operation == ['cat-file', '--batch']:
                parts = []
                for object_id in kwargs['input'].decode().splitlines():
                    content = self.blobs[object_id]
                    parts.append(f'{object_id} blob {len(content)}\n'.encode() + content + b'\n')
                data = b''.join(parts)
            elif operation == ['diff', '--quiet', 'HEAD', '--']:
                return subprocess.CompletedProcess(args, int(self.dirty), stdout=b'', stderr=b'')
            else:
                raise AssertionError(f'Unexpected Git subprocess: {args}')
            return subprocess.CompletedProcess(args, 0, stdout=data, stderr=b'')
        if args[:3] == ['gh', 'release', 'upload']:
            assert '--clobber' not in args
            assert args[args.index('--repo') + 1] == 'github.com/' + publisher.REPOSITORY
            assert self.record is not None and self.record['draft'] is True
            path = Path(args[4])
            self.mutations.append(('upload', path.name))
            if self.upload_failure:
                return subprocess.CompletedProcess(args, 1, stdout=b'', stderr=b'mock upload failed')
            assert path.name not in {asset['name'] for asset in self.assets.values()}
            self.add_asset(path.name, path.read_bytes())
            return subprocess.CompletedProcess(args, 0, stdout=b'', stderr=b'')
        if args[:2] != ['gh', 'api']:
            raise AssertionError(f'Unexpected subprocess: {args}')
        assert args[args.index('--hostname') + 1] == 'github.com'
        endpoint = next(value for value in args if value.startswith('repos/'))
        method = args[args.index('--method') + 1] if '--method' in args else 'GET'
        if endpoint in self.failures:
            failure = self.failures[endpoint]
            if failure == 'network':
                return subprocess.CompletedProcess(args, 1, stdout=b'', stderr=b'connection failed')
            if failure == 'json':
                return subprocess.CompletedProcess(args, 0, stdout=b'HTTP/2.0 200 OK\n\nnot JSON', stderr=b'')
            return self.response(args, {'message': 'mock failure'}, failure)
        if endpoint == publisher.API and method == 'GET':
            return self.response(args, {'full_name': publisher.REPOSITORY})
        if endpoint == f'{publisher.API}/releases/tags/v9q1':
            if self.record is None or self.record['draft']:
                return self.response(args, {'message': 'Not Found'}, 404)
            return self.response(args, self.record)
        if endpoint.startswith(f'{publisher.API}/releases?'):
            if self.hide_draft_on_first_page and endpoint.endswith('page=1'):
                return self.response(args, [{'id': i + 1000, 'tag_name': f'old-{i}', 'draft': False} for i in range(100)])
            return self.response(args, [self.record] if self.record else [])
        if endpoint == f'{publisher.API}/git/ref/tags/v9q1':
            return (self.response(args, {'object': self.tag_object}) if self.tag_object is not None
                    else self.response(args, {'message': 'Not Found'}, 404))
        if endpoint.startswith(f'{publisher.API}/git/tags/'):
            sha = endpoint.rsplit('/', 1)[1]
            return self.response(args, {'object': self.annotations[sha]})
        if endpoint == f'{publisher.API}/releases' and method == 'POST':
            assert self.record is None
            self.record = {'id': 17, **json.loads(kwargs['input'])}
            self.mutations.append(('create-draft', self.record['tag_name']))
            return self.response(args, self.record, 201)
        if endpoint == f'{publisher.API}/releases/17/assets?per_page=100&page=1':
            return self.response(args, self.asset_list())
        if endpoint.startswith(f'{publisher.API}/releases/assets/'):
            assert 'Accept: application/octet-stream' in args
            asset_id = int(endpoint.rsplit('/', 1)[1])
            asset = self.assets[asset_id]
            content = asset['content']
            if self.corrupt_download == asset['name']:
                content = b'!' + content[1:]
            kwargs['stdout'].write(content)
            self.downloaded.add(asset['name'])
            return subprocess.CompletedProcess(args, 0, stdout=None, stderr=b'')
        if endpoint == f'{publisher.API}/releases/17':
            if method == 'GET':
                if self.publish_concurrently:
                    self.record['draft'] = False
                return self.response(args, self.record)
            if method == 'PATCH':
                assert json.loads(kwargs['input']) == {'draft': False}
                assert len(self.assets) == 5
                assert self.downloaded == {asset['name'] for asset in self.assets.values()}
                self.mutations.append(('publish', 17))
                self.record['draft'] = False
                if self.tag_object is None:
                    self.tag_object = {'type': 'commit', 'sha': COMMIT}
                return self.response(args, self.record)
        raise AssertionError(f'Unexpected API subprocess: {method} {endpoint}')


class PublicationTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='lucky-publication-test-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.output = self.root / 'output'
        self.processes = MockProcesses()
        mock = patch.object(publisher.subprocess, 'run', side_effect=self.processes)
        self.addCleanup(mock.stop)
        mock.start()
        self.info = release.build(self.root, self.output, COMMIT)
        self.processes.calls.clear()

    def seed(self, draft=False, names=None):
        self.processes.record = {'id': 17, 'tag_name': self.info['tag'], 'draft': draft,
                                 'target_commitish': COMMIT, 'body': publisher.notes(self.info)}
        if not draft:
            self.processes.tag_object = {'type': 'commit', 'sha': COMMIT}
        expected = [self.info['deploy'], self.info['sdk'], *publisher.SIDE_FILES]
        for name in expected if names is None else names:
            self.processes.add_asset(name, (self.output / name).read_bytes())

    def publish(self, environment=None):
        return publisher.publish(self.root, self.output, ENV if environment is None else environment)

    def assert_no_mutations(self):
        self.assertEqual(self.processes.mutations, [])

    def test_new_draft_upload_download_verify_publish(self):
        result = self.publish()
        self.assertEqual(result['status'], 'published-verified')
        self.assertEqual(self.processes.mutations[0], ('create-draft', 'v9q1'))
        self.assertEqual(self.processes.mutations[-1], ('publish', 17))
        self.assertEqual(sum(op[0] == 'upload' for op in self.processes.mutations), 5)
        self.assertIn(self.info['deploy'], self.processes.downloaded)

    def test_published_identical_rerun_is_read_only(self):
        self.seed()
        self.assertEqual(self.publish()['status'], 'already-published-identical')
        self.assertEqual(len(self.processes.downloaded), 5)
        self.assert_no_mutations()

    def test_existing_different_tag_commit_requires_version_bump(self):
        self.seed()
        self.processes.tag_object['sha'] = 'c' * 40
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required.*different source'):
            self.publish()
        self.assert_no_mutations()

    def test_published_release_missing_source_tag_requires_version_bump(self):
        self.seed()
        self.processes.tag_object = None
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required.*no source tag'):
            self.publish()
        self.assert_no_mutations()

    def test_annotated_tag_resolves_to_certified_commit(self):
        self.seed()
        self.processes.tag_object = {'type': 'tag', 'sha': 'c' * 40}
        self.processes.annotations['c' * 40] = {'type': 'commit', 'sha': COMMIT}
        self.assertEqual(self.publish()['status'], 'already-published-identical')
        self.assert_no_mutations()

    def test_cyclic_tag_fails_closed(self):
        self.seed()
        self.processes.tag_object = {'type': 'tag', 'sha': 'c' * 40}
        self.processes.annotations['c' * 40] = self.processes.tag_object.copy()
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required'):
            self.publish()
        self.assert_no_mutations()

    def test_published_missing_sdk_fails_without_uploading(self):
        self.seed(names=[self.info['deploy'], *publisher.SIDE_FILES])
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required.*incomplete'):
            self.publish()
        self.assert_no_mutations()

    def test_unexpected_remote_asset_is_never_deleted(self):
        self.seed()
        self.processes.add_asset('unexpected.zip', b'other build')
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required.*unexpected'):
            self.publish()
        self.assert_no_mutations()

    def test_corrupted_remote_bytes_fail_even_if_api_digest_claims_match(self):
        self.seed()
        self.processes.corrupt_download = self.info['deploy']
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required.*content differs'):
            self.publish()
        self.assert_no_mutations()

    def test_wrong_remote_source_metadata_fails_without_overwrite(self):
        self.seed()
        asset = next(a for a in self.processes.assets.values() if a['name'] == 'BUILD_INFO.json')
        asset['content'] = asset['content'].replace(COMMIT.encode(), ('c' * 40).encode())
        asset['digest'] = 'sha256:' + hashlib.sha256(asset['content']).hexdigest()
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required.*digest differs'):
            self.publish()
        self.assert_no_mutations()

    def test_matching_partial_draft_resumes_only_missing_assets(self):
        self.seed(draft=True, names=[self.info['deploy'], 'BUILD_INFO.json'])
        self.assertEqual(self.publish()['status'], 'published-verified')
        uploads = [name for operation, name in self.processes.mutations if operation == 'upload']
        self.assertEqual(set(uploads), {self.info['sdk'], 'BUILD_INFO.txt', 'SHA256SUMS.txt'})
        self.assertNotIn(('create-draft', 'v9q1'), self.processes.mutations)

    def test_draft_recovery_scans_past_first_release_page(self):
        self.seed(draft=True, names=[])
        self.processes.hide_draft_on_first_page = True
        self.assertEqual(self.publish()['status'], 'published-verified')
        self.assertNotIn(('create-draft', 'v9q1'), self.processes.mutations)

    def test_foreign_draft_requires_manual_review_or_new_version(self):
        self.seed(draft=True, names=[])
        self.processes.record['target_commitish'] = 'c' * 40
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required.*draft'):
            self.publish()
        self.assert_no_mutations()

    def test_draft_with_changed_source_notes_is_not_adopted(self):
        self.seed(draft=True, names=[])
        self.processes.record['body'] = 'unrecognized manually created draft'
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required.*draft'):
            self.publish()
        self.assert_no_mutations()

    def test_corrupt_partial_draft_is_not_clobbered(self):
        self.seed(draft=True, names=[self.info['deploy']])
        self.processes.corrupt_download = self.info['deploy']
        with self.assertRaisesRegex(publisher.PublicationError, 'Version bump required'):
            self.publish()
        self.assert_no_mutations()

    def test_auth_rate_limit_server_errors_are_not_treated_as_missing(self):
        for status in (401, 403, 429, 500, 503):
            with self.subTest(status=status):
                self.processes.failures[f'{publisher.API}/releases/tags/v9q1'] = status
                with self.assertRaisesRegex(publisher.PublicationError, f'HTTP {status}'):
                    self.publish()
                self.assert_no_mutations()

    def test_network_error_is_not_treated_as_missing(self):
        self.processes.failures[f'{publisher.API}/releases/tags/v9q1'] = 'network'
        with self.assertRaisesRegex(publisher.PublicationError, 'no verified HTTP status'):
            self.publish()
        self.assert_no_mutations()

    def test_invalid_github_json_is_not_treated_as_missing(self):
        self.processes.failures[f'{publisher.API}/releases/tags/v9q1'] = 'json'
        with self.assertRaisesRegex(publisher.PublicationError, 'invalid JSON'):
            self.publish()
        self.assert_no_mutations()

    def test_wrong_repository_or_ref_is_rejected_before_github_access(self):
        for key, value in [('GITHUB_REPOSITORY', 'other/lucky'), ('GITHUB_REF', 'refs/heads/feature'),
                           ('GITHUB_SHA', ''), ('GH_TOKEN', '')]:
            with self.subTest(key=key):
                self.processes.calls.clear()
                with self.assertRaises(publisher.PublicationError):
                    self.publish({**ENV, key: value})
                self.assertFalse(any(call[0] == 'gh' for call in self.processes.calls))

    def test_non_actions_or_unapproved_event_is_rejected_before_git_or_github(self):
        for key, value in [('GITHUB_ACTIONS', ''), ('GITHUB_ACTIONS', 'false'),
                           ('GITHUB_EVENT_NAME', ''), ('GITHUB_EVENT_NAME', 'pull_request'),
                           ('GITHUB_EVENT_NAME', 'pull_request_target'), ('GITHUB_EVENT_NAME', 'workflow_run')]:
            with self.subTest(key=key, value=value):
                self.processes.calls.clear()
                with self.assertRaisesRegex(publisher.PublicationError, 'requires an Actions'):
                    self.publish({**ENV, key: value})
                self.assertEqual(self.processes.calls, [])

    def test_manual_actions_main_run_is_allowed(self):
        result = self.publish({**ENV, 'GITHUB_EVENT_NAME': 'workflow_dispatch'})
        self.assertEqual(result['status'], 'published-verified')

    def test_wrong_checkout_sha_is_rejected_before_github_access(self):
        self.processes.git_commit = 'c' * 40
        with self.assertRaisesRegex(publisher.PublicationError, 'certified workflow source'):
            self.publish()
        self.assertFalse(any(call[0] == 'gh' for call in self.processes.calls))

    def test_wrong_checkout_tree_is_rejected_before_github_access(self):
        self.processes.git_tree = 'c' * 40
        with self.assertRaisesRegex(publisher.PublicationError, 'source identity'):
            self.publish()
        self.assertFalse(any(call[0] == 'gh' for call in self.processes.calls))

    def test_dirty_source_is_not_published(self):
        self.processes.dirty = True
        with self.assertRaisesRegex(release.ReleaseError, 'Commit tracked changes'):
            self.publish()
        self.assertFalse(any(call[0] == 'gh' for call in self.processes.calls))

    def test_self_consistent_but_forged_local_package_fails_source_rebuild(self):
        archive_path = self.output / self.info['deploy']
        with zipfile.ZipFile(archive_path) as archive:
            entries = {name: archive.read(name) for name in archive.namelist()}
        entries['index.php'] = b'not the certified source code'
        release.write_zip(archive_path, entries)
        metadata = json.loads((self.output / 'BUILD_INFO.json').read_text())
        metadata['artifacts'][self.info['deploy']] = release.digest(archive_path)
        (self.output / 'BUILD_INFO.json').write_bytes(release.encoded(metadata))
        hashes = metadata['artifacts']
        (self.output / 'SHA256SUMS.txt').write_text(''.join(f'{hashes[name]}  {name}\n' for name in sorted(hashes)))
        release.verify(self.output, COMMIT)  # Internally consistent, but untrusted source assertion.
        with self.assertRaisesRegex(publisher.PublicationError, 'does not match certified source'):
            self.publish()
        self.assertFalse(any(call[0] == 'gh' for call in self.processes.calls))

    def test_failed_upload_never_publishes_or_deletes_the_draft(self):
        self.processes.upload_failure = True
        with self.assertRaisesRegex(publisher.PublicationError, 'draft was left intact'):
            self.publish()
        self.assertTrue(self.processes.record['draft'])
        self.assertNotIn(('publish', 17), self.processes.mutations)
        self.assertFalse(any('DELETE' in args for args in self.processes.calls))

    def test_concurrent_publication_stops_before_upload(self):
        self.seed(draft=True, names=[])
        self.processes.publish_concurrently = True
        with self.assertRaisesRegex(publisher.PublicationError, 'published concurrently'):
            self.publish()
        self.assert_no_mutations()


if __name__ == '__main__':
    unittest.main(verbosity=2)
