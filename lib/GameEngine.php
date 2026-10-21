<?php
declare(strict_types=1);
require_once __DIR__.'/SpinReveal.php';

/** Stateless, deterministic game rules. Database transactions protect calls to these methods. */
final class GameEngine {
    public const TIERS = ['tier1','tier2','bonus'];
    public const DEFAULT_ODDS = ['bonusChance'=>12,'tier1Weight'=>1,'tier2Weight'=>1,'bonusWeight'=>1];
    public static function manifest(): array { return [
        ['id'=>'spin-wheel','name'=>'Spin the Wheel','description'=>'Evolving prizes: Tier 1 → Tier 2 → Bonus','status'=>'ready','path'=>'game.php?game=spin-wheel'],
        ['id'=>'game-2','name'=>'Game 2','description'=>'Reserved for the next game','status'=>'upcoming','path'=>null]
    ]; }
    public static function defaultPools(): array {
        $names=['Gift Card','Store Credit','Drink Voucher','Mystery Box','Shipping Credit','Merch Pack','Discount Voucher','Grand Prize'];
        $tier1=[];
        foreach ($names as $i=>$name) $tier1[]=['id'=>'t1-'.($i+1),'name'=>$name,'cents'=>3750,'url'=>'','image'=>''];
        return ['tier1'=>['budgetCents'=>30000,'prizes'=>$tier1],
                'tier2'=>['budgetCents'=>0,'prizes'=>[]], 'bonus'=>['budgetCents'=>0,'prizes'=>[]]];
    }
    public static function defaults(): array {return ['pools'=>self::defaultPools(),'odds'=>self::DEFAULT_ODDS];}
    public static function validateConfig(array $config): array {
        require_once __DIR__.'/PrizeSmartText.php';
        $pools=$config['pools']??null; $odds=$config['odds']??self::DEFAULT_ODDS;
        if (!is_array($pools) || !is_array($odds)) throw new DomainException('Invalid game configuration.');
        $used=[];$validated=[];
        foreach(self::TIERS as $tier){
            $pool=$pools[$tier]??null;
            if(!is_array($pool) || !isset($pool['prizes']) || !is_array($pool['prizes']) || count($pool['prizes'])>24 ||
               !isset($pool['budgetCents']) || !is_int($pool['budgetCents']) || $pool['budgetCents']<0 || $pool['budgetCents']>1000000000)
                throw new DomainException('Invalid '.$tier.' budget or prize list.');
            $sum=0;$items=[];
            foreach($pool['prizes'] as $p){
                if(!is_array($p)) throw new DomainException('Invalid prize.');
                $id=$p['id']??null;$name=$p['name']??null;$amount=$p['cents']??null;
                if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)||isset($used[$id]) ||
                   !is_string($name)||trim($name)===''||strlen($name)>160 ||
                   !is_int($amount)||$amount<0||$amount>1000000000) throw new DomainException('Invalid or duplicate prize.');
                $url=$p['url']??''; $image=$p['image']??''; $videoUrl=$p['videoUrl']??'';
                $description=PrizeSmartText::validate($p['description']??'');
                if(!is_string($url)||!self::validUrl($url)||!is_string($image)||!self::validUrl($image)||!is_string($videoUrl)||!self::validUrl($videoUrl))
                   throw new DomainException('Prize links must be blank or valid HTTP(S) URLs.');
                $angles=$p['proofAngles']??[];
                if(!is_array($angles)||count($angles)>4)throw new DomainException('Proof angles must have 0–4 camera views.',422);
                $proof=[];
                foreach($angles as $entry){
                    if(!is_array($entry))throw new DomainException('Invalid proof angle.',422);
                    $label=trim((string)($entry['label']??''));$seconds=$entry['seconds']??8;
                    if($label===''||(function_exists('mb_strlen')?mb_strlen($label,'UTF-8'):strlen($label))>80||preg_match('/[\x00-\x1f\x7f]/',$label)||!is_int($seconds)||$seconds<3||$seconds>30)
                        throw new DomainException('Proof angle labels must be 1–80 characters and timings 3–30 seconds.',422);
                    $proof[]=['label'=>$label,'seconds'=>$seconds];
                }
                $used[$id]=true;$sum+=$amount;
                $items[]=['id'=>$id,'name'=>trim($name),'cents'=>$amount,'url'=>$url,'image'=>$image,'videoUrl'=>$videoUrl,'description'=>$description,'proofAngles'=>$proof];
            }
            if($sum>$pool['budgetCents']) throw new DomainException($tier.' prizes exceed their budget.');
            $validated[$tier]=['budgetCents'=>$pool['budgetCents'],'prizes'=>$items];
        }
        $o=[];
        foreach(['tier1Weight','tier2Weight','bonusWeight'] as $key){
            $n=$odds[$key]??null;
            if(!is_numeric($n)||!is_finite((float)$n)||(float)$n<0||(float)$n>10) throw new DomainException('Invalid game odds.');
            $o[$key]=(float)$n;
        }
        $n=$odds['bonusChance']??null;
        if(!is_numeric($n)||!is_finite((float)$n)||(float)$n<0||(float)$n>100) throw new DomainException('Invalid bonus probability.');
        $o['bonusChance']=(float)$n;
        return ['pools'=>$validated,'odds'=>$o];
    }
    public static function validUrl(string $url):bool {
        if($url==='')return true;
        if(strlen($url)>2048 || preg_match('/[\x00-\x20\x7f]/',$url))return false;
        $parts=parse_url($url);
        return is_array($parts)&&in_array(strtolower($parts['scheme']??''),['https','http'],true)&&
          !empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass']);
    }
    public static function newSession(array $config, string $id): array {
        $cfg=self::validateConfig($config);
        $slots=[];
        foreach($cfg['pools']['tier1']['prizes'] as $prize) $slots[]=['tier'=>'tier1','prizeId'=>$prize['id'],'pending'=>null];
        return ['id'=>$id,'game'=>'spin-wheel','status'=>'active','version'=>1,'pools'=>$cfg['pools'],'odds'=>$cfg['odds'],
            'slots'=>$slots,'claims'=>[],'pendingBonus'=>false,'spinCount'=>0,'lastSpin'=>null,'createdAt'=>gmdate('c'),'endedAt'=>null];
    }
    private static function prize(array $s, string $tier, string $id): ?array {
        foreach($s['pools'][$tier]['prizes'] as $p)if($p['id']===$id)return $p;
        return null;
    }
    private static function claimedIds(array $s):array {return array_fill_keys(array_column($s['claims'],'prizeId'),true);}
    private static function occupiedIds(array $s):array {return array_fill_keys(array_filter(array_column($s['slots'],'prizeId')),true);}
    private static function availablePrize(array $s,string $tier):?array {
        $claimed=self::claimedIds($s);$occupied=self::occupiedIds($s);
        foreach($s['pools'][$tier]['prizes'] as $p){if($p['cents']>0&&!isset($claimed[$p['id']])&&!isset($occupied[$p['id']]))return $p;}
        return null;
    }
    private static function promote(array &$s, int $index): void {
        $slot=&$s['slots'][$index];
        if(!isset($slot['pending'])||!in_array($slot['pending'],['tier2','bonus'],true))return;
        $prize=self::availablePrize($s,$slot['pending']);
        if($prize!==null){$slot['tier']=$slot['pending'];$slot['prizeId']=$prize['id'];$slot['pending']=null;}
    }
    public static function replenish(array &$s):void {foreach(array_keys($s['slots']) as $i)self::promote($s,$i);}
    /** Changes to an active game's prize pools cannot remove or reprice already claimed prizes. */
    public static function applyConfig(array &$s,array $new):void {
        $new=self::validateConfig($new);
        foreach($s['claims'] as $claim){
            $p=self::prize(['pools'=>$new['pools']],$claim['tier'],$claim['prizeId']);
            if($p===null||$p['name']!==$claim['name']||$p['cents']!==$claim['cents'])
                throw new DomainException('Cannot delete or change an awarded prize while this game is active.');
        }
        foreach($s['slots'] as $slot){if(!empty($slot['prizeId']) && !self::prize(['pools'=>$new['pools']],$slot['tier'],$slot['prizeId']))
            throw new DomainException('Cannot delete a prize currently on the wheel.');}
        $existingRoots=array_fill_keys(array_column($s['slots'],'prizeId'),true);
        $claimed=self::claimedIds($s);
        foreach($new['pools']['tier1']['prizes'] as $p){
            if(!isset($existingRoots[$p['id']])&&!isset($claimed[$p['id']])){
                $s['slots'][]=['tier'=>'tier1','prizeId'=>$p['id'],'pending'=>null];
            }
        }
        $s['pools']=$new['pools'];$s['odds']=$new['odds'];self::replenish($s);$s['version']++;
    }
    public static function weightedPick(array $entries, float $seed): ?array {
        $total=array_sum(array_column($entries,'weight'));if($total<=0)return null;
        $target=$seed*$total;
        foreach($entries as $item){$target-=$item['weight'];if($target<0)return $item;}
        return end($entries) ?: null;
    }
    public static function spin(array &$s,?float $forcedBonusSeed=null,?float $forcedPickSeed=null): array {
        if($s['status']!=='active')throw new DomainException('Start a new game before spinning.');
        $s['spinCount']++;
        $claimed=self::claimedIds($s);
        $bonusMode=$s['pendingBonus'];
        $beforeSlices=[];
        if($bonusMode){foreach($s['pools']['bonus']['prizes'] as $p)if($p['cents']>0&&!isset($claimed[$p['id']]))$beforeSlices[]=['name'=>$p['name'],'cents'=>$p['cents'],'tier'=>'bonus','kind'=>'prize','prizeId'=>$p['id']];}
        else{foreach($s['slots'] as $slot){$p=empty($slot['prizeId'])?null:self::prize($s,$slot['tier'],$slot['prizeId']);$beforeSlices[]=['name'=>$p['name']??'WAITING','cents'=>$p['cents']??0,'tier'=>$slot['tier']??'','kind'=>$p?'prize':'empty','prizeId'=>$p['id']??null];}}
        if($s['pendingBonus']){
            $bonus=[];foreach($s['pools']['bonus']['prizes'] as $p)if($p['cents']>0&&!isset($claimed[$p['id']]))$bonus[]=$p;
            if(!$bonus){$s['pendingBonus']=false;throw new DomainException('No available bonus prizes.');}
            $pick=$bonus[random_int(0,count($bonus)-1)];$tier='bonus';$slotIndex=null;
            foreach($s['slots'] as $i=>$slot)if($slot['prizeId']===$pick['id']){$slotIndex=$i;break;}
            $s['pendingBonus']=false;
        }else{
            $bonusAvailable=false;
            foreach($s['pools']['bonus']['prizes'] as $p)if($p['cents']>0&&!isset($claimed[$p['id']])){$bonusAvailable=true;break;}
            $main=[];
            foreach($s['slots'] as $i=>$slot){
                if(empty($slot['prizeId']))continue;
                $p=self::prize($s,$slot['tier'],$slot['prizeId']);
                if($p && $p['cents']>0&&!isset($claimed[$p['id']])){
                    $w=$s['odds'][$slot['tier'].'Weight'];if($w>0)$main[]=['tier'=>$slot['tier'],'slot'=>$i,'prize'=>$p,'weight'=>$w];
                }
            }
            if($bonusAvailable && $s['odds']['bonusChance']>0 && ($main===[] || ($forcedBonusSeed??random_int(0,1000000)/1000001) < $s['odds']['bonusChance']/100)){
                $beforeSlices[]=['name'=>'BONUS ROUND','cents'=>0,'tier'=>'bonus','kind'=>'trigger','prizeId'=>null];
                $s['pendingBonus']=true;$s['version']++;
                $s['lastSpin']=['type'=>'bonus_trigger','index'=>count($beforeSlices)-1,'slices'=>$beforeSlices,'at'=>gmdate('c'),'spinCount'=>$s['spinCount']];
                return ['type'=>'bonus_trigger','version'=>$s['version'],'spinCount'=>$s['spinCount'],'animation'=>$s['lastSpin']];
            }
            if(!$main)throw new DomainException('No eligible prizes remain.');
            $choice=self::weightedPick($main,$forcedPickSeed??random_int(0,1000000)/1000001);
            $pick=$choice['prize'];$tier=$choice['tier'];$slotIndex=$choice['slot'];
        }
        if(!$bonusMode){
            $bonusReady=false;foreach($s['pools']['bonus']['prizes'] as $p)if($p['cents']>0&&!isset($claimed[$p['id']])){$bonusReady=true;break;}
            if($bonusReady && $s['odds']['bonusChance']>0)$beforeSlices[]=['name'=>'BONUS ROUND','cents'=>0,'tier'=>'bonus','kind'=>'trigger','prizeId'=>null];
        }
        $index=$bonusMode?array_search($pick['id'],array_column($beforeSlices,'prizeId'),true):$slotIndex;
        $s['lastSpin']=['type'=>'award','index'=>$index,'slices'=>$beforeSlices,'at'=>gmdate('c'),'spinCount'=>$s['spinCount']];
        $claim=['id'=>bin2hex(random_bytes(16)),'prizeId'=>$pick['id'],'tier'=>$tier,'name'=>$pick['name'],
            'cents'=>$pick['cents'],'url'=>$pick['url'],'image'=>$pick['image'],'videoUrl'=>$pick['videoUrl']??'','description'=>$pick['description']??'','slot'=>$slotIndex===null?null:$slotIndex+1,
            'at'=>gmdate('c'),'method'=>'spin'];
        $s['claims'][]=$claim;
        if($slotIndex!==null){
            $next= $tier==='tier1'?'tier2':($tier==='tier2'?'bonus':null);
            $s['slots'][$slotIndex]=['tier'=>null,'prizeId'=>null,'pending'=>$next];
            if($next)self::promote($s,$slotIndex);
        }
        $s['version']++;
        return ['type'=>'award','claim'=>$claim,'version'=>$s['version'],'spinCount'=>$s['spinCount'],'animation'=>$s['lastSpin']];
    }
    /** Import a trusted user's previous browser session as an immutable ended archive. */
    public static function importLegacy(array $legacy,string $newId):array {
        $config=['pools'=>$legacy['pools']??null,'odds'=>$legacy['odds']??self::DEFAULT_ODDS];
        $s=self::newSession($config,$newId);
        $oldClaims=$legacy['claims']??[];
        if(!is_array($oldClaims)||count($oldClaims)>72)throw new DomainException('Invalid legacy claims list.');
        $seen=[];
        // V5 stores newest first. Replay oldest first so promotions remain faithful.
        foreach(array_reverse($oldClaims) as $c){
            if(!is_array($c)||!in_array($c['tier']??null,self::TIERS,true)||!is_string($c['prizeId']??null))throw new DomainException('Invalid prior claim.');
            $p=self::prize($s,$c['tier'],$c['prizeId']);
            if(!$p||isset($seen[$p['id']]))throw new DomainException('Previous game has an invalid or duplicate prize claim.');
            $at=strval($c['at']??'');if(strtotime($at)===false)throw new DomainException('Invalid historic claim timestamp.');
            $slotIdx=null;
            foreach($s['slots'] as $i=>$slot){if($slot['prizeId']===$p['id']){$slotIdx=$i;break;}}
            $claim=['id'=>bin2hex(random_bytes(16)),'prizeId'=>$p['id'],'tier'=>$c['tier'],'name'=>$p['name'],'cents'=>$p['cents'],
                'url'=>$p['url'],'image'=>$p['image'],'videoUrl'=>$p['videoUrl']??'','description'=>$p['description']??'','slot'=>$slotIdx===null?null:$slotIdx+1,'at'=>gmdate('c',strtotime($at)),'method'=>'import'];
            $s['claims'][]=$claim;$seen[$p['id']]=true;
            if($slotIdx!==null){$next=$c['tier']==='tier1'?'tier2':($c['tier']==='tier2'?'bonus':null);
                $s['slots'][$slotIdx]=['tier'=>null,'prizeId'=>null,'pending'=>$next];if($next)self::promote($s,$slotIdx);}
        }
        $s['spinCount']=count($s['claims']);self::end($s);return $s;
    }
    public static function pause(array &$s):void {if($s['status']!=='active')throw new DomainException('Can only pause an active game.');$s['status']='paused';$s['version']++;}
    public static function resume(array &$s):void {if($s['status']!=='paused')throw new DomainException('Can only resume a paused game.');$s['status']='active';$s['version']++;}
    public static function end(array &$s):void {$s['status']='ended';$s['endedAt']=gmdate('c');$s['version']++;}
    public static function publicView(array $s):array {
        return ['id'=>$s['id'],'game'=>$s['game'],'status'=>$s['status'],'version'=>$s['version'],'slots'=>array_map(
            function($slot)use($s){$p=$slot['prizeId']?self::prize($s,$slot['tier'],$slot['prizeId']):null;
                return ['tier'=>$slot['tier'],'name'=>$p['name']??'Waiting for replacement','value'=>$p['cents']??0,'pending'=>$slot['pending']];},$s['slots']),
          'pendingBonus'=>$s['pendingBonus'],'claims'=>array_map(fn($c)=>['name'=>$c['name'],'tier'=>$c['tier'],'cents'=>$c['cents'],'at'=>$c['at'],'slot'=>$c['slot']],$s['claims']),
          'spinCount'=>$s['spinCount'],'lastSpin'=>$s['lastSpin']??null,'spinPending'=>SpinReveal::pending($s),
          'bonusSlices'=>array_values(array_map(fn($p)=>['name'=>$p['name'],'cents'=>$p['cents'],'tier'=>'bonus','kind'=>'prize'],
              array_filter($s['pools']['bonus']['prizes'],fn($p)=>$p['cents']>0&&!in_array($p['id'],array_column($s['claims'],'prizeId'),true)))),
          'bonusEnabled'=>$s['odds']['bonusChance']>0,
          'createdAt'=>$s['createdAt'],'endedAt'=>$s['endedAt'],'prizePackage'=>$s['prizePackage']??null];
    }
}
