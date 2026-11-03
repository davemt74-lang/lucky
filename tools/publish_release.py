#!/usr/bin/env python3
"""Publish verified direct ZIPs, never replace an existing release or asset.

Only the certified Lucky main checkout may publish. GitHub authentication is
the GH_TOKEN already supplied to the Actions job; this script never reads or
prints credentials. Matching incomplete drafts can resume without clobbering.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

import release

REPOSITORY = 'davemt74-lang/lucky'
API = f'repos/{REPOSITORY}'
ROOT = Path(__file__).resolve().parents[1]
SIDE_FILES = ('BUILD_INFO.json', 'BUILD_INFO.txt', 'SHA256SUMS.txt')


class PublicationError(RuntimeError):
    pass


def mismatch(message: str) -> None:
    raise PublicationError('Version bump required: ' + message + ' Existing published assets were not overwritten.')


def command(args: list[str], **kwargs) -> subprocess.CompletedProcess:
    try:
        return subprocess.run(args, check=False, stderr=subprocess.PIPE,
                              timeout=120, **kwargs)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise PublicationError('GitHub CLI unavailable or timed out; publication stopped.') from exc


class GitHub:
    def request(self, method: str, endpoint: str, data=None, missing_ok: bool = False):
        args = ['gh', 'api', '--hostname', 'github.com', '--include', '--method', method,
                '-H', 'Accept: application/vnd.github+json',
                '-H', 'X-GitHub-Api-Version: 2022-11-28', endpoint]
        payload = None
        if data is not None:
            args += ['--input', '-']
            payload = json.dumps(data).encode('utf-8')
        result = command(args, input=payload, stdout=subprocess.PIPE)
        raw = (result.stdout or b'').replace(b'\r\n', b'\n')
        headers, separator, body = raw.partition(b'\n\n')
        status_line = re.match(rb'HTTP/\S+\s+(\d{3})(?:\s|$)', headers)
        if not separator or not status_line:
            raise PublicationError(f'GitHub API {method} {endpoint} returned no verified HTTP status; stopped.')
        status = int(status_line.group(1))
        if status == 404 and missing_ok:
            return None
        if result.returncode or not 200 <= status < 300:
            raise PublicationError(f'GitHub API {method} {endpoint} failed (HTTP {status}); stopped.')
        try:
            return json.loads(body) if body.strip() else None
        except (ValueError, UnicodeError) as exc:
            raise PublicationError('GitHub returned invalid JSON; publication stopped.') from exc

    def download(self, asset_id: int, destination: Path) -> None:
        if type(asset_id) is not int or asset_id <= 0:
            raise PublicationError('Invalid GitHub asset identity.')
        with destination.open('xb') as output:
            result = command(['gh', 'api', '--hostname', 'github.com',
                              '-H', 'Accept: application/octet-stream',
                              f'{API}/releases/assets/{asset_id}'], stdout=output)
        if result.returncode:
            raise PublicationError('GitHub asset download failed; no release will be published.')

    def upload(self, tag: str, path: Path) -> None:
        # Deliberately no --clobber: a conflicting upload always fails closed.
        result = command(['gh', 'release', 'upload', tag, str(path), '--repo', 'github.com/' + REPOSITORY],
                         stdout=subprocess.PIPE)
        if result.returncode:
            raise PublicationError('Draft upload failed. The draft was left intact; retry only after reviewing it.')


def source_identity(root: Path, environment: dict[str, str]) -> dict:
    if environment.get('GITHUB_ACTIONS') != 'true' or environment.get('GITHUB_EVENT_NAME') not in ('push', 'workflow_dispatch'):
        raise PublicationError('Publication requires an Actions main push or manual workflow run.')
    if environment.get('GITHUB_REPOSITORY') != REPOSITORY or environment.get('GITHUB_REF') != 'refs/heads/main':
        raise PublicationError('Publication is restricted to davemt74-lang/lucky main.')
    sha = environment.get('GITHUB_SHA', '')
    if not release.SHA.fullmatch(sha) or not environment.get('GH_TOKEN'):
        raise PublicationError('A full certified GITHUB_SHA and configured GH_TOKEN are required.')
    commit = release.git(root, 'rev-parse', 'HEAD').decode().strip()
    tree = release.git(root, 'rev-parse', 'HEAD^{tree}').decode().strip()
    if commit != sha or not release.SHA.fullmatch(tree):
        raise PublicationError('Checkout does not match the certified workflow source.')
    spec = release.manifest(json.loads(release.git(root, 'show', 'HEAD:release.json')))
    return release.identity({'version': spec['version'], 'commit': commit, 'tree': tree})


def verified_payload(root: Path, directory: Path, environment: dict[str, str]) -> tuple[dict, dict[str, Path]]:
    identity = source_identity(root, environment)
    info = release.verify(directory, identity['commit'])
    if release.identity(info) != identity:
        raise PublicationError('Local packages disagree with the checked-out source identity.')
    assets = {name: directory / name for name in (info['deploy'], info['sdk'], *SIDE_FILES)}
    if any(not path.is_file() or path.is_symlink() for path in assets.values()):
        raise PublicationError('Publication requires five regular, local release files.')
    # A self-consistent checksum manifest alone is not proof of source parity.
    # Rebuild from the certified Git tree and compare every publishable byte.
    with tempfile.TemporaryDirectory(prefix='lucky-release-source-') as temporary:
        canonical = Path(temporary)
        rebuilt = release.build(root, canonical, identity['commit'])
        if release.identity(rebuilt) != identity:
            raise PublicationError('Canonical release rebuild changed source identity.')
        for name, path in assets.items():
            if release.digest(path) != release.digest(canonical / name):
                raise PublicationError(f'Local release asset does not match certified source: {name}')
    return info, assets


def notes(info: dict) -> str:
    return (f"Lucky Game Hub {info['version']} — direct deployment.\n\n"
            f"Source commit: {info['commit']}\nSource tree: {info['tree']}\n\n"
            'Download the Full_Deploy.zip asset, upload it to the existing Lucky installation directory, '
            'and extract once. No outer artifact ZIP or enclosing application folder. '
            'Back up first; preserve storage/config.php, uploaded media and database data.\n')


def release_record(value, tag: str) -> dict:
    if not isinstance(value, dict) or type(value.get('id')) is not int or value['id'] <= 0:
        raise PublicationError('GitHub returned an invalid release identity.')
    if value.get('tag_name') != tag or type(value.get('draft')) is not bool:
        mismatch('the release tag or draft state is unexpected.')
    return value


def find_release(client: GitHub, tag: str):
    found = client.request('GET', f'{API}/releases/tags/{tag}', missing_ok=True)
    if found is not None:
        return release_record(found, tag)
    # The by-tag REST endpoint returns published releases, not necessarily drafts.
    matches = []
    for page in range(1, 101):
        releases = client.request('GET', f'{API}/releases?per_page=100&page={page}')
        if not isinstance(releases, list):
            raise PublicationError('Invalid release listing; publication stopped.')
        matches += [item for item in releases if isinstance(item, dict) and item.get('tag_name') == tag]
        if len(matches) > 1:
            mismatch('multiple releases or drafts use the same version.')
        if len(releases) < 100:
            return release_record(matches[0], tag) if matches else None
    raise PublicationError('Release listing exceeded the safe page limit; inspect existing drafts manually.')


def check_tag(client: GitHub, info: dict, missing_ok: bool) -> None:
    ref = client.request('GET', f"{API}/git/ref/tags/{info['tag']}", missing_ok=True)
    if ref is None:
        if missing_ok:
            return  # A new draft may not create its tag until publication.
        mismatch('the published release has no source tag.')
    obj = ref.get('object') if isinstance(ref, dict) else None
    visited = set()
    for _ in range(8):
        if not isinstance(obj, dict) or not release.SHA.fullmatch(str(obj.get('sha', ''))):
            mismatch('the release tag has an invalid Git object.')
        if obj.get('type') == 'commit':
            if obj['sha'] != info['commit']:
                mismatch('the version tag points to a different source commit.')
            return
        if obj.get('type') != 'tag' or obj['sha'] in visited:
            mismatch('the release tag cannot be resolved to one source commit.')
        visited.add(obj['sha'])
        target = client.request('GET', f"{API}/git/tags/{obj['sha']}")
        obj = target.get('object') if isinstance(target, dict) else None
    mismatch('the release tag has too many annotation layers.')


def check_draft(record: dict, info: dict) -> None:
    if record['draft'] is not True:
        raise PublicationError('Release was published concurrently; stopped without changing assets. Rerun verification.')
    if record.get('target_commitish') != info['commit'] or record.get('body') != notes(info):
        mismatch('the existing draft is not owned by this exact source build.')


def asset_inventory(client: GitHub, release_id: int, expected: set[str]) -> dict:
    # There are only five allowed assets. Any full page is already unexpected.
    values = client.request('GET', f'{API}/releases/{release_id}/assets?per_page=100&page=1')
    if not isinstance(values, list):
        raise PublicationError('Invalid release asset listing.')
    assets = {}
    for asset in values:
        if not isinstance(asset, dict) or asset.get('name') not in expected or asset['name'] in assets:
            mismatch('the release contains unexpected or duplicate assets.')
        if type(asset.get('id')) is not int or asset['id'] <= 0 or asset.get('state') != 'uploaded':
            mismatch('a release asset is incomplete or has an invalid identity.')
        assets[asset['name']] = asset
    return assets


def verify_remote(client: GitHub, record: dict, info: dict, local: dict[str, Path], complete: bool) -> dict:
    inventory = asset_inventory(client, record['id'], set(local))
    if complete and set(inventory) != set(local):
        mismatch('the published release asset inventory is incomplete.')
    with tempfile.TemporaryDirectory(prefix='lucky-release-download-') as temporary:
        destination = Path(temporary)
        for name, asset in inventory.items():
            if type(asset.get('size')) is not int or asset['size'] != local[name].stat().st_size:
                mismatch(f'asset size differs for {name}.')
            expected = release.digest(local[name])
            if asset.get('digest') not in (None, '', 'sha256:' + expected):
                mismatch(f'GitHub asset digest differs for {name}.')
            client.download(asset['id'], destination / name)
            if release.digest(destination / name) != expected:
                mismatch(f'downloaded asset content differs for {name}.')
        if complete:
            downloaded = release.verify(destination, info['commit'])
            if release.identity(downloaded) != release.identity(info):
                mismatch('downloaded source metadata differs.')
    return {name: asset['id'] for name, asset in inventory.items()}


def stable_inventory(client: GitHub, record: dict, local: dict[str, Path], checked: dict) -> None:
    current = asset_inventory(client, record['id'], set(local))
    if {name: asset['id'] for name, asset in current.items()} != checked:
        mismatch('release assets changed during verification.')


def publish(root: Path, directory: Path, environment=None, client=None) -> dict:
    environment = os.environ if environment is None else environment
    client = GitHub() if client is None else client
    info, local = verified_payload(root, directory, environment)
    repository = client.request('GET', API)
    if not isinstance(repository, dict) or repository.get('full_name') != REPOSITORY:
        raise PublicationError('Authenticated GitHub repository identity could not be verified.')
    record = find_release(client, info['tag'])
    if record is not None and record['draft'] is False:
        check_tag(client, info, missing_ok=False)
        checked = verify_remote(client, record, info, local, complete=True)
        current = release_record(client.request('GET', f"{API}/releases/{record['id']}"), info['tag'])
        if current['draft']:
            mismatch('published release changed to draft during verification.')
        check_tag(client, info, missing_ok=False)
        stable_inventory(client, current, local, checked)
        return {'status': 'already-published-identical', 'tag': info['tag'], 'commit': info['commit']}
    check_tag(client, info, missing_ok=True)
    if record is None:
        record = release_record(client.request('POST', f'{API}/releases', {
            'tag_name': info['tag'], 'target_commitish': info['commit'],
            'name': f"Lucky Game Hub {info['version']} — Direct Deploy ZIP",
            'body': notes(info), 'draft': True, 'prerelease': False,
        }), info['tag'])
    check_draft(record, info)
    checked = verify_remote(client, record, info, local, complete=False)
    for name in sorted(set(local) - set(checked)):
        current = release_record(client.request('GET', f"{API}/releases/{record['id']}"), info['tag'])
        check_draft(current, info)
        stable_inventory(client, current, local, checked)
        client.upload(info['tag'], local[name])
        # Recheck every added asset before allowing subsequent writes.
        checked = verify_remote(client, current, info, local, complete=False)
        if name not in checked:
            raise PublicationError('Upload did not produce the expected draft asset; publication stopped.')
    checked = verify_remote(client, record, info, local, complete=True)
    current = release_record(client.request('GET', f"{API}/releases/{record['id']}"), info['tag'])
    check_draft(current, info)
    stable_inventory(client, current, local, checked)
    check_tag(client, info, missing_ok=True)
    published = release_record(client.request('PATCH', f"{API}/releases/{record['id']}", {'draft': False}), info['tag'])
    if published['draft']:
        raise PublicationError('GitHub did not publish the verified draft.')
    check_tag(client, info, missing_ok=False)
    verified = verify_remote(client, published, info, local, complete=True)
    stable_inventory(client, published, local, verified)
    return {'status': 'published-verified', 'tag': info['tag'], 'commit': info['commit']}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT)
    parser.add_argument('--output-dir', type=Path, default=ROOT / 'dist')
    args = parser.parse_args()
    try:
        result = publish(args.root.resolve(), args.output_dir.resolve())
    except (PublicationError, release.ReleaseError, OSError, ValueError, subprocess.SubprocessError) as exc:
        print(f'Release publication stopped: {exc}', file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
