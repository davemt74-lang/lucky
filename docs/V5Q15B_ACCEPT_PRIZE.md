# V5Q.15B — Prize acceptance before proof

Awards create a pending decision in `prize_decisions`. While pending, no further spin is allowed. The paired phone replaces gameplay controls with **Accept Prize** and **Decline · End Game**. Desktop controls provide a fallback. Accept opens the single desktop proof editor for the exact claim. Decline ends the session but preserves the award and decision for audit. Uses transactions and idempotent command nonces. Run `upgrade.php` to apply `v5q15b_prize_decisions.sql`. Production MySQL and physical phone testing remains outstanding.
