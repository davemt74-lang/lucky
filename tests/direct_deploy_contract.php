<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/deploy.yml');
$ci=(string)file_get_contents($root.'/.github/workflows/ci.yml');
$windows=(string)file_get_contents($root.'/.github/workflows/windows-homeserver.yml');
$inventory=json_decode((string)file_get_contents($root.'/tests/suites.json'),true,512,JSON_THROW_ON_ERROR);
$release=json_decode((string)file_get_contents($root.'/release.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;
function deployCheck(bool $ok,string $label):void {
 global $checks;
 if(!$ok)throw new RuntimeException('DIRECT DEPLOY: '.$label);
 $checks++;
 echo "PASS $label\n";
}
deployCheck(preg_match('/^V[0-9A-Za-z]{1,31}$/D',$release['version']??'')===1,
 'Release version comes from one validated manifest');
deployCheck(str_contains($workflow,'uses: ./.github/workflows/ci.yml')&&str_contains($workflow,'uses: ./.github/workflows/windows-homeserver.yml'),
 'Post-merge publication calls the complete Linux and Windows certification workflows');
deployCheck(str_contains($workflow,'needs: [certify, windows-certify]'),
 'Packaging is blocked until both exact-source certification workflows succeed');
deployCheck(str_contains($ci,'workflow_call:')&&str_contains($windows,'workflow_call:'),
 'Both certification workflows support reusable execution');
deployCheck(str_contains($ci,'lucky-ci-')&&str_contains($windows,'lucky-windows-')&&str_contains($workflow,'lucky-release-'),
 'Reusable workflow concurrency groups do not cancel their caller');
deployCheck(str_contains($workflow,'python3 tools/release.py build --output-dir dist --commit "$GITHUB_SHA"')&&str_contains($workflow,'ref: ${{ github.sha }}'),
 'Packaging is bound to the certified source SHA');
deployCheck(str_contains($workflow,'python3 tools/release.py verify --output-dir dist --commit "$GITHUB_SHA"'),
 'Packaging behavior is independently verified before artifact upload');
deployCheck(str_contains($workflow,'direct-release:')&&str_contains($workflow,'needs: package'),
 'Direct release waits for successful packaging');
deployCheck(str_contains($workflow,'actions/download-artifact@v4'),
 'Release job unwraps GitHub artifact before publication');
deployCheck(str_contains($workflow,'python3 tools/publish_release.py --output-dir release'),
 'Direct publication uses the behaviorally tested immutable release publisher');
deployCheck(substr_count($workflow,'contents: write')===1&&str_contains($workflow,"if: github.repository == 'davemt74-lang/lucky' && github.ref == 'refs/heads/main'"),
 'Release write permission is limited to the owned repository main publication job');
foreach(['core','database','browsers','video'] as $group){
 deployCheck(str_contains($ci,'python3 tools/test_inventory.py --group '.$group),
  $group.' CI executes the registered suites rather than a drifting command list');
}
foreach(['tests/release_packaging.py','tests/release_publication.py','tests/node_test_completion.js'] as $test){
 deployCheck(in_array($test,$inventory['groups']['core']['tests'],true),$test.' is an executable core CI gate');
}
echo "DIRECT DEPLOY WIRING CONTRACT: $checks/$checks\n";
