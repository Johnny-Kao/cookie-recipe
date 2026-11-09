<?php
declare(strict_types=1);
// Isolated source-derived benchmark: no CakePHP boot and no MISP modification.
$source = file_get_contents($argv[1] ?? '');
if ($source === false) throw new RuntimeException('missing pinned Warninglist source');
$start = strpos($source, '    private function __evalHostname(');
$end = strpos($source, '    private function __evalRegex(', $start);
if ($start === false || $end === false) throw new RuntimeException('method boundaries changed');
$method = substr($source, $start, $end - $start);
$needle = 'foreach (array_reverse($hostname) as $piece) {';
if (substr_count($method, $needle)!==1) throw new RuntimeException('source signature mismatch');
$candidate = str_replace($needle, 'for ($i = count($hostname) - 1; $i >= 0; --$i) {' . "\n            " . '$piece = $hostname[$i];', $method);
$originalCode = "<?php class WarninglistBaseline {\n".str_replace('private function __evalHostname', 'public function evalHostname', $method)."\n}";
$candidateCode = "<?php class WarninglistCandidate {\n".str_replace('private function __evalHostname', 'public function evalHostname', $candidate)."\n}";
$dir = dirname(__DIR__).'/build';
file_put_contents($dir.'/WarninglistBaseline.php',$originalCode);
file_put_contents($dir.'/WarninglistCandidate.php',$candidateCode);
require $dir.'/WarninglistBaseline.php';
require $dir.'/WarninglistCandidate.php';
$a=new WarninglistBaseline();$b=new WarninglistCandidate();
$entries=['com'=>true,'example.com'=>true,'foo.example.com'=>true,'net'=>true,'0'=>true,'x.0'=>true,'a..com'=>true,'org'=>true];
$values=['foo.example.com','a.foo.example.com','https://foo.example.com/path','https://x.y.z.com','a..com','x.0','x..0','0','.', '', 'https://','https:///abc', 'a.b.c.d.e.net', 'no-match.xyz', 'https://example.com:443/abc', 'foo.example.com.', 'FOO.EXAMPLE.COM','foo/a/b','abc//foo.net', 'a//b'];
mt_srand(20261109);
$alpha='abcdefghijklmnopqrstuvwxyz0123456789./';
for($i=0;$i<25000;$i++) {
    $v='';
    $len=mt_rand(0,75);
    for($j=0;$j<$len;$j++) $v.=$alpha[mt_rand(0,strlen($alpha)-1)];
    if($i%5===0) $v .= '.example.com';
    if($i%7===0) $v = 'https://'.$v.'/path';
    $values[]=$v;
}
foreach($values as $k=>$v) {
    $old=$a->evalHostname($entries,$v);
    $new=$b->evalHostname($entries,$v);
    if($old!==$new)throw new RuntimeException('Mismatch '. $k.' '.json_encode($v));
}
echo 'PASS warninglist hostname exact equivalence '.count($values)."\n";
$benchValues=[];
for($i=0;$i<30000;$i++) {
    $benchValues[]="node{$i}.edge{$i}.example.com";
}
function timeRun($obj,array $entries,array $values):float {
    $start=hrtime(true);
    foreach($values as $v)$obj->evalHostname($entries,$v);
    return (hrtime(true)-$start)/1e6;
}
$times=['baseline'=>[],'candidate'=>[]];
for($r=0;$r<14;$r++) {
    $order=$r%2===0?['baseline','candidate']:['candidate','baseline'];
    foreach($order as $which)$times[$which][]=timeRun($which==='baseline'?$a:$b,$entries,$benchValues);
}
sort($times['baseline']);sort($times['candidate']);
$mid=fn($x)=>($x[6]+$x[7])/2;
$old=$mid($times['baseline']);$new=$mid($times['candidate']);
echo json_encode(['case'=>'warninglist-hostname-matches','rows'=>count($benchValues),'baseline_ms'=>round($old,3),'candidate_ms'=>round($new,3),'reduction_pct'=>round((1-$new/$old)*100,2)],JSON_UNESCAPED_SLASHES)."\n";
