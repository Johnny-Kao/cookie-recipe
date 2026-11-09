<?php
declare(strict_types=1);
require __DIR__.'/test_freetext.php';
class AfterFirstReadWriter {
    private Redis $reader;
    private Redis $writer;
    private int $reads = 0;
    public function __construct() {
        $this->reader = new Redis();
        $this->reader->connect('127.0.0.1',6379);
        $this->writer = new Redis();
        $this->writer->connect('127.0.0.1',6379);
    }
    public function exists($key) { return $this->reader->exists($key); }
    public function sismember($key,$hash) {
        $answer = $this->reader->sIsMember($key,$hash);
        if (++$this->reads === 1) {
            $this->writer->sAdd('misp:feed_cache:2',md5('repeated.invalid'));
        }
        return $answer;
    }
}
$feeds=[
 ['Feed'=>['id'=>1,'name'=>'excluded','lookup_visible'=>1]],
 ['Feed'=>['id'=>2,'name'=>'target','lookup_visible'=>1]],
];
$data=[
 ['value'=>'repeated.invalid'],
 ['value'=>'repeated.invalid'],
];
try {
    $outputs=[];
    foreach ([BaselineFreetext::class,CandidateFreetext::class] as $class) {
        cleanup($r);
        $r->sAdd('misp:feed_cache:2',md5('unrelated.invalid'));
        $app=new $class(new AfterFirstReadWriter(),$feeds,[]);
        $outputs[$class]=$app->getFreetextFeedCorrelations($data,1,['Role'=>['perm_site_admin'=>true]]);
    }
    $changed=$outputs[BaselineFreetext::class]!==$outputs[CandidateFreetext::class];
    $mode=$argv[1]??'md5';
    if ($mode==='memo' && !$changed) throw new RuntimeException('Memoization must expose stale result in adversarial update');
    if ($mode==='md5' && $changed) throw new RuntimeException('MD5-only unexpectedly changes reads');
    echo ($changed ? 'EXPECTED STALE MEMO RESULT' : 'PASS MD5-only concurrent read equivalence')."\n";
    echo 'BASELINE='.json_encode($outputs[BaselineFreetext::class])."\n";
    echo 'CANDIDATE='.json_encode($outputs[CandidateFreetext::class])."\n";
} finally {cleanup($r);}
