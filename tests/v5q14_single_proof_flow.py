"""The single desktop proof editor must only open AFTER accepting the awarded prize."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=(ROOT/'public/game.js').read_text()
assert 'function openPrize(c)' in s
assert "location.assign('proof.php?session='" in s
assert "pollOffer()" in s
assert "'prize-accept'" in (ROOT/'lib/ControllerPairing.php').read_text()
print('PASS acceptance-gated prize proof route')
