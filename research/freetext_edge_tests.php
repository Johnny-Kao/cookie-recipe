<?php
declare(strict_types=1);
require __DIR__ . '/test_freetext.php';

function assertSameOutput(array $data,array $feeds,array $rows,bool $admin=true,int $feedId=1): void {
    $a=new BaselineFreetext(new RedisProbe(),$feeds,$rows);
    $b=new CandidateFreetext(new RedisProbe(),$feeds,$rows);
    $user=['Role'=>['perm_site_admin'=>$admin]];
    $orig=$a->getFreetextFeedCorrelations($data,$feedId,$user);
    $new=$b->getFreetextFeedCorrelations($data,$feedId,$user);
    if($orig!==$new) throw new RuntimeException('Mismatch: '.json_encode($data));
}
function row(string $value): array {return ['value'=>$value];}
$feeds=[];
for($i=1;$i<=16;$i++) $feeds[]=['Feed'=>['id'=>$i,'name'=>"F-$i",'provider'=>'Synthetic','source_format'=>'freetext','lookup_visible'=>$i%3!==0]];
$rows=[
 ['Attribute'=>['event_id'=>3,'value1'=>'shared.invalid','value2'=>'']],
 ['Attribute'=>['event_id'=>1,'value1'=>'shared.invalid','value2'=>'unrelated']],
 ['Attribute'=>['event_id'=>9,'value1'=>'ignored.invalid','value2'=>'shared.invalid']],
 ['Attribute'=>['event_id'=>9,'value1'=>'shared.invalid','value2'=>'shared.invalid']]
];
try {
    $cases=[
      'empty'=>[],
      'single'=>[['value'=>'shared.invalid']],
      'mixed-keys'=>['str'=>row('shared.invalid'),7=>row('missing.invalid'),42=>row('shared.invalid')],
      'original-fields'=>[17=>['value'=>'shared.invalid','correlations'=>[99],'feed_correlations'=>[['preexisting'=>true]]]],
      'duplicate'=>array_fill(0,16,row('shared.invalid')),
      'empty-value'=>[row(''),row('shared.invalid'),row('')],
    ];
    foreach($cases as $name=>$data) {
        seed($r,$feeds,$data,40);
        foreach([true,false] as $admin) assertSameOutput($data,$feeds,$rows,$admin);
        echo "PASS edge $name admin+nonadmin\n";
    }
    // Hit exactly, before, and after 256-command pipeline boundaries.
    foreach([255,256,257,511,512,513,1025] as $n) {
        $data=makeData($n);
        seed($r,$feeds,$data,45);
        assertSameOutput($data,$feeds,$rows,true);
        echo "PASS boundary $n\n";
    }
    // No matching candidate feeds: all source IDs equal excluded feedId.
    $only=[['Feed'=>['id'=>1,'name'=>'Only','lookup_visible'=>true]]];
    seed($r,$only,[row('shared.invalid')],100);
    assertSameOutput([row('shared.invalid')],$only,$rows);
    echo "PASS no candidate feeds\n";
    // Explicitly confirm the pipeline itself is bounded.
    $data=makeData(1400);
    seed($r,$feeds,$data,20);
    $base=runOne(BaselineFreetext::class,$feeds,$data,$rows,true,0);
    $opt=runOne(CandidateFreetext::class,$feeds,$data,$rows,true,0);
    if($base['output']!==$opt['output'])throw new RuntimeException('Large fixture mismatch');
    if($opt['exec'] < 20)throw new RuntimeException('Large fixture not chunked as expected');
    printf("PASS stress 1400 rows output identical; original %.1fms candidate %.1fms; peak B %d vs %d; %d pipelines\n",
        $base['ms'],$opt['ms'],$base['mem'],$opt['mem'],$opt['exec']);
} finally {cleanup($r);}
