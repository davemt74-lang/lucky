# Lucky Game Hub — Signed Game Update Feed v1

The HomeServer remains **fully offline for gameplay**. Only an administrator who
explicitly connects an update channel and presses **Check for updates** causes an
outbound HTTPS request. No cloud database, account, telemetry, licensing check,
background polling, or remote command execution is involved.

## Connect a publisher

Admin Studio → Game Catalog → Cloud game updates. Enter:

1. An HTTPS feed URL on a public DNS hostname, using port 443;
2. The publisher's RSA (2048+ bit) or EC (256+ bit) PEM public signing key;
3. An optional bearer token for a private feed.

The saved connection lives in the installation's private storage directory
(`storage/game-updates/connection.json`). HomeServer maps its `storage/`
directory into `%LOCALAPPDATA%\\LuckyGameHub\\storage`. Disconnect erases
the stored connection and access token without changing installed games.
The API returns only the feed URL and public-key fingerprint, never the token.

## Feed transport

HTTPS GET, HTTP 200, no redirects, max 512 KiB, eight-second timeout, TLS
certificate verification. Hostnames resolving to private/reserved IPs are
rejected. Feed and package URLs must use the same HTTPS hostname.
This is an **update discovery protocol, not a game installer**.

The body is a JSON envelope:

```json
{
  "schema": "lucky.game-update-feed.v1",
  "payload": "<base64 of exact UTF-8 JSON payload bytes>",
  "signature": "<base64 of SHA-256 RSA/ECDSA signature over the exact payload bytes>"
}
```

Example decoded payload, before signing:

```json
{
  "issuedAt": "2026-10-20T00:00:00Z",
  "expiresAt": "2026-10-21T00:00:00Z",
  "releases": [
    {
      "game": "spin-wheel",
      "version": "5.22.1",
      "url": "https://publisher.example/releases/spin-wheel-5.22.1.zip",
      "sha256": "<64 lowercase hexadecimal characters>",
      "bytes": 123456,
      "notes": "Improved prize animations and controller controls."
    }
  ]
}
```

The payload is time-bound (at most 31 days old and 31 days validity), limited
to 100 releases, and uses semantic `major.minor.patch` release versions.
The decoded payload and its signature are verified using the *configured*
publisher public key before displaying any updates.

To generate the envelope, sign the raw bytes of the JSON payload with the
publisher's private key using RSA/ECDSA and SHA-256. In OpenSSL CLI:

```sh
openssl dgst -sha256 -sign publisher-private.pem -out payload.sig payload.json
```

Base64-encode the unmodified `payload.json` bytes and `payload.sig` bytes.
Never upload the private key to Lucky Game Hub or distribute it with packages.

## Built-in Spin the Wheel

The bundled Spin the Wheel module is a protected catalog entry. Its metadata
and artwork are editable; it cannot be removed through catalog administration.
A signed feed can advertise a future release of Spin the Wheel. **This version
only verifies and lists release metadata**—it does not download, install or
activate new executable game code. Future installer certification will include
archive validation, trusted publisher verification, compatibility enforcement,
rollback, and saved-session preservation before enabling installation.

## Security

This is for trusted publishers, not arbitrary unsigned PHP plug-ins.
A future PHP game package can execute server-side code, so no package may run
merely by appearing in a remote feed. Never expose the HomeServer's LAN UI or
database to the public internet to enable update checks.


## V5Q.22F — Download and stage from trusted cloud

After checking for updates, an admin can click **Download & stage verified ZIP**
on a listed version. HomeServer then re-fetches the publisher feed,
cryptographically verifies its signature again, selects the requested
game/version server-side, connects to the public publisher over TLS with DNS
pinning and no redirects, and checks the exact advertised ZIP byte length and
SHA-256 before passing it to the same private offline package validator used
by manual uploads.

The browser never supplies a download URL, token or checksum to this endpoint.
All download requests are manual, require admin CSRF authorization, and
reuse the existing pinned publisher key. Maximum package size: 40 MiB.

**Importing still does not activate or execute game code.** Staged versions
remain immutable in local HomeServer storage. A new runtime certification
phase is required for game-specific host/phone execution, shared sessions
and activation/rollback. No cloud connection is required for normal games.
