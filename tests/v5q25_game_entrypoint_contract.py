"""Static cross-entry-point checks. Real MariaDB concurrency remains a separate CI gate."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def read(path):return (ROOT/path).read_text()

checks={
 'Current-state reader only permits active/paused':"status IN ('active','paused')" in read('lib/CurrentGame.php'),
 'Desktop current API uses live reader':"CurrentGame::row($db,$game)" in read('api/index.php'),
 'Desktop host no longer opens history as current':"L.api('current')" in read('public/game.js') and "L.api('last')" not in read('public/game.js'),
 'Phone game snapshot uses current, not historical':"$row=self::current($db);" in read('lib/ControllerGame.php'),
 'Host start locks console and checks global vacancy':"GameSessionLifecycle::lockConsole($db);\n   $config=GameSessionLifecycle::lockGame" in read('api/index.php') and "GameSessionLifecycle::assertConsoleVacant($db);" in read('api/index.php'),
 'Saved-game resume follows console lock':"if($action==='resume_saved')GameSessionLifecycle::lockConsole($db);" in read('api/index.php') and "GameSessionLifecycle::assertConsoleVacant($db,$id)" in read('api/index.php'),
 'Direct phone start checks all running games':"GameSessionLifecycle::assertConsoleVacant($db);" in read('lib/ControllerGame.php'),
 'Phone dashboard launch shares console lock':"GameSessionLifecycle::lockConsole($db);\n  GameSessionLifecycle::assertConsoleVacant($db);" in read('lib/PhoneGameDashboard.php'),
 'Idle host never renders old claims':"const actualClaims=live?(data?.session?.claims||[]):[];" in read('public/game.js'),
 'Host expands package chooser when no active session':".game-no-live-session .host-workspace{grid-template-columns:minmax(0,1fr)}" in read('public/platform.css'),
 'Phone uses explicit session proof information':"proofOffer=proof&&game?.offer?.status==='accepted'" in read('public/phone-controller.js'),
 'Game end preserves claimed prizes in saved session history':"GameSessionLifecycle::FINISHED" in read('docs/GAME_SYSTEM_LIFECYCLE_AUDIT_V5Q25.md') if (ROOT/'docs/GAME_SYSTEM_LIFECYCLE_AUDIT_V5Q25.md').exists() else False,
}
for label,passed in checks.items():print(('PASS ' if passed else 'FAIL ')+label)
print('V5Q.25 cross-entry-point gates:',sum(checks.values()),'/',len(checks))
if not all(checks.values()):raise SystemExit(1)
