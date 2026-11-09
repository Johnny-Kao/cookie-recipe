<?php
declare(strict_types=1);
require __DIR__ . '/regression.php';

// Inject a deterministic Redis write between the two sources' reads.
class InjectedRedis {
    private Redis $r;
    private Redis $writer;
    private string $key;
    private int $execs = 0;
    public bool $injected = false;
    public array $operations = [];
    public function __construct(string $key) {
        $this->key = $key;
        $this->r = new Redis();
        $this->r->connect('127.0.0.1', 6379);
        $this->writer = new Redis();
        $this->writer->connect('127.0.0.1', 6379);
    }
    public function sCard($key) {
        $this->operations[] = 'SCARD '.$key;
        return $this->r->sCard($key);
    }
    public function pipeline(): self { $this->operations[] = 'PIPELINE'; $this->r->pipeline(); return $this; }
    public function sismember($key,$member) {
        $this->operations[] = 'SISMEMBER '.$key.' '.$member;
        return $this->r->sIsMember($key,$member);
    }
    public function smembers($key) {
        $this->operations[] = 'SMEMBERS '.$key;
        return $this->r->sMembers($key);
    }
    public function exec() {
        $this->operations[] = 'EXEC';
        $this->execs++;
        $result = $this->r->exec();
        if ($this->execs === 4) {
            $this->writer->sAdd($this->key, '2/00000000-0000-4000-8000-000000000099');
            $this->injected = true;
            $this->operations[] = 'INJECT SADD';
        }
        return $result;
    }
    public function discard() { $this->operations[] = 'DISCARD'; return $this->r->discard(); }
}
$sources = [
    ['Feed'=>['id'=>1,'name'=>'Feed 1','source_format'=>'misp']],
    ['Feed'=>['id'=>2,'name'=>'Feed 2','source_format'=>'misp']]
];
$attrs = [['type'=>'domain','value'=>'example.org']];
$data = [['keys'=>['example.org'],'source_ids'=>[1,2]]];
$results = [];
foreach ([BaselineFeed::class, CandidateFeed::class] as $kind) {
    clean($r);
    fixture($r,'Feed',$sources,$data);
    $proxy = new InjectedRedis('misp:feed_cache:event_uuid_lookup:' . md5('example.org'));
    $object = new $kind($proxy,$sources);
    $event = [];
    $result = $object->attachFeedCorrelations($attrs,user(),$event);
    if (!$proxy->injected) throw new RuntimeException('Mutation injection did not execute');
    $results[$kind] = ['output'=>[$result,$event], 'operations'=>$proxy->operations];
}
clean($r);
if ($results[BaselineFeed::class] !== $results[CandidateFeed::class]) {
    fwrite(STDERR, "FAIL: original and candidate differ under concurrent cache update\n");
    exit(1);
}
$final = $results[CandidateFeed::class]['output'][1];
if (!in_array('00000000-0000-4000-8000-000000000099', $final['Feed'][1]['event_uuids'] ?? [], true)) {
    fwrite(STDERR, "FAIL: later source did not receive injected UUID\n");
    exit(1);
}
echo "PASS: concurrent Redis update, outputs and exact Redis call order identical\n";
