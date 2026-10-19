<?php
declare(strict_types=1);
/** Each new game is a module with its own rules, state, and public view. */
interface GameModule {
 public function id():string;
 public function manifest():array;
 public function defaults():array;
 public function validateSettings(array $settings):array;
 public function createSession(array $settings,string $id):array;
 public function applySettings(array &$session,array $settings):void;
 public function action(array &$session,string $verb):array;
 public function publicView(array $session):array;
}
