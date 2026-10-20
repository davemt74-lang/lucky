<?php
declare(strict_types=1);
/**
 * Optional phone controller contract. GameModule implementations do NOT have
 * to implement this interface. No controller is the default.
 *
 * Actions describe UI affordances, not authorization. Every command must
 * still be checked and executed server-side by that game's command handler.
 */
interface PhoneControllerProvider {
 public function phoneController():array;
}
