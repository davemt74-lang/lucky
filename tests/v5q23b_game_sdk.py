"""V5Q.23B offline SDK test: scaffolding, ZIP build, hash validation and rejection."""
import hashlib
import json
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CLI=ROOT/'tools/game_sdk.py'

def call(*args,should_pass=True):
    r=subprocess.run([sys.executable,str(CLI),*map(str,args)],capture_output=True,text=True)
    if (r.returncode==0)!=should_pass:
        raise RuntimeError(f"Expected {should_pass} from {args}: {r.stdout}\n{r.stderr}")
    return r

with tempfile.TemporaryDirectory(prefix='lucky-sdk-test-') as directory:
    tmp=Path(directory)
    for phone in (True,False):
        project=tmp/('with-phone' if phone else 'tv-only')
        args=['init',project,'--id','fixture-phone' if phone else 'fixture-tv',
              '--title','SDK Fixture','--publisher','CI Only']
        if not phone:args.append('--no-phone')
        call(*args)
        assert (project/'host.html').is_file()
        assert (project/'phone.html').exists()==phone
        sdk=(project/'scripts/lucky-game.js').read_text()
        assert "onState" in sdk and "sendInput" in sdk
        package=tmp/('phone.zip' if phone else 'tv.zip')
        call('build',project,'--output',package)
        assert 'Verified' in call('verify',package).stdout
        first=hashlib.sha256(package.read_bytes()).hexdigest()
        call('build',project,'--output',package)
        assert hashlib.sha256(package.read_bytes()).hexdigest()==first, 'Non-deterministic package'
        with zipfile.ZipFile(package) as z:
            manifest=json.loads(z.read('manifest.json'))
            assert manifest['phone']==('phone.html' if phone else None)
            assert 'scripts/lucky-game.js' in manifest['files']
            assert set(z.namelist())==set(manifest['files'])|{'manifest.json'}
        (project/'host.html').write_text('<img src="lucky-asset:media/missing.png">')
        call('build',project,'--output',package,should_pass=False)
        (project/'host.html').write_text('<script>ok()</script>')
        (project/'evil.php').write_text('<?php echo 1;')
        call('build',project,'--output',package,should_pass=False)
        (project/'evil.php').unlink()
        (project/'host.html').write_text('<script>ok()</script>')
        call('build',project,'--output',package)
        with zipfile.ZipFile(package,'a') as z:
            z.writestr('extra.js','oops')
        call('verify',package,should_pass=False)
    print('V5Q.23B SDK: host/phone scaffolds, deterministic ZIP, hashes and malicious-input checks passed')
