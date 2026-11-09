<?php
declare(strict_types=1);
class ClassRegistry {
    public static $attribute;
    public static function init($name) { return self::$attribute; }
}
class FakeAttribute {
    private $rows;
    public function __construct($rows) {$this->rows=$rows;}
    public function fetchAttributesSimple($user,$options) { return $this->rows; }
}
class FreetextHarnessBase {
    public $Attribute;
    private $redis;
    private $feeds;
    public function __construct($redis, array $feeds, array $attributeRows) {
        $this->redis=$redis;
        $this->feeds=$feeds;
        ClassRegistry::$attribute=new FakeAttribute($attributeRows);
    }
    public function setupRedis() {return $this->redis;}
    public function visibleConditions(array $user) {return empty($user['Role']['perm_site_admin']) ? ['Feed.lookup_visible'=>1] : [];}
    public function find($type,$query) {
        $id=$query['conditions']['Feed.id !='];
        $visible=isset($query['conditions']['Feed.lookup_visible']);
        return array_filter($this->feeds, static fn($feed) => $feed['Feed']['id']!=$id && (!$visible || !empty($feed['Feed']['lookup_visible'])));
    }
}
require __DIR__.'/../build/freetext-extracted.php';

class RedisProbe {
    private Redis $r;
    private int $delayUs;
    private bool $pipelined=false;
    public int $direct=0;
    public int $pipelineExec=0;
    public int $membership=0;
    public function __construct(int $delayUs=0) {
        $this->r=new Redis();$this->r->connect('127.0.0.1',6379,2);$this->delayUs=$delayUs;
    }
    public function exists($key) { if($this->delayUs)usleep($this->delayUs);return $this->r->exists($key);}
    public function sismember($key,$member) {
        $this->membership++;
        if (!$this->pipelined) {
            $this->direct++;
            if($this->delayUs)usleep($this->delayUs);
        }
        return $this->r->sIsMember($key,$member);
    }
    public function pipeline() {$this->pipelined=true;$this->r->pipeline();return $this;}
    public function exec() {$this->pipelineExec++;$this->pipelined=false;if($this->delayUs)usleep($this->delayUs);return $this->r->exec();}
}
$r=new Redis();$r->connect('127.0.0.1',6379,2);
$prefix='cookie-recipe:freetext:';
function cleanup(Redis $r) {
    foreach ($r->keys('misp:feed_cache:*') as $key) $r->del($key);
}
function seed(Redis $r,array $feeds,array $data,int $rate): void {
    cleanup($r);
    foreach($feeds as $f) {
        $id=$f['Feed']['id'];
        if($id%11===0) continue; // absent cache source
        $key='misp:feed_cache:'.$id;
        $r->sAdd($key, md5('dummy-'.$id));
        foreach(array_values($data) as $i=>$entry) {
            if((($i*7+$id*13)%100)<$rate)$r->sAdd($key,md5($entry['value']));
        }
    }
}
function makeData($n,$uniquePercent=70) {
    $data=[];
    for($i=0;$i<$n;$i++) {
        $key=($i%5===0) ? "key".($i*2) : $i;
        $v='example-'.($i%max(1,(int)($n*$uniquePercent/100))).'.invalid';
        $data[$key]=['value'=>$v];
    }
    return $data;
}
function runOne($class,$feeds,$data,$rows,$admin,$delay) {
    $proxy=new RedisProbe($delay);
    $obj=new $class($proxy,$feeds,$rows);
    if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();
    $startMem=memory_get_usage(false);
    $t=hrtime(true);
    $output=$obj->getFreetextFeedCorrelations($data,1,['Role'=>['perm_site_admin'=>$admin]]);
    return ['output'=>$output,'ms'=>(hrtime(true)-$t)/1e6,'mem'=>max(0,memory_get_peak_usage(false)-$startMem),
      'direct'=>$proxy->direct,'exec'=>$proxy->pipelineExec,'membership'=>$proxy->membership];
}
function testCase($name,$n,$sourceCount,$rate,$delay=0,$admin=true,$repeats=3) {
    global $r;
    $feeds=[];
    for($s=1;$s<=$sourceCount;$s++)$feeds[]=['Feed'=>['id'=>$s,'name'=>'Feed '.$s,'provider'=>'lab',
      'source_format'=>'freetext','lookup_visible'=>($s%4 !==0 ? 1 : 0)]];
    $data=makeData($n);
    $rows=[
        ['Attribute'=>['event_id'=>71,'value1'=>array_values($data)[0]['value'],'value2'=>'']],
        ['Attribute'=>['event_id'=>72,'value1'=>'irrelevant.invalid','value2'=>array_values($data)[0]['value']]],
        ['Attribute'=>['event_id'=>71,'value1'=>array_values($data)[0]['value'],'value2'=>array_values($data)[0]['value']]],
    ];
    seed($r,$feeds,$data,$rate);
    $samples=['baseline'=>[],'candidate'=>[]];$observed=[];
    for($i=0;$i<$repeats;$i++) {
        $outputs=[];
        foreach(($i%2===0?['baseline','candidate']:['candidate','baseline']) as $which) {
            $kind=$which==='baseline' ? BaselineFreetext::class : CandidateFreetext::class;
            $res=runOne($kind,$feeds,$data,$rows,$admin,$delay);
            $outputs[$which]=$res['output'];
            unset($res['output']);
            $samples[$which][]=$res;
            $observed[$which]=$res;
        }
        if($outputs['baseline']!==$outputs['candidate']) {
            file_put_contents('/tmp/freetext-mismatch.txt',var_export(['baseline'=>$outputs['baseline'],'candidate'=>$outputs['candidate']],true));
            throw new RuntimeException('DIFFERENT OUTPUT '.$name);
        }
    }
    foreach($samples as $key=>$entries) {
        usort($entries,static fn($a,$b)=>$a['ms']<=>$b['ms']);
        $observed[$key]=$entries[(int)floor(count($entries)/2)];
    }
    printf("PASS %s n=%d feeds=%d rate=%d%% delay=%dus baseline=%.3fms candidate=%.3fms commands=%d,%d pipeline_exec=%d peak_bytes=%d,%d\n",
    $name,$n,$sourceCount,$rate,$delay,$observed['baseline']['ms'],$observed['candidate']['ms'],
    $observed['baseline']['membership'],$observed['candidate']['membership'],
    $observed['candidate']['exec'],$observed['baseline']['mem'],$observed['candidate']['mem']);
    return ['case'=>$name,'attributes'=>$n,'feeds'=>$sourceCount,'rate'=>$rate,'delay_us'=>$delay,
        'baseline'=>$observed['baseline'],'candidate'=>$observed['candidate'],'identical'=>true];
}
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
$results=[];
try {
    $results[]=testCase('zero-hits',15,8,0,0,true,3);
    $results[]=testCase('normal-preview-60',60,15,30,0,true,5);
    $results[]=testCase('admin-preview',100,15,30,0,true,5);
    $results[]=testCase('nonadmin-visibility',100,15,80,0,false,5);
    $results[]=testCase('high-hitrate',300,25,100,0,true,5);
    $results[]=testCase('network-delay',100,15,30,300,true,3);
    echo "RESULT_JSON=".json_encode($results,JSON_UNESCAPED_SLASHES)."\n";
} finally {cleanup($r);}
}
