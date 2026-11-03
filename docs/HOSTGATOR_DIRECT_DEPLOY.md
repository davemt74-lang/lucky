# Lucky Game Hub — HostGator deployment

## Direct download and upload (one ZIP, one extraction)

After a version passes CI and is merged, visit the GitHub **Releases** page for the repository and download the asset named **Lucky_Game_Hub_V…_Full_Deploy.zip**.

1. Download the **Full_Deploy.zip** asset from GitHub Releases. Do not download GitHub's auto-generated source-code ZIP or the CI artifact wrapper.
2. Open HostGator File Manager and navigate to your **existing Lucky Game Hub installation directory**. Do not use the webroot of an unrelated site.
3. Upload that exact ZIP and select **Extract** once.
4. Confirm that index.php, admin.php, director.php, api/, lib/, public/ and storage/ are directly in the installation directory. There should be no extra enclosing folder.
5. Preserve the existing storage/config.php, user media/uploads and your database. Back up live site content before updating; do not delete user data or reinstall from scratch.

A release also includes SHA256SUMS.txt for integrity checks and BUILD_INFO.txt identifying the exact source commit.

GitHub Actions workflow artifacts remain available as an archival copy, but GitHub wraps them inside an outer ZIP. The **Release asset** is the direct deployment file meant for HostGator upload and extraction.

## Release integrity and safety

The build verifies ZIP CRCs and the expected application paths at the archive root before publishing. Release assets are versioned and are not overwritten by workflow reruns. Production installation and database migration remain separate administrator actions; run upgrade.php only when release notes explicitly call for a database migration.
