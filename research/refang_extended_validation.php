<?php
declare(strict_types=1);
require __DIR__ . '/../build/ComplexTypeTool.php';
require __DIR__ . '/../build/ComplexTypeToolFastPath.php';

$base = new ComplexTypeTool();
$fast = new ComplexTypeToolFastPath();
$tlds = ['com','org','net','io','co','jp','uk','ch','gov','de'];
$base->setTLDs($tlds);
$fast->setTLDs($tlds);
$oldMethod = new ReflectionMethod(ComplexTypeTool::class, '__refangInput');
$newMethod = new ReflectionMethod(ComplexTypeToolFastPath::class, '__refangInput');

$seeds = [
    '', '.', '...', "foo\0bar", "foo\tbar", "foo\r\nbar",
    'HXXP://EXAMPLE.COM', 'HxTp://a[.]com', 'h[tt]p://a[.]com',
    'hxxps://a[.]com', 'hxxpX://a[.]com', '[hxxp://]a[.]com',
    'a[dot]com', 'a(dot)com', 'a[.]com', 'a[@]b.com',
    'a[at]b.com', 'a[:]443', 'a[X]b', '[.]', '(dot)',
    'a（dot）com', 'a［dot］com', 'a【dot】com',
    '例子.测试', 'bücher.ch', '☃.com', '🙂example.com',
    "a\xC2\xA0b", "a\xE2\x80\x8Bb",
    str_repeat('a', 8192), str_repeat('a[.]', 1000),
    str_repeat('hxxp', 1000), str_repeat('(', 1000),
];
foreach (['http','hxxp','hxtp','htxp','meow','HXXP','h[tt]p'] as $prefix) {
    foreach (['://site[.]com','[:]//site(dot)org','://site.com','://a[@]b.com'] as $suffix) {
        $seeds[] = $prefix . $suffix;
    }
}
mt_srand(61109);
$alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789[]().@:/_-";
for ($i=0; $i<12000; ++$i) {
    $v = '';
    $len = mt_rand(0, 160);
    for ($j=0; $j<$len; ++$j) $v .= $alphabet[mt_rand(0,strlen($alphabet)-1)];
    if ($i % 5 === 0) $v .= ['[.]','[dot]','(dot)','[:]','[@]','[at]'][($i/5)%6];
    if ($i % 7 === 0) $v = ['hxxp','htxp','meow','h[tt]p'][($i/7)%4] . $v;
    $seeds[] = $v;
}
foreach ($seeds as $i => $s) {
    $old = $oldMethod->invoke($base, ['raw'=>$s]);
    $new = $newMethod->invoke($fast, ['raw'=>$s]);
    if ($old !== $new) throw new RuntimeException('refang mismatch '. $i .' '.json_encode($s,JSON_INVALID_UTF8_SUBSTITUTE));
}
echo 'PASS extended refang strict equivalence '.count($seeds)."\n";

$datasets = [];
for ($i=0;$i<2400;$i++) {
    $datasets['domains'][] = "node{$i}.example.com";
    $datasets['urls'][] = "https://example.com/path/{$i}?a=1";
    $datasets['hashes'][] = hash('sha256', "item:{$i}");
    $datasets['ips'][] = sprintf('192.0.%d.%d', intdiv($i,256), $i%256);
    $datasets['defanged'][] = "hxxp://node{$i}[.]example.com";
}
$datasets['mixed'] = [];
for ($i=0;$i<2400;$i++) $datasets['mixed'][] = $datasets[['domains','urls','hashes','ips','defanged'][$i%5]][$i];

function measure($tool,string $payload): array {
    $start = hrtime(true);
    $out = $tool->checkFreeText($payload);
    return [(hrtime(true)-$start)/1000000, $out];
}
foreach ($datasets as $name=>$values) {
    $payload=implode("\n",$values);
    if ($base->checkFreeText($payload) !== $fast->checkFreeText($payload)) {
        throw new RuntimeException('full output mismatch '.$name);
    }
    // Interleaved ABBA ordering reduces the effect of a consistently warmer second contender.
    $times=['baseline'=>[],'candidate'=>[]];
    for ($round=0;$round<8;$round++) {
        $order=($round%2===0)?['baseline','candidate','candidate','baseline']:['candidate','baseline','baseline','candidate'];
        foreach ($order as $which) {
            [$ms,$out]=measure($which==='baseline'?$base:$fast,$payload);
            $times[$which][]=$ms;
        }
    }
    sort($times['baseline']);sort($times['candidate']);
    $med = fn(array $a) => ($a[7]+$a[8])/2;
    $old=$med($times['baseline']);$new=$med($times['candidate']);
    echo json_encode(['dataset'=>$name,'rows'=>count($values),'baseline_ms'=>round($old,3),'candidate_ms'=>round($new,3),'speedup'=>round($old/$new,3),'reduction_pct'=>round((1-$new/$old)*100,2)],JSON_UNESCAPED_SLASHES)."\n";
}
