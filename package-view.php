<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';
require_once __DIR__.'/lib/PackageGameSandbox.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
try{
 $id=GameCatalog::slug($_GET['game']??null);
 $version=GamePackageInstaller::version($_GET['version']??null);
 $session=(string)($_GET['session']??'');$viewer=(string)($_GET['viewer']??'');
 if(!preg_match('/^[a-f0-9]{32}$/D',$session)||!preg_match('/^[a-f0-9]{48}$/D',$viewer))
  throw new DomainException('Invalid viewer authorization.',403);
 $row=sessionRow(db(),$session);
 if(!hash_equals((string)$row['viewer_token'],$viewer)||$row['game_id']!==$id)
  throw new DomainException('Game viewer is not authorized.',403);
 $state=stateFromRow($row);
 if(($state['packageVersion']??null)!==$version)
  throw new DomainException('Session is pinned to another game release.',403);
 if(($state['status']??null)==='ended')throw new DomainException('Finished session cannot load executable content.',403);
 $role=(string)($_GET['role']??'host');
 $markup=PackageGameSandbox::document($id,$version,$role);
 // An opaque iframe origin prevents access to the host's cookies, DOM and APIs.
 // All outbound resource and network access is blocked. Parent handles inputs.
 header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; media-src data:; font-src data:; connect-src 'none'; form-action 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; navigate-to 'none'");
 header('Content-Type: text/html; charset=utf-8');echo $markup;
}catch(Throwable $e){http_response_code(403);header('Content-Type: text/plain; charset=utf-8');echo 'Game view unavailable.';error_log('Package sandbox: '.$e->getMessage());}
