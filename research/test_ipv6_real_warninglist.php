<?php
declare(strict_types=1);
require __DIR__.'/../build/CidrToolOriginal.php';
require __DIR__.'/../build/CidrToolIndexed.php';
$raw=file_get_contents($argv[1]??'');
if($raw===false)throw new RuntimeException('missing pinned real warninglist');
$document=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
if(($document['type']??'')!=='cidr')throw new RuntimeException('Unexpected warninglist type');
$list=$document['list'];
$original=new CidrToolOriginal($list);
$indexed=new CidrToolIndexed($list);
$queries=['::','::1','2001:db8::1','ffff::1','fd00::1','fe80::1','::ffff:192.0.2.1','bad','2001:db8::/32','2001:db8::/128','2001:db8::/0'];
for($i=0;$i<500;$i++){
    $parts=explode('/',$list[($i*37)%count($list)],2);
    $queries[]=$parts[0];
    if($i%7===0)$queries[]=$parts[0].'/128';
    $queries[]=inet_ntop(pack('n8',0xfd00,$i%65536,($i*19)%65536,($i*7)%65536,($i*23)%65536,($i*11)%65536,($i*29)%65536,($i*31)%65536));
}
$hits=0;
foreach($queries as $i=>$q){
    $a=$original->contains($q);
    $b=$indexed->contains($q);
    if($a!==$b)throw new RuntimeException('Real-list output mismatch '.$i.' '.json_encode($q).' a='.var_export($a,true).' b='.var_export($b,true));
    if($a!==false)$hits++;
}
echo 'PASS real datacenter IPv6 warninglist '.count($list).' CIDRs, '.count($queries).' identical lookups, '. $hits ." hits\n";
function median(array $v):float {sort($v);return $v[intdiv(count($v),2)];}
function times($a,$b,$queries):array {
    $samples=['baseline'=>[],'indexed'=>[]];
    foreach(range(0,5) as $r) {
        foreach(($r%2===0?['baseline','indexed']:['indexed','baseline']) as $name){
            $obj=$name==='baseline'?$a:$b;
            $start=hrtime(true);
            foreach($queries as $q)$obj->contains($q);
            $samples[$name][]=(hrtime(true)-$start)/1e6;
        }
    }
    return [median($samples['baseline']),median($samples['indexed'])];
}
[$old,$new]=times($original,$indexed,$queries);
function buildProfile(string $cls,array $list):array {
    gc_collect_cycles();
    $before=memory_get_usage(false);
    $start=hrtime(true);
    $obj=new $cls($list);
    $ms=(hrtime(true)-$start)/1e6;
    $used=memory_get_usage(false)-$before;
    unset($obj);
    return [round($ms,3),$used];
}
[$originalBuild,$originalBytes]=buildProfile(CidrToolOriginal::class,$list);
[$indexedBuild,$indexedBytes]=buildProfile(CidrToolIndexed::class,$list);
echo json_encode(['dataset'=>'MISP/misp-warninglists datacenter-ipv6','cidrs'=>count($list),'queries'=>count($queries),'hits'=>$hits,'baseline_lookup_ms'=>round($old,3),'indexed_lookup_ms'=>round($new,3),'lookup_speedup'=>round($old/$new,2),'baseline_build_ms'=>$originalBuild,'indexed_build_ms'=>$indexedBuild,'baseline_object_bytes'=>$originalBytes,'indexed_object_bytes'=>$indexedBytes,'memory_multiplier'=>round($indexedBytes/max(1,$originalBytes),2)],JSON_UNESCAPED_SLASHES)."\n";
