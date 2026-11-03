# Lucky Game Hub — HostGator deployment

## Direct download and upload (one ZIP, one extraction)

After a version passes CI and is merged, visit the GitHub **Releases** page for the repository and download the asset named **Lucky_Game_Hub_V…_Full_Deploy.zip**.

1. Download the **Full_Deploy.zip** asset from GitHub Releases. Do not download GitHub's auto-generated source-code ZIP or the CI artifact wrapper.
2. Open HostGator File Manager and navigate to your **existing Lucky Game Hub installation directory**. Do not use the webroot of an unrelated site.
3. Upload that exact ZIP and select **Extract** once.
4. Confirm that index.php, admin.php, director.php, api/, lib/, public/ and storage/ are directly in the installation directory. There should be no extra enclosing folder.
5. Preserve the existing storage/config.php, user media/uploads and your database. Back up live site content before updating; do not delete user data or reinstall from scratch.

A release also includes SHA256SUMS.txt for integrity checks and BUILD_INFO.txt /
BUILD_INFO.json identifying the exact source commit and Git tree. These are
separate downloadable assets, not a ZIP wrapper around the deployment package.

After extraction, check the application footer: its version and short commit must
match the release's BUILD_INFO.txt. The deployed build-info.json contains the full
commit and tree. A footer saying "source build" means generated provenance is
missing or does not match release.json; it is not confirmation of a certified ZIP.

GitHub Actions workflow artifacts remain available as an archival copy, but GitHub wraps them inside an outer ZIP. The **Release asset** is the direct deployment file meant for HostGator upload and extraction.

## Release integrity and safety

For V5Q32G1 onward, the main-branch release workflow runs all registered core,
MariaDB, browser and FFmpeg suites, plus Windows installer certification, before
packaging the same exact commit. Publication verifies source identity, ZIP CRCs,
app-root layout, embedded metadata, SDK consistency and SHA-256 checksums.

The packager reads committed source only. Private configuration, environment
files and runtime uploads cannot be included; only the explicit storage protection
placeholder files are shipped. An isolated extraction regression verifies that an
existing configuration and sample recording survive the update unchanged.

An existing published version is accepted only when its source and every release
asset match. Different source or asset content fails publication and requires a
new version; reruns never overwrite published downloads. GitHub's automatic
"Source code" downloads are not the deployment artifacts.

Production installation and database migration remain separate administrator
actions. Section A adds no migration; run upgrade.php only for documented pending
migrations from prior releases. Always back up the site and database first.
