# V5P.2 — Controller navigation and voice

## Scope

- Shared focus-navigation engine (`public/console.js`) works on game screens, prize popups, the home console, settings and admin pages. Arrow keys / phone D-pad select a visible, enabled control; Enter / phone Select activates it; Back / Escape exits popups and menus; arrow keys adjust focused range sliders.
- Controller UI commands are never interpreted as prize selection. Phone `select` is a screen command; only server-side Spin can award a prize. Explicit server-authorized Start/Spin/Pause/Resume/End are unchanged.
- The Win popup supports **Done** via on-screen button, phone controller, voice command, or text composer. Done dismisses a prize or menu. It neither ends the session nor awards another prize.
- Phone controller gets a ChatGPT-inspired sticky composer, text-command transcript, microphone toggle, and speech-to-command mapping. Supported spoken commands include Start, Spin, Spin Again, Done, Select, directions, Pause/Resume, End, music commands, song by name, volume, mute, effects and navigation.
- The microphone remains off by default; recognition uses browser SpeechRecognition when available and requires HTTPS/localhost and explicit browser permission. Browsers without SpeechRecognition can use text entry and buttons.
- The phone reports an interface action as **completed** only after the game/TV browser acknowledges it; unsupported commands now return an explicit failure receipt rather than silently expiring.

## Known limitations / release gate

- This is a deterministic command composer, not an AI assistant or arbitrary conversation service.
- Remote directional input requires an active authorized phone pair and an open Host Game or TV screen to receive commands. Server-only gameplay commands work without the host tab.
- Live end-to-end HTTPS, hosted MySQL, phone voice permissions, real hardware keyboard/gamepad and simultaneous-host display testing are required before production sign-off. Automated browser checks use a mock server.
- This ZIP is not yet merged in GitHub; the Lucky repository PR #1 is a draft initialization checklist, not a completed code PR. Do not equate local checks with merged CI.

## Validation commands

```
php tests/engine.php
php tests/catalog.php
php tests/pairing.php
python tests/browser.py
python tests/v5n_console.py
python tests/v5p_pairing.py
python tests/v5p_sync.py
python tests/v5p_button_sync.py
python tests/v5p2_navigation_voice.py
```
