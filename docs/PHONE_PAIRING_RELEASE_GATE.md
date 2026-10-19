# V5P phone controller pairing — release gate

Status: **Source import pending; do not merge**.

The verified local V5P deployment package and V5P patch were built before this repository was created. The repository's `main` branch currently holds an initialization README only. This branch is not a substitute for importing the tested application source.

## Required work

1. Import the V5N PHP/MySQL application baseline and retain the original migration history.
2. Apply the V5P patch to that baseline, preserving the tested phone controller API, QR generation, host approval, pairing invitation expiry and revocation, and host-side command application.
3. Run PHP syntax checks, server-side game and pairing tests, browser/mobile tests, and security checks.
4. Confirm that commands cannot choose prize winners, bypass host approval, execute after revocation, or trigger duplicate spins.
5. Verify QR scanning and phone-to-TV pairing against the deployed PHP/MySQL backend.
6. Merge only after all required CI checks pass.

This file is an integration checklist, **not** a claim that V5P implementation has been pushed or tested on GitHub.
