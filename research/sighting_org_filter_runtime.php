<?php
declare(strict_types=1);
$source=file_get_contents($argv[1]??'');
if($source===false)throw new RuntimeException('Missing pinned source');
$start=strpos($source,"        if (isset(\$filters['org_id'])) {",strpos($source,'    public function restSearch('));
$end=strpos($source,"        if (isset(\$filters['source']))", $start);
if($start===false||$end===false)throw new RuntimeException('Org filter block not found');
$block=substr($source,$start,$end-$start);
class Validation {
    public static function uuid($v) { return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i',$v) === 1; }
}
class FakeOrganisation {
    public function find($mode,$opts){ if(($opts['conditions']['Organisation.uuid']??'')==='11111111-1111-1111-1111-111111111111')return ['Organisation'=>['id'=>17]];return []; }
}
$closure=eval('return function(array $filters) { $conditions=[]; $thisOrg = new FakeOrganisation(); $thisDummy=null; '.str_replace('$this->Organisation','$thisOrg',$block).' return $conditions; };');
if(!is_callable($closure))throw new RuntimeException('Unable to compile exact org filter');
set_error_handler(function($severity,$msg){throw new ErrorException($msg,0,$severity);});
foreach ([
    'numeric-id'=>['org_id'=>17],
    'numeric-string'=>['org_id'=>'17'],
    'known-uuid'=>['org_id'=>'11111111-1111-1111-1111-111111111111'],
    'missing-uuid'=>['org_id'=>'22222222-2222-2222-2222-222222222222'],
] as $case=>$filters) {
    try {
        $result=$closure($filters);
        echo json_encode(['case'=>$case,'result'=>$result],JSON_UNESCAPED_SLASHES)."\n";
    } catch(ErrorException $e) {
        echo 'CONFIRMED original '.$case.' => '.$e->getMessage()."\n";
    }
}
restore_error_handler();
// With warnings non-fatal, a failed UUID lookup can inherit $temp from the previous iteration.
$warnings=[];
set_error_handler(function($severity,$msg) use (&$warnings) {$warnings[]=$msg;return true;});
$known='11111111-1111-1111-1111-111111111111';
$unknown='22222222-2222-2222-2222-222222222222';
$cases=[
    'known-then-unknown'=>['org_id'=>[$known,$unknown]],
    'known-then-numeric'=>['org_id'=>[$known,18]],
    'unknown-then-known'=>['org_id'=>[$unknown,$known]],
    'unknown-negated'=>['org_id'=>['!'.$unknown]],
    'mixed-negation'=>['org_id'=>[$known,'!'.$unknown]],
];
foreach($cases as $label=>$filters){
    $warnings=[];
    $out=$closure($filters);
    echo json_encode(['case'=>$label,'result'=>$out,'warnings'=>$warnings],JSON_UNESCAPED_SLASHES)."\\n";
    if($label==='known-then-unknown' && ($out['Sighting.org_id']??null)!==[17,17]) throw new RuntimeException('Expected stale temp reuse not observed');
    if($label==='known-then-numeric' && ($out['Sighting.org_id']??null)!==[17,17]) throw new RuntimeException('Expected numeric ID dropped via stale temp reuse not observed');
}
restore_error_handler();
