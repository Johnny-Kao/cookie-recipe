<?php
declare(strict_types=1);
require __DIR__.'/test_freetext.php';
class FailureProbe {
    private Redis $r;
    private string $failureMode;
    private int $calls=0;
    public function __construct(string $failureMode) {
        $this->failureMode=$failureMode;
        $this->r=new Redis();$this->r->connect('127.0.0.1',6379);
    }
    public function exists($key) {
        if($this->failureMode==='exists')throw new RuntimeException('injected exists fault');
        return $this->r->exists($key);
    }
    public function pipeline() {
        if($this->failureMode==='pipeline')throw new RuntimeException('injected pipeline fault');
        $this->r->pipeline();return $this;
    }
    public function sismember($key,$member) {
        $this->calls++;
        if($this->failureMode==='sismember' && $this->calls===2)throw new RuntimeException('injected membership fault');
        return $this->r->sIsMember($key,$member);
    }
    public function exec(){
        if($this->failureMode==='exec') {
            $this->r->discard();
            throw new RuntimeException('injected exec fault');
        }
        return $this->r->exec();
    }
}
$feeds=[];
for($i=1;$i<=4;$i++)$feeds[]=['Feed'=>['id'=>$i,'name'=>'F'.$i,'lookup_visible'=>true]];
$data=[['value'=>'fault.invalid'],['value'=>'other.invalid']];
try {
    seed($r,$feeds,$data,100);
    foreach(['exists','pipeline','sismember','exec'] as $mode) {
        $results=[];
        foreach([BaselineFreetext::class,CandidateFreetext::class] as $kind) {
            $app=new $kind(new FailureProbe($mode),$feeds,[]);
            try {
                $result=$app->getFreetextFeedCorrelations($data,1,['Role'=>['perm_site_admin'=>true]]);
                $results[$kind]='returned';
            } catch(Throwable $e) {
                $results[$kind]=get_class($e).': '.$e->getMessage();
            }
        }
        if($mode==='exists'||$mode==='sismember') {
            if($results[BaselineFreetext::class]!==$results[CandidateFreetext::class])throw new RuntimeException('Fault mismatch '.$mode);
        } else {
            if($mode==='pipeline' && $results[BaselineFreetext::class]!=='returned')throw new RuntimeException('Baseline pipeline unexpectedly used');
            if($mode==='exec' && $results[BaselineFreetext::class]!=='returned')throw new RuntimeException('Baseline exec unexpectedly used');
            if($results[CandidateFreetext::class]==='returned')throw new RuntimeException('Candidate did not surface '.$mode.' fault');
        }
        echo 'PASS fault-mode '.$mode.' (baseline='.$results[BaselineFreetext::class].'; candidate='.$results[CandidateFreetext::class].")\n";
    }
} finally {cleanup($r);}
