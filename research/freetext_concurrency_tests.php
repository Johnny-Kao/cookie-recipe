<?php
declare(strict_types=1);
require __DIR__ . '/test_freetext.php';

// Deterministic adversarial update between two membership calls.
// Pipeline enqueues both before executing them, while baseline reads between calls.
class MutatingProbe {
    private Redis $reader;
    private Redis $writer;
    private string $target;
    private string $hash;
    private int $reads=0;
    public int $mutations=0;
    public function __construct(string $target,string $hash) {
        $this->target=$target;$this->hash=$hash;
        $this->reader=new Redis();$this->reader->connect('127.0.0.1',6379);
        $this->writer=new Redis();$this->writer->connect('127.0.0.1',6379);
    }
    public function exists($key){return $this->reader->exists($key);}
    public function pipeline(){ $this->reader->pipeline(); return $this;}
    public function exec(){return $this->reader->exec();}
    public function sismember($key,$member) {
        $this->reads++;
        $res=$this->reader->sIsMember($key,$member);
        if($this->reads===1) {
            $this->writer->sAdd($this->target,$this->hash);
            $this->mutations++;
        }
        return $res;
    }
}
$feeds=[];
foreach([1,2,3] as $id)$feeds[]=['Feed'=>['id'=>$id,'name'=>"Feed $id",'provider'=>'synthetic','source_format'=>'freetext','lookup_visible'=>true]];
$data=[['value'=>'race.invalid']];
$results=[];
try {
    foreach([BaselineFreetext::class,CandidateFreetext::class] as $kind) {
        cleanup($r);
        // Keep sets present but empty of raced value, bypasses exists() filter.
        foreach([2,3] as $id)$r->sAdd('misp:feed_cache:'.$id,md5('unrelated'));
        $probe=new MutatingProbe('misp:feed_cache:2',md5('race.invalid'));
        $app=new $kind($probe,$feeds,[]);
        $results[$kind]=$app->getFreetextFeedCorrelations($data,1,['Role'=>['perm_site_admin'=>true]]);
        if($probe->mutations!==1)throw new RuntimeException('No writer injected');
    }
    $baseline=$results[BaselineFreetext::class];
    $candidate=$results[CandidateFreetext::class];
    if($baseline===$candidate)throw new RuntimeException('Adversarial fixture unexpectedly same');
    echo "EXPECTED DIVERGENCE: mutation between queued reads and execute changes temporal semantics\n";
    echo "Baseline ".json_encode($baseline,JSON_UNESCAPED_SLASHES)."\n";
    echo "Candidate ".json_encode($candidate,JSON_UNESCAPED_SLASHES)."\n";
} finally {cleanup($r);}
