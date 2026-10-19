# Voice and audio controls

Enable listening from the game's Music & Voice panel. Browser permission is required and recognition is not available on every device; Chrome/Edge with HTTPS are the recommended starting point. Speaking is optional: the corresponding buttons remain usable. Live microphone input is **not yet certified** on an actual host.

| Say | Intended action |
| --- | --- |
| Start game / Begin game | Start a new session |
| Spin / Spin again / Respin | Start an eligible spin, ignoring commands while animating |
| End game | Request session end; confirmation is required |
| Confirm / Cancel | Confirm or dismiss the pending action |
| Close prize / Continue | Close the winner popup or return from a bonus reveal |
| What's left / How many prizes are left | Announce remaining prizes |
| Music on / Music off | Toggle background soundtrack |
| Play music / Pause music | Play or pause the selected track |
| Next song / Previous song | Select adjacent tracks |
| Play [song name] | Select a named library song |
| Music volume up / down | Change music volume in steps |
| Set music volume to 50 percent | Set an absolute music volume |
| Effects volume to 40 percent | Set effects volume |
| Voice volume to 80 percent | Set spoken announcement volume |
| Master volume to 60 percent | Adjust overall audio |
| Mute / Unmute | Silence or restore audio |
| Sound effects on / off | Toggle wheel/success effects |
| Announcements on / off | Toggle spoken prize announcements |
| Game hub / Prize studio / Claimed list / Music library | Navigate to platform areas (authorization still enforced) |
| Stop listening | Turn off microphone recognition |
| Voice help | Announce available command groups |

Voice commands are interpreted by `public/voice-commands.js`, then acted on by the host page. Server authorization still applies; voice commands cannot bypass login or confirmation. Browsers may block autoplay or require an explicit user gesture before sound begins. Spoken announcements rely on available device text-to-speech voices; voice recognizer availability is browser-dependent. Song titles are matched from the current shared track library, not generated or inferred.
