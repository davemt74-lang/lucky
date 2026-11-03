"""No-browser proof that current page fixtures render without database access.

Uses the same temporary fixture and actual Bootstrap as the browser suites;
Playwright/Chromium are intentionally not imported or required by this gate.
"""
import json
import subprocess

from v5p_pairing import ROOT, isolated_page_root, php_page


def main():
    checks = []

    def check(label, condition):
        checks.append((label, bool(condition)))
        print(('PASS ' if condition else 'FAIL ') + label, flush=True)
        assert condition, label

    # The rendering fixture must never change an installed checkout, including
    # any pre-existing configuration; its entire copy is removed on context exit.
    real_config = ROOT / 'storage/config.php'
    before = real_config.read_bytes() if real_config.is_file() else None
    for page, marker in [('devices', 'phone-pairing'), ('controller', 'phone-join'),
                         ('game', 'start-game'), ('display', 'display-pair-qr')]:
        rendered = php_page(page)
        check(page + ' renders its real current UI with explicit cloud metadata',
              'id="' + marker + '"' in rendered
              and '<meta name="lucky-deployment" content="cloud">' in rendered
              and 'Fatal error' not in rendered)

    with isolated_page_root() as temporary:
        check('Fixture root is separate from the source checkout', temporary.resolve() != ROOT.resolve())
        bootstrap = json.dumps(str(temporary / 'lib/Bootstrap.php'))
        code = '''define('LUCKY_TESTING',true); require BOOTSTRAP;
        $cfg=config();
        if(($cfg['deployment']??null)!=='cloud')exit(2);
        $metadata=installationUrls();
        if($metadata!==['deployment'=>'cloud','lanOrigin'=>'','leaseSeconds'=>0])exit(3);
        try {db(); echo 'FAIL database access unexpectedly returned'; exit(4);}
        catch(Throwable $error) {
         if(get_class($error)!==RuntimeException::class ||
            $error->getMessage()!=='Browser fixture database access is disabled.') {
          echo 'FAIL unexpected failure before explicit transport guard: '.get_class($error);exit(5);
         }
         echo 'PASS actual Bootstrap db() stopped at the fixture DSN sentinel';
        }'''.replace('BOOTSTRAP', bootstrap)
        result = subprocess.run(['php', '-r', code], text=True, capture_output=True, timeout=15)
        check('Actual Bootstrap db() throws the sentinel before any PDO transport',
              result.returncode == 0 and result.stdout.strip() == 'PASS actual Bootstrap db() stopped at the fixture DSN sentinel'
              and not result.stderr.strip())
        temporary_path = temporary
    check('Temporary copied application and configuration are removed', not temporary_path.exists())
    after = real_config.read_bytes() if real_config.is_file() else None
    check('Source checkout configuration is unchanged', before == after)
    print(f'BROWSER FIXTURE ISOLATION: {len(checks)}/{len(checks)}', flush=True)


if __name__ == '__main__':
    main()
