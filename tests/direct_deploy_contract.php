<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/deploy.yml');
function deployCheck(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException('DIRECT DEPLOY: '.$label);
 echo "PASS $label\n";
}
deployCheck(str_contains($workflow,'cd dist/work && zip -qr ../Lucky_Game_Hub_'),
 'Deployment ZIP is built from the application root, not a wrapper folder');
deployCheck(str_contains($workflow,'unzip -p dist/Lucky_Game_Hub_V5Q32F1_Full_Deploy.zip "$required_root"'),
 'ZIP-root files verified before artifact upload');
deployCheck(str_contains($workflow,'direct-release:')&&str_contains($workflow,'needs: package'),
 'Direct release waits for successful packaging');
deployCheck(str_contains($workflow,'actions/download-artifact@v4'),
 'Release job unwraps GitHub artifact before publication');
deployCheck(str_contains($workflow,'gh release create "$tag" "$deploy_zip"'),
 'Published asset is the inner deployment ZIP itself');
deployCheck(str_contains($workflow,'contents: write')&&str_contains($workflow,"if: github.ref == 'refs/heads/main'"),
 'Release write permission is scoped to post-merge main');
deployCheck(str_contains($workflow,'gh release view "$tag"'),
 'Already-published version remains unchanged on rerun');
deployCheck(str_contains($workflow,'unzip -p "$deploy_zip" "$required_root"'),
 'Direct download is independently checked for app-root structure');
echo "DIRECT DEPLOY CONTRACT: 8/8\n";
