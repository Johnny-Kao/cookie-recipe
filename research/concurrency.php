<?php
declare(strict_types=1);
require __DIR__ . '/regression.php';

class InjectedRedis {
    private Redis $r;
    private Redis $writer;
    private string $key;
    private int $execs = 0;
    public function __construct(string $key) {
        $this->key = $key;
        $this->r = new Redis(); $this->r->connect('127.0.0.1', 6379);
        $this->writer = new Redis(); $this->writer->connect('127.0.0.1', 6379);
    }
    public function sCard($key) { return $this->r->sCard($key); }
    public function pipeline(): self { $this->r->pipeline(); return $this; }
    public function sismember($key,$member) { return $this->r->sIsMember($key,$member); }
    public function smembers($key) { return $this->r->sMembers($key); }
    public function exec() {
        $this->execs++;
        $result = $this->r->exec();
        // A writer updates the global UUID set after source 2's SISMEMBER
        // pipeline completes, and before source 2's UUID query would begin.
        if ($this->execs === 4) {
            $this->writer->sAdd($this->key, '2/00000000-0000-4000-8000-000000000099');
        }
        return $result;
    }
    public function discard() { return $this->r->discard(); }
}
$sources = [
    ['Feed'=>['id'=>1,'name'=>'Feed 1','source_format'=>'misp']],
    ['Feed'=>['id'=>2,'name'=>'Feed 2','source_format'=>'misp']]
];
$attrs = [['type'=>'domain','value'=>'example.org']];
$data = [['keys'=>['example.org'],'source_ids'=>[1,2]]];
$results = [];
foreach ([BaselineFeed::class, CandidateFeed::class] as $kind) {
    clean($r); fixture($r,'Feed',$sources,$data);
    $proxy = new InjectedRedis('misp:feed_cache:event_uuid_lookup:' . md5('example.org'));
    $object = new $kind($proxy,$sources);
    $event = [];
    $results[$kind] = [$object->attachFeedCorrelations($attrs,user(),$event),$event];
}
clean($r);
if ($results[BaselineFeed::class] === $results[CandidateFeed::class]) {
    fwrite(STDERR, "Unexpected equality: mutation fixture must expose a behavioral difference\n");
    exit(1);
}
echo "EXPECTED DIVERGENCE: concurrent Redis UUID-set modification changes later-source results\n";
echo "This is a blocking production-equivalence concern, not a regression pass.\n";
