<?php
declare(strict_types=1);
// Independent, read-only-semantics probe: pipelined SISMEMBER versus SMISMEMBER.
// No MISP application logic or candidate method is modified.
if (!class_exists('Redis')) throw new RuntimeException('phpredis unavailable');
$r = new Redis();
$r->connect('127.0.0.1',6379,2);
$key='cookie-recipe:probe:'.bin2hex(random_bytes(6));
try {
    foreach (range(0,799) as $i) if ($i%3===0) $r->sAdd($key, "hash-$i");
    $scenarios=[10,100,400,800];
    $results=[];
    $supports=method_exists($r,'sMIsMember');
    foreach ($scenarios as $n) {
        $members=[];
        for ($i=0;$i<$n;$i++) $members[]='hash-'.($i%max(1,intdiv($n,2)));
        $baseSamples=[]; $batchedSamples=[];
        for ($repeat=0;$repeat<9;$repeat++) {
            $start=hrtime(true);
            $pipe=$r->pipeline();
            foreach ($members as $m) $r->sIsMember($key,$m);
            $base=$pipe->exec();
            $baseSamples[]=(hrtime(true)-$start)/1e6;

            $start=hrtime(true);
            $bulk = $r->rawCommand('SMISMEMBER', $key, ...$members);
            $batchedSamples[]=(hrtime(true)-$start)/1e6;
            if (!is_array($bulk)) throw new RuntimeException('SMISMEMBER failed');
            $normalized=array_map(static fn($x)=>(int)(bool)$x,$base);
            $bulk=array_map('intval',$bulk);
            if ($normalized!==$bulk) throw new RuntimeException("Mismatch on $n");
        }
        sort($baseSamples); sort($batchedSamples);
        $results[]=['members'=>$n,'distinct_members'=>count(array_unique($members)),'baseline_commands'=>$n,'batch_commands'=>1,
            'baseline_median_ms'=>round($baseSamples[4],3),'batch_median_ms'=>round($batchedSamples[4],3),'equal'=>true];
    }
    echo json_encode(['status'=>'PASS','redis_version'=>$r->info('server')['redis_version']??'unknown','native_method_available'=>$supports,
      'results'=>$results,'caveat'=>'synthetic membership only; no MISP app integration, no server concurrency equivalence claim'],
      JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
} finally {
    $r->del($key);
}
