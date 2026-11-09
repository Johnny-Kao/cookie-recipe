<?php
declare(strict_types=1);
// Reproduce the exact MISP public method in an isolated fake model, no CakePHP/DB.
$source = file_get_contents($argv[1] ?? '');
if($source===false)throw new RuntimeException('Pinned source unavailable');
$start=strpos($source,'    public function getLastSightingForAttribute(');
$end=strpos($source,'    public function restSearch(', $start);
if($start===false||$end===false)throw new RuntimeException('Method boundaries changed');
$method=substr($source,$start,$end-$start);
$method=substr($method,0,strpos($method,'    /**',2)); // exclude next docblock
$needle=<<<'PHP'
            $sighting = $all_sightings[0]['Sighting']['date_sighting'];
            return $sighting;
PHP;
if(substr_count($method,$needle)!==1)throw new RuntimeException('Expected original failing branch changed');
$fixed=str_replace($needle,<<<'PHP'
            return empty($all_sightings) ? [] : $all_sightings[0];
PHP,$method);
$common=<<<'PHP'
    public const SIGHTING_POLICY_EVENT_OWNER = 0;
    public const SIGHTING_POLICY_SIGHTING_REPORTER = 1;
    public const SIGHTING_POLICY_EVERYONE = 2;
    public const SIGHTING_POLICY_HOST_ORG = 3;
    public int $policy = 1;
    public array $mockRows = [];
    public function sightingsPolicy(): int { return $this->policy; }
    public function listSightings($user,$ids,$context,$orgId=false,$type=false,$orderDesc=true): array {
        if($context!=='attribute'||$type!==0||$orderDesc!==true)throw new RuntimeException('Unexpected list query');
        return $this->mockRows;
    }
    public function find($type,$query): array { return $this->mockRows[0] ?? []; }
PHP;
file_put_contents(dirname(__DIR__).'/build/SightingInvariantOriginal.php',"<?php\nclass SightingInvariantOriginal {\n".$common."\n".$method."\n}");
file_put_contents(dirname(__DIR__).'/build/SightingInvariantCandidate.php',"<?php\nclass SightingInvariantCandidate {\n".$common."\n".$fixed."\n}");
class Configure {public static function read($k){return 1;}}
require dirname(__DIR__).'/build/SightingInvariantOriginal.php';
require dirname(__DIR__).'/build/SightingInvariantCandidate.php';
set_error_handler(function($severity,$message){throw new ErrorException($message,0,$severity);});
$user=['org_id'=>1,'Role'=>['perm_site_admin'=>true]];
$tests=[
    'reporter-hit' => [['Sighting'=>['id'=>7,'date_sighting'=>1780000000]]],
    'reporter-empty' => [],
];
foreach($tests as $label=>$rows){
    $original=new SightingInvariantOriginal();
    $original->mockRows=$rows;
    try{$result=$original->getLastSightingForAttribute($user,10);throw new RuntimeException('Unexpected original success '.json_encode($result));}
    catch(TypeError|ErrorException $e){echo 'CONFIRMED original '.$label.' => '.get_class($e).': '.$e->getMessage()."\n";}
    $candidate=new SightingInvariantCandidate();
    $candidate->mockRows=$rows;
    $actual=$candidate->getLastSightingForAttribute($user,10);
    $expected=$rows[0]??[];
    if($actual!==$expected)throw new RuntimeException('Candidate mismatch '.$label);
    echo 'PASS candidate '.$label.' returns exact array shape'."\n";
}
foreach([0,2,3] as $policy){
    $rows=[['Sighting'=>['id'=>5,'date_sighting'=>1780000000]]];
    foreach([SightingInvariantOriginal::class,SightingInvariantCandidate::class] as $class){
        $model=new $class();
        $model->policy=$policy;
        $model->mockRows=$rows;
        $actual=$model->getLastSightingForAttribute($user,10);
        if($actual!==$rows[0])throw new RuntimeException('Other policy changed '.$policy);
    }
    echo 'PASS original/candidate other policy '.$policy."\n";
}
restore_error_handler();
