#!/usr/bin/env python3
"""Lucky Game Hub game package SDK — offline, Python standard library only.

Examples:
 python tools/game_sdk.py init /tmp/couch-demo --id couch-demo --title "Couch Demo"
 python tools/game_sdk.py build /tmp/couch-demo --output /tmp/couch-demo.zip
 python tools/game_sdk.py verify /tmp/couch-demo.zip
"""
import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXT = {".html", ".js", ".css", ".json", ".png", ".jpg", ".jpeg",
       ".webp", ".mp3", ".m4a", ".ogg", ".wav", ".webm", ".woff2"}
MEDIA = {".png", ".jpg", ".jpeg", ".webp", ".mp3", ".m4a", ".ogg",
         ".wav", ".webm", ".woff2"}
MAX_FILE = 12 * 1024 * 1024
MAX_TOTAL = 90 * 1024 * 1024
MAX_ZIP = 40 * 1024 * 1024
MAX_ENTRIES = 150
SUPPORTED_CAPABILITIES = {
    "storage.read", "storage.write", "players.read", "players.write",
    "scores.read", "scores.write", "rounds.read", "rounds.write",
    "turns.read", "turns.write", "timers.read", "timers.write", "session.write"
}
VALID_ID = re.compile(r"^[a-z0-9][a-z0-9-]{1,62}$")
VALID_VER = re.compile(r"^(0|[1-9]\d{0,4})\.(0|[1-9]\d{0,4})\.(0|[1-9]\d{0,4})$")
VALID_FILE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_./-]*\."
                        r"(?:html|js|css|json|png|jpg|jpeg|webp|mp3|m4a|ogg|wav|webm|woff2)$")
ASSET_REF = re.compile(r"lucky-asset:([A-Za-z0-9][A-Za-z0-9_./-]{0,155}\.(?:png|jpg|jpeg|webp|mp3|m4a|ogg|wav|webm|woff2))")
FIXED_DATE = (2020, 1, 1, 0, 0, 0)


def must(condition, reason):
    if not condition:
        raise ValueError(reason)


def valid_path(name):
    must(len(name) <= 160 and VALID_FILE.fullmatch(name) is not None
         and ".." not in name and "//" not in name
         and "/./" not in name and len(name.split("/")) <= 4,
         "Unsafe or unsupported asset path: " + name)


def manifest_check(m):
    must(isinstance(m, dict) and m.get("schema") == "lucky.game-package.v1",
         "Unsupported game package schema")
    must(isinstance(m.get("id"), str) and VALID_ID.fullmatch(m["id"]),
         "Invalid game id")
    must(isinstance(m.get("version"), str) and VALID_VER.fullmatch(m["version"]),
         "Use a semantic major.minor.patch version")
    must(all(isinstance(m.get(k), str) and 0 < len(m[k].strip()) <= max_len
             for k, max_len in (("title", 120), ("publisher", 160))),
         "Missing title or publisher")
    valid_path(m.get("host", ""))
    must(m["host"].endswith(".html"), "Host must be an HTML file")
    phone = m.get("phone")
    if phone is not None:
        must(isinstance(phone, str), "Phone must be null or a path")
        valid_path(phone)
        must(phone.endswith(".html"), "Phone must be HTML")
    capabilities = m.get("capabilities", [])
    must(isinstance(capabilities, list) and
         len(capabilities) == len(set(capabilities)) and
         all(isinstance(cap, str) and cap in SUPPORTED_CAPABILITIES
             for cap in capabilities), "Invalid or duplicate game capabilities")
    schema = m.get("dataVersion", 1)
    must(type(schema) is int and 1 <= schema <= 65535, "Invalid game data version")
    files = m.get("files")
    must(isinstance(files, dict) and 0 < len(files) <= MAX_ENTRIES - 2,
         "Missing or excessive manifest files")
    must(m["host"] in files and (phone is None or phone in files),
         "Game entry HTML missing")
    seen = set()
    for path, digest in files.items():
        valid_path(path)
        must(path.lower() not in seen, "Duplicate case-insensitive asset path")
        seen.add(path.lower())
        must(isinstance(digest, str) and re.fullmatch(r"[a-f0-9]{64}", digest),
             "Invalid SHA-256 for " + path)
    return files


def collect(source):
    must(source.is_dir() and not source.is_symlink(), "Project folder unavailable")
    meta_file = source / "game.json"
    must(meta_file.is_file() and not meta_file.is_symlink(),
         "game.json missing from SDK project")
    meta = json.loads(meta_file.read_text(encoding="utf-8"))
    must(isinstance(meta, dict), "game.json must be an object")
    result = {}
    total = 0
    for file in sorted(source.rglob("*")):
        must(not file.is_symlink(), "Symlinks are forbidden: " + str(file))
        if not file.is_file() or file == meta_file:
            continue
        path = file.relative_to(source).as_posix()
        valid_path(path)
        data = file.read_bytes()
        must(len(data) <= MAX_FILE, path + " exceeds the 12MB asset limit")
        total += len(data)
        must(total <= MAX_TOTAL, "Game content exceeds 90MB")
        result[path] = data
    must(result, "No game files")
    m = {"schema": "lucky.game-package.v1",
         "id": meta.get("id"), "version": meta.get("version"),
         "title": meta.get("title"), "publisher": meta.get("publisher"),
         "host": meta.get("host"), "phone": meta.get("phone"),
         "capabilities": meta.get("capabilities", []),
         "dataVersion": meta.get("dataVersion", 1),
         "files": {name: hashlib.sha256(data).hexdigest()
                   for name, data in result.items()}}
    manifest_check(m)
    for path, data in result.items():
        if path.endswith((".html", ".css", ".js")):
            source_text = data.decode("utf-8")
            for ref in ASSET_REF.findall(source_text):
                must(ref in result and Path(ref).suffix in MEDIA,
                     "Missing offline media reference in " + path + ": " + ref)
            must(source_text.count("lucky-asset:") == len(ASSET_REF.findall(source_text)),
                 "Invalid offline media token in " + path)
    return m, result


def zip_put(z, name, data):
    info = zipfile.ZipInfo(name, date_time=FIXED_DATE)
    info.compress_type = zipfile.ZIP_DEFLATED
    info.external_attr = 0o100644 << 16
    z.writestr(info, data, compresslevel=6)


def sign(raw, key):
    p = subprocess.run(["openssl", "dgst", "-sha256", "-sign", str(key)],
                       input=raw, capture_output=True, check=True)
    import base64
    return base64.b64encode(p.stdout)


def build(args):
    source = Path(args.source).resolve()
    output = Path(args.output).resolve()
    manifest, files = collect(source)
    must(output != source / "game.json" and not output.is_relative_to(source),
         "Output ZIP must not be inside project source")
    raw = json.dumps(manifest, separators=(",", ":"), ensure_ascii=False).encode()
    signed = sign(raw, Path(args.sign_key)) if args.sign_key else None
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w") as z:
        zip_put(z, "manifest.json", raw)
        if signed is not None:
            zip_put(z, "manifest.sig", signed)
        for name in sorted(files):
            zip_put(z, name, files[name])
    must(output.stat().st_size <= MAX_ZIP, "ZIP exceeds 40MB")
    verify_zip(output)
    print(f"Built {output} · {manifest['id']} v{manifest['version']} · "
          f"{len(files)} assets · SHA256 {hashlib.sha256(output.read_bytes()).hexdigest()}")


def verify_zip(path):
    path = Path(path)
    must(path.is_file() and path.stat().st_size <= MAX_ZIP,
         "ZIP must be a file smaller than 40MB")
    with zipfile.ZipFile(path) as z:
        infos = z.infolist()
        must(2 <= len(infos) <= MAX_ENTRIES, "Invalid archive entry count")
        names = [i.filename for i in infos]
        must(len(names) == len(set(n.lower() for n in names)),
             "Duplicate ZIP path")
        must("manifest.json" in names, "Missing manifest")
        for info in infos:
            must(not info.is_dir(), "Explicit ZIP directory entry is forbidden")
            if info.filename not in ("manifest.json", "manifest.sig"):
                valid_path(info.filename)
            mode = (info.external_attr >> 16) & 0o170000
            must(mode not in (0o120000, 0o060000),
                 "Links and devices are forbidden")
            must(info.file_size <= MAX_FILE, "Excessive uncompressed asset")
        must(sum(i.file_size for i in infos) <= MAX_TOTAL,
             "Excessive expanded ZIP")
        raw = z.read("manifest.json")
        must(len(raw) <= 131072, "Oversized manifest")
        meta = json.loads(raw)
        files = manifest_check(meta)
        expected = set(files) | {"manifest.json"}
        if "manifest.sig" in names:
            expected.add("manifest.sig")
        must(set(names) == expected, "ZIP has unexpected/missing files")
        for name, digest in files.items():
            must(hashlib.sha256(z.read(name)).hexdigest() == digest,
                 "Asset checksum mismatch: " + name)
    return meta


def init(args):
    destination = Path(args.destination).resolve()
    must(not destination.exists() or
         destination.is_dir() and not any(destination.iterdir()),
         "Use a new or empty project folder")
    must(VALID_ID.fullmatch(args.id), "Invalid game id")
    destination.mkdir(parents=True, exist_ok=True)
    (destination / "scripts").mkdir()
    (destination / "styles").mkdir()
    (destination / "scripts" / "lucky-game.js").write_bytes(
        (ROOT / "sdk" / "lucky-game.js").read_bytes())
    meta = {"id": args.id, "version": "0.1.0", "title": args.title,
            "publisher": args.publisher, "host": "host.html",
            "phone": None if args.no_phone else "phone.html",
            "capabilities": [], "dataVersion": 1}
    (destination / "game.json").write_text(json.dumps(meta, indent=2) + "\n")
    (destination / "host.html").write_text(
        '<!doctype html><html><head><meta charset="utf-8"><title>Game host</title>'
        '<link rel="stylesheet" href="styles/game.css"></head><body>'
        '<h1>SDK Test Fixture</h1><p id="status">Waiting for session…</p>'
        '<button data-game-input="ready" data-game-value="yes">Ready</button>'
        '<script src="scripts/lucky-game.js"></script>'
        '<script src="scripts/game.js"></script></body></html>\n')
    if not args.no_phone:
        (destination / "phone.html").write_text(
            '<!doctype html><html><head><meta charset="utf-8"></head><body>'
            '<button data-game-input="buzz" data-game-value="one">Buzz</button>'
            '<script src="scripts/lucky-game.js"></script></body></html>\n')
    (destination / "scripts" / "game.js").write_text(
        'LuckyGameSDK.onState(state => {\n'
        '  document.querySelector("#status").textContent = state.status;\n'
        '});\n')
    (destination / "styles" / "game.css").write_text(
        'body { font-family: system-ui; background:#101827; color:white; }\n')
    print(f"Created {destination} (phone: {not args.no_phone})")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    c = commands.add_parser("init", help="Create a reusable host/phone game SDK project")
    c.add_argument("destination")
    c.add_argument("--id", required=True)
    c.add_argument("--title", default="SDK Test Fixture")
    c.add_argument("--publisher", default="Local developer")
    c.add_argument("--no-phone", action="store_true")
    b = commands.add_parser("build", help="Create a hashed Lucky game ZIP")
    b.add_argument("source")
    b.add_argument("--output", required=True)
    b.add_argument("--sign-key", help="Optional PEM private key; requires OpenSSL")
    v = commands.add_parser("verify", help="Validate paths, manifest, and SHA-256")
    v.add_argument("zip")
    args = parser.parse_args()
    try:
        if args.command == "init":
            init(args)
        elif args.command == "build":
            build(args)
        elif args.command == "verify":
            m = verify_zip(args.zip)
            print(f"Verified {m['id']} v{m['version']} · "
                  f"{len(m['files'])} assets (publisher signature not checked)")
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile,
            subprocess.CalledProcessError, UnicodeDecodeError) as error:
        parser.exit(1, f"Package invalid: {error}\n")


if __name__ == "__main__":
    main()
