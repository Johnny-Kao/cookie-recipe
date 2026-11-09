<?php
declare(strict_types=1);
require __DIR__ . '/regression.php';

class CountedRedis {
    private Redis $r;
    public int $smembers = 0;
    public int $sismember = 0;
    public function __construct() {
        $this->r = new Redis();
        $this->r->connect('127.0.0.1', 6379, 2.0);
    }
    public function pipeline(): self { $this->r->pipeline(); return $this; }
    public function sCard(string $key): int { return $this->r->sCard($key); }
    public function sismember(string $key, string $value) {
        $this->sismember++;
        return $this->r->sIsMember($key, $value);
    }
    public function smembers(string $key) {
        $this->smembers++;
        return $this->r->sMembers($key);
    }
    public function exec() { return $this->r->exec(); }
    public function discard() { return $this->r->discard(); }
}
function measure(string $className, array $sources, array $attributes): array {
    $proxy = new CountedRedis();
    $feed = new $className($proxy, $sources);
    $event = [];
    if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
    $before = memory_get_usage(false);
    $start = hrtime(true);
    $result = $feed->attachFeedCorrelations($attributes, user(), $event);
    $duration = (hrtime(true) - $start) / 1e6;
    return [
        'output' => [$result, $event],
        'ms' => round($duration,3),
        'smembers' => $proxy->smembers,
        'sismember' => $proxy->sismember,
        'peak_increment_bytes' => max(0, memory_get_peak_usage(false) - $before),
    ];
}
$all = [];
foreach ([[30,3],[100,5],[200,10]] as [$count, $sourceCount]) {
    $sources = [];
    for ($s=1; $s<=$sourceCount; $s++) {
        $sources[] = ['Feed'=>['id'=>$s,'name'=>"MISP $s",'source_format'=>'misp']];
    }
    $attrs=[]; $data=[];
    for ($h=0; $h<$count; $h++) {
        $value = "domain-$h.example.invalid";
        $attrs[] = ['type'=>'domain','value'=>$value];
        $data[] = ['keys'=>[$value], 'source_ids'=>range(1,$sourceCount), 'uuids'=>['00000000-0000-4000-8000-' . str_pad((string)$h,12,'0',STR_PAD_LEFT)]];
    }
    clean($r);
    fixture($r,'Feed',$sources,$data);
    $baseline = measure(BaselineFeed::class, $sources, $attrs);
    $candidate = measure(CandidateFeed::class, $sources, $attrs);
    if ($baseline['output'] !== $candidate['output']) throw new RuntimeException("Mismatch $count/$sourceCount");
    unset($baseline['output'], $candidate['output']);
    $all[] = ['hashes'=>$count,'sources'=>$sourceCount,'baseline'=>$baseline,'candidate'=>$candidate,'equivalent'=>true];
}
clean($r);
echo json_encode(['kind'=>'extracted-misp-method', 'results'=>$all, 'caveats'=>[
  'synthetic Redis fixture, not whole MISP runtime',
  'single timed sample, not statistical benchmark',
  'peak incremental memory reflects PHP allocator and order',
  'candidate cache remains unbounded'
]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";
