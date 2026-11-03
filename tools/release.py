#!/usr/bin/env python3
"""Deterministic, app-root deployment packages from one exact Git commit.

No runtime data is read. Publication is a separate, checked CI operation.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
REQUIRED = (
    'index.php', 'admin.php', 'director.php', 'install.php', 'upgrade.php',
    'api/index.php', 'api/director-live.php', 'lib/Bootstrap.php',
    'lib/BuildVersion.php', 'public/director-view.js', 'public/director-publish.js',
    'storage/.htaccess', 'release.json',
    'proof.php', 'controller.php', 'camera.php', 'recording-editor.php',
    'game.php', 'live-view.php', 'api/phone-media.php',
    'api/recording-sessions.php', 'api/recording-phone.php',
    'api/recording-media.php', 'api/recording-timeline.php',
    'api/recording-export.php', 'lib/RecordingSessions.php',
    'lib/RecordingPhone.php', 'lib/RecordingMulticam.php',
    'lib/RecordingProfiles.php', 'lib/RecordingTimeline.php',
    'lib/RecordingExports.php', 'lib/RecordingExportPlan.php',
    'lib/MulticameraEncoder.php', 'lib/RecordingExportWorker.php',
    'lib/PartyStudio.php', 'lib/CostumeGameplay.php', 'lib/LiveGameDirector.php',
    'lib/DirectorBroadcast.php', 'lib/DirectorTurn.php',
    'bin/recording-export-worker.php',
    'lib/migrations/v5q29e1_recording_sessions.sql',
    'lib/migrations/v5q29e2_phone_camera.sql',
    'lib/migrations/v5q29e3_multicamera_coordination.sql',
    'lib/migrations/v5q29e4a_multicamera_timeline.sql',
    'lib/migrations/v5q29e4b_multicamera_exports.sql',
    'lib/migrations/v5q29e4c_recording_profiles.sql',
    'lib/migrations/v5q30_party_studio.sql',
    'lib/migrations/v5q31_costume_actions.sql',
    'lib/migrations/v5q32e1_permanent_show_url.sql',
    'public/director-workspace.js', 'public/director-game.js',
    'public/director-game.css', 'public/display-program.js',
    'public/display-program.css', 'public/recording-editor.js',
    'public/recording-editor.css', 'public/phone-controller.js',
    'public/phone-capture.js', 'public/recording-camera.js',
    'public/admin-recordings.js', 'public/proof.js',
    'public/party-studio.js', 'public/party-studio.css',
    'public/costume-gameplay.js', 'public/costume-gameplay.css',
    'public/editor-moments.js', 'public/editor-moments.css',
    'public/game-setup.js', 'public/game-setup.css',
)
STORAGE_PLACEHOLDERS = {
    prefix + name
    for prefix in ('storage/', 'storage/claim-media/', 'storage/prize-videos/', 'storage/renders/')
    for name in ('.htaccess', 'index.html')
}
SDK_FILES = (
    'sdk/lucky-game.js', 'tools/game_sdk.py', 'docs/GAME_PACKAGE_SDK.md',
    'docs/GAME_PACKAGE_FORMAT.md', 'docs/PACKAGE_PHONE_CONTROLLERS.md',
    'docs/V5Q24_UNIVERSAL_PLUGIN_SDK.md',
)
SHA = re.compile(r'^[a-f0-9]{40}$')


class ReleaseError(ValueError):
    pass


def manifest(value: dict) -> dict:
    if not isinstance(value, dict):
        raise ReleaseError('Release manifest must be an object.')
    for key in ('version', 'sdkVersion'):
        if not isinstance(value.get(key), str) or not re.fullmatch(r'V[0-9A-Za-z]{1,31}', value[key]):
            raise ReleaseError(f'Invalid release {key}.')
    numeric = value.get('installerVersion', '')
    if not isinstance(numeric, str) or not re.fullmatch(r'\d+\.\d+\.\d+\.\d+', numeric):
        raise ReleaseError('Invalid numeric installer version.')
    if any(int(part) > 65535 for part in numeric.split('.')):
        raise ReleaseError('Installer version component exceeds 65535.')
    if not isinstance(value.get('section'), str) or not value['section'].strip():
        raise ReleaseError('Release section is required.')
    return value


def names(value: dict) -> dict[str, str]:
    value = manifest(value)
    return {
        'deploy': f"Lucky_Game_Hub_{value['version']}_Full_Deploy.zip",
        'sdk': f"Lucky_Game_SDK_{value['sdkVersion']}.zip",
        'installer': f"Lucky_Game_Hub_HomeServer_{value['version']}_Setup.exe",
        'tag': value['version'].lower(),
        'artifact': f"lucky-{value['version'].lower()}-complete-deployment",
    }


def identity(value: dict) -> dict[str, str]:
    if not isinstance(value, dict) or not re.fullmatch(r'V[0-9A-Za-z]{1,31}', str(value.get('version', ''))):
        raise ReleaseError('Invalid build version.')
    if any(not SHA.fullmatch(str(value.get(key, ''))) for key in ('commit', 'tree')):
        raise ReleaseError('Build commit and tree must be full Git object IDs.')
    return {key: value[key] for key in ('version', 'commit', 'tree')}


def git(root: Path, *args: str, input_data: bytes | None = None) -> bytes:
    return subprocess.run(['git', '-C', str(root), *args], check=True,
                          input=input_data, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=60).stdout


def committed_source(root: Path, commit: str) -> dict[str, bytes]:
    """Read exact tree blobs; archive filters must not omit or rewrite runtime files."""
    records = []
    for record in git(root, 'ls-tree', '-r', '-z', '--full-tree', commit).split(b'\0'):
        if not record:
            continue
        details, raw_name = record.split(b'\t', 1)
        mode, kind, object_id = details.decode('ascii').split(' ')
        name = raw_name.decode('utf-8')
        safe_path(name)
        if mode not in ('100644', '100755') or kind != 'blob' or not SHA.fullmatch(object_id):
            raise ReleaseError(f'Non-regular source entry cannot be deployed: {name}')
        records.append((name, object_id))
    payload = ''.join(f'{object_id}\n' for _, object_id in records).encode('ascii')
    stream = io.BytesIO(git(root, 'cat-file', '--batch', input_data=payload))
    result = {}
    for name, object_id in records:
        header = stream.readline().decode('ascii').split()
        if len(header) != 3 or header[0] != object_id or header[1] != 'blob' or not header[2].isdigit():
            raise ReleaseError(f'Cannot read committed blob: {name}')
        size = int(header[2])
        content = stream.read(size)
        if len(content) != size or stream.read(1) != b'\n':
            raise ReleaseError(f'Truncated committed blob: {name}')
        result[name] = content
    if stream.read():
        raise ReleaseError('Unexpected bytes after committed source blobs.')
    return result


def safe_path(name: str) -> None:
    path = PurePosixPath(name)
    if not name or path.is_absolute() or '..' in path.parts or '\\' in name or ':' in name or name != path.as_posix():
        raise ReleaseError(f'Unsafe archive path: {name!r}')


def deploy_path(name: str) -> bool:
    safe_path(name)
    path = PurePosixPath(name)
    if path.parts[0] in ('.github', 'tests', 'dist', '.git') or name in ('.gitignore', '.gitattributes'):
        return False
    if name == 'build-info.json':
        raise ReleaseError('build-info.json is generated; do not commit a stale build identity.')
    if any(part == '.env' or part.startswith('.env.') for part in path.parts):
        raise ReleaseError('Environment/secret files cannot be packaged.')
    if path.parts[0] == 'storage' and name not in STORAGE_PLACEHOLDERS:
        raise ReleaseError(f'Private runtime data is tracked: {name}. Remove it from source before releasing.')
    return True


def encoded(value: dict) -> bytes:
    return (json.dumps(value, indent=2, sort_keys=True, ensure_ascii=False) + '\n').encode('utf-8')


def info_text(release: dict, info: dict, count: int) -> str:
    return (f"Lucky Game Hub {release['version']} — Full Deployment\n"
            f"Section: {release['section']}\nCommit: {info['commit']}\nTree: {info['tree']}\nFiles: {count}\n"
            'Upload the Full_Deploy.zip asset and extract once in the existing installation directory.\n'
            'Existing storage/config.php, uploaded media and database are not included or replaced.\n')


def write_zip(path: Path, entries: dict[str, bytes]) -> None:
    with zipfile.ZipFile(path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, data in sorted(entries.items()):
            safe_path(name)
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, data, compresslevel=9)


def digest(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def build(root: Path, destination: Path, expected_commit: str | None = None) -> dict:
    root = root.resolve()
    commit = git(root, 'rev-parse', 'HEAD').decode().strip()
    tree = git(root, 'rev-parse', 'HEAD^{tree}').decode().strip()
    if expected_commit and commit != expected_commit:
        raise ReleaseError('Checked-out source is not the certified workflow commit.')
    # Refuse to present uncommitted application edits as part of the certified build.
    if subprocess.run(['git', '-C', str(root), 'diff', '--quiet', 'HEAD', '--'], check=False).returncode != 0:
        raise ReleaseError('Commit tracked changes before building a release.')
    tracked = committed_source(root, commit)
    release = manifest(json.loads(tracked['release.json']))
    files = names(release)
    info = identity({'version': release['version'], 'commit': commit, 'tree': tree})
    app = {name: data for name, data in tracked.items() if deploy_path(name)}
    for required in REQUIRED + SDK_FILES:
        if required not in app:
            raise ReleaseError(f'Required deployment file missing: {required}')
    app['build-info.json'] = encoded(info)
    destination.mkdir(parents=True, exist_ok=True)
    # Re-running the same build is safe; use a separate directory for another source.
    previous = destination / 'BUILD_INFO.json'
    if previous.exists() and identity(json.loads(previous.read_text())) != info:
        raise ReleaseError('Output directory belongs to a different build. Use a new output directory.')
    write_zip(destination / files['deploy'], app)
    write_zip(destination / files['sdk'], {name: tracked[name] for name in SDK_FILES})
    hashes = {files[key]: digest(destination / files[key]) for key in ('deploy', 'sdk')}
    metadata = {**info, 'files': len(app), 'artifacts': hashes}
    (destination / 'BUILD_INFO.json').write_bytes(encoded(metadata))
    (destination / 'SHA256SUMS.txt').write_text(''.join(f'{hashes[name]}  {name}\n' for name in sorted(hashes)), encoding='utf-8')
    (destination / 'BUILD_INFO.txt').write_text(info_text(release, info, len(app)), encoding='utf-8')
    return verify(destination, commit)


def verify(directory: Path, expected_commit: str | None = None) -> dict:
    metadata = json.loads((directory / 'BUILD_INFO.json').read_text(encoding='utf-8'))
    info = identity(metadata)
    if expected_commit and info['commit'] != expected_commit:
        raise ReleaseError('Artifact was not built from the certified source commit.')
    deploy_name = f"Lucky_Game_Hub_{info['version']}_Full_Deploy.zip"
    with zipfile.ZipFile(directory / deploy_name) as archive:
        entries = archive.namelist()
        if len(set(entries)) != len(entries) or archive.testzip() is not None:
            raise ReleaseError('Invalid or duplicate deployment ZIP entries.')
        for member in archive.infolist():
            if not stat.S_ISREG(member.external_attr >> 16):
                raise ReleaseError('Deployment entries must be regular files, not folders or symlinks.')
        for name in entries:
            if name == 'build-info.json':
                continue
            if not deploy_path(name):
                raise ReleaseError(f'Development-only file in deployment: {name}')
        if not set(REQUIRED + SDK_FILES + ('build-info.json',)).issubset(entries):
            raise ReleaseError('ZIP must contain application files directly at its root.')
        release = manifest(json.loads(archive.read('release.json')))
        if release['version'] != info['version'] or identity(json.loads(archive.read('build-info.json'))) != info:
            raise ReleaseError('ZIP build identity disagrees with release metadata.')
        if metadata.get('files') != len(entries):
            raise ReleaseError('Deployment file count disagrees with metadata.')
        sdk_data = {name: archive.read(name) for name in SDK_FILES}
    if (directory / 'BUILD_INFO.txt').read_text(encoding='utf-8') != info_text(release, info, len(entries)):
        raise ReleaseError('Human-readable build information disagrees with verified metadata.')
    files = names(release)
    expected_names = {files['deploy'], files['sdk']}
    hashes = metadata.get('artifacts')
    if not isinstance(hashes, dict) or set(hashes) != expected_names:
        raise ReleaseError('Release checksum inventory is incomplete or unexpected.')
    for name, expected in hashes.items():
        if not isinstance(expected, str) or not re.fullmatch(r'[a-f0-9]{64}', expected) or digest(directory / name) != expected:
            raise ReleaseError(f'Artifact digest mismatch: {name}')
    checksums = ''.join(f'{hashes[name]}  {name}\n' for name in sorted(hashes))
    if (directory / 'SHA256SUMS.txt').read_text(encoding='utf-8') != checksums:
        raise ReleaseError('Checksum file disagrees with build metadata.')
    with zipfile.ZipFile(directory / files['sdk']) as archive:
        if sorted(archive.namelist()) != sorted(SDK_FILES) or archive.testzip() is not None:
            raise ReleaseError('SDK archive is incomplete or invalid.')
        for member in archive.infolist():
            if not stat.S_ISREG(member.external_attr >> 16) or archive.read(member.filename) != sdk_data[member.filename]:
                raise ReleaseError('SDK archive differs from the certified application source.')
    return {**metadata, **files}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('build', 'verify', 'names'))
    parser.add_argument('--root', type=Path, default=ROOT)
    parser.add_argument('--output-dir', type=Path, default=ROOT / 'dist')
    parser.add_argument('--commit', default=os.environ.get('GITHUB_SHA'))
    args = parser.parse_args()
    if args.command == 'build':
        result = build(args.root, args.output_dir, args.commit)
    elif args.command == 'verify':
        result = verify(args.output_dir, args.commit)
    else:
        result = names(json.loads((args.root / 'release.json').read_text(encoding='utf-8')))
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
