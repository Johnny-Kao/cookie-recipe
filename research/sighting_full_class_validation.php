<?php
declare(strict_types=1);
// Full unmodified MISP Sighting class, run with stand-in framework dependencies.
// Tests execute the REAL listSightings(), getLastSightingForAttribute() and restSearch().
// No local tests, no DB, no upstream change.
$source=file_get_contents($argv[1] ?? '');
if ($source === false || !str_contains($source, 'class Sighting extends AppModel')) throw new RuntimeException('missing or changed pinned Sighting source');
$target=dirname(__DIR__).'/build/RealSighting.php';
file_put_contents($target,$source);

class App {public static function uses($name,$package):void {}}
class AppModel {public $Event; public $Attribute;public $Organisation;}
class Configure {
    public static array $values=['Plugin.Sightings_policy'=>1,'MISP.host_org_id'=>101,'MISP.disable_sighting_loading'=>false];
    public static function read($key) {return self::$values[$key]??null;}
}
class Validation {
    public static function uuid($value):bool {return is_string($value)&&preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$value)===1;}
}
class JsonTool {public static function convertIntegersToStrings(&$value):void {}}
class TmpFileTool {
    public array $chunks=[];
    public function write($s):void {$this->chunks[]=$s;}
    public function writeWithSeparator($s,$sep):void {$this->chunks[]=$s.$sep;}
}
class JsonExport {
    public function header($params):string{return '';}
    public function separator($params):string{return ',';}
    public function footer($params):string{return '';}
    public function handler($row,$params):string{return json_encode($row);}
}
class FakeAttr {
    public array $attributes=[];
    public function fetchAttributes($user,$options):array{return $this->attributes;}
    public function setTimestampConditions($when,$conditions,$field):array{return $conditions;}
}
class FakeEvent {
    public $Attribute;
    public function __construct(){$this->Attribute=new FakeAttr();}
}
class FakeOrg {
    public function find($mode,$options):array {
        $uuid=$options['conditions']['Organisation.uuid']??'';
        return $uuid==='11111111-1111-1111-1111-111111111111' ? ['Organisation'=>['id'=>17]] : [];
    }
}
require $target;
class RealSightingHarness extends Sighting {
    public array $sightingRows=[];
    public array $finds=[];
    public bool $reporter=true;
    public function hasAny($conditions):bool{return $this->reporter;}
    public function find($mode,$args=[]){
        $this->finds[]=['mode'=>$mode,'conditions'=>$args['conditions']??[]];
        if ($mode==='all')return $this->sightingRows;
        if ($mode==='first')return $this->sightingRows[0]??[];
        if ($mode==='column')return array_column(array_column($this->sightingRows,'Sighting'),'id');
        throw new RuntimeException('Unexpected find mode '.$mode);
    }
    public function getSightings(array $user,array $ids,$includeEvent=true,$includeAttribute=false,$includeUuid=false){
        return $this->sightingRows;
    }
}
$user=['org_id'=>101,'Role'=>['perm_site_admin'=>false]];
function instance(array $rows,bool $reporter):RealSightingHarness {
    $obj=new RealSightingHarness();
    $obj->Event=new FakeEvent();
    $obj->Attribute=$obj->Event->Attribute;
    $obj->Organisation=new FakeOrg();
    $obj->Event->Attribute->attributes=[['Attribute'=>['id'=>15],'Event'=>['id'=>12,'orgc_id'=>202]]];
    $obj->sightingRows=$rows;
    $obj->reporter=$reporter;
    return $obj;
}
function expectFailure(callable $fn,string $name):void{
    try {
        $r=$fn();
        throw new RuntimeException('BUG NOT REPRODUCED '.$name.'; returned '.json_encode($r));
    } catch (TypeError|ErrorException $e) {
        echo 'CONFIRMED FULL CLASS '.$name.' '.get_class($e).' '.$e->getMessage()."\n";
    }
}
set_error_handler(static function($severity,$message){throw new ErrorException($message,0,$severity);});
$row=['Sighting'=>['id'=>99,'attribute_id'=>15,'event_id'=>12,'org_id'=>202,'type'=>0,'date_sighting'=>1780000000],'Organisation'=>['name'=>'Example']];
$hit=instance([$row],true);
$authorized=$hit->listSightings($user,[15],'attribute',false,0,true);
if ($authorized!==[$row])throw new RuntimeException('Reporter fixture did not pass real ACL filtering');
expectFailure(fn()=>$hit->getLastSightingForAttribute($user,15),'R1-reporter-visible-hit');
$empty=instance([$row],false);
if ($empty->listSightings($user,[15],'attribute',false,0,true)!==[])throw new RuntimeException('ACL fixture did not remove sightings');
expectFailure(fn()=>$empty->getLastSightingForAttribute($user,15),'R1-reporter-hidden-by-ACL');
$noRows=instance([],true);
expectFailure(fn()=>$noRows->getLastSightingForAttribute($user,15),'R1-reporter-no-sightings');
restore_error_handler();
echo "PASS full class R1 independent listSightings ACL hit/hidden/empty fixtures\n";

function checkRestSearch(array $orgFilter):array{
    global $user;
    $model=instance([],true);
    $model->restSearch($user,'json',['org_id'=>$orgFilter]);
    $find=end($model->finds);
    if($find===false)throw new RuntimeException('No underlying Sighting find');
    return $find['conditions'];
}
$known='11111111-1111-1111-1111-111111111111';
$unknown='22222222-2222-2222-2222-222222222222';
$cases=[
    'known'=>[$known],
    'known-then-missing'=>[$known,$unknown],
    'known-then-numeric'=>[$known,18],
    'known-then-negated-missing'=>[$known,'!'.$unknown],
];
foreach($cases as $name=>$data){
    $conditions=checkRestSearch($data);
    echo json_encode(['case'=>$name,'actual_conditions'=>$conditions],JSON_UNESCAPED_SLASHES)."\n";
    if($name==='known' && $conditions['Sighting.org_id']!==[17])throw new RuntimeException('known filter expected 17');
    if($name==='known-then-missing' && $conditions['Sighting.org_id']!==[17,17])throw new RuntimeException('stale condition not reproduced');
    if($name==='known-then-numeric' && $conditions['Sighting.org_id']!==[17,17])throw new RuntimeException('stale numeric condition not reproduced');
    if($name==='known-then-negated-missing' && ($conditions['Sighting.org_id NOT IN']??null)!==[17])throw new RuntimeException('incorrect negative condition not reproduced');
}
set_error_handler(static function($severity,$message){throw new ErrorException($message,0,$severity);});
expectFailure(fn()=>checkRestSearch([$unknown]),'R2-unresolved-UUID');
expectFailure(fn()=>checkRestSearch([17]),'R2-integer-org');
restore_error_handler();
echo "PASS real Sighting::restSearch filter branch and find conditions\n";
