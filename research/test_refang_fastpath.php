<?php
declare(strict_types=1);
require __DIR__.'/../build/ComplexTypeTool.php';
require __DIR__.'/../build/ComplexTypeToolFastPath.php';
$original = new ComplexTypeTool();
$candidate = new ComplexTypeToolFastPath();
$original->setTLDs(['com', 'org', 'net', 'io', 'co', 'jp', 'uk', 'ch', 'gov', 'de']);
$candidate->setTLDs(['com', 'org', 'net', 'io', 'co', 'jp', 'uk', 'ch', 'gov', 'de']);
$a = new ReflectionMethod(ComplexTypeTool::class, '__refangInput');
$b = new ReflectionMethod(ComplexTypeToolFastPath::class, '__refangInput');
$seeds = [
    'example.com', 'sub.example.net', '8.8.8.8', '192.0.2.7', '2001:db8::1',
    'https://example.com/a?q=1', 'http://example.org', 'hxxp://evil[.]example',
    'hxtp://foo(dot)com', 'htxp://test[dot]org', 'meow://host[.]com',
    'h[tt]p://foo[.]com', '[hxxp://]foo[.]com', 'evil[.]com', 'evil(dot)com',
    'abc[at]example[.]com', 'abc[@]example[.]com',
    'test[:]443', '[.]', '[X]', '[a]123', '(dot)', 'something...','',
    'https://virustotal.com/path','hello.example', 'abcd[dot]net',
    '41cbe25a6b3c9b76d5faef20d2abcb89', 'CVE-2026-10001',
];
mt_srand(218);
$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789[]().@:/_-';
for($i=0;$i<2200;$i++) {
    $v='';
    for($j=mt_rand(0,56);$j>0;$j--) $v .= $alphabet[mt_rand(0,strlen($alphabet)-1)];
    $seeds[]=$v;
}
foreach($seeds as $i=>$value) {
    $old = $a->invoke($original, ['raw'=>$value]);
    $new = $b->invoke($candidate, ['raw'=>$value]);
    if ($old !== $new) throw new RuntimeException('refang mismatch index '.$i.' input='.json_encode($value).' old='.json_encode($old).' new='.json_encode($new));
}
echo 'PASS exact refang equivalence for '.count($seeds)." inputs\n";
$cases = [
    'normal-domain' => array_fill(0,4500,'alpha.example.com'),
    'normal-ip' => array_fill(0,4500,'192.0.2.7'),
    'normal-url' => array_fill(0,4500,'https://example.com/a?q=1'),
    'mixed-defanged' => array_fill(0,4500,'hxxp://bad[.]example'),
    'mixed-random' => array_slice($seeds,0,1600),
];
function benchmark($method,$tool,array $values,int $repeats=4): float {
    // warm-up PCRE/JIT and optimize outside timing
    foreach(array_slice($values,0,20) as $v) $method->invoke($tool,['raw'=>$v]);
    $times=[];
    for($r=0;$r<$repeats;$r++) {
        $start=hrtime(true);
        foreach($values as $v) $method->invoke($tool,['raw'=>$v]);
        $times[]=(hrtime(true)-$start)/1000000;
    }
    sort($times);
    return ($times[1]+$times[2])/2;
}
foreach($cases as $name=>$values) {
    $oldMs=benchmark($a,$original,$values);
    $newMs=benchmark($b,$candidate,$values);
    echo json_encode(['case'=>$name,'count'=>count($values),'baseline_ms'=>round($oldMs,3),'candidate_ms'=>round($newMs,3),'speedup'=>round($oldMs/max($newMs,0.0001),2)],JSON_UNESCAPED_SLASHES)."\n";
}
// Full public-path regression to include type resolution, de-duplication and output shape.
$payloads = [
 '8.8.8.8 example.com https://example.org CVE-2026-10001',
 'hxxp://bad[.]com good@sample.com 2001:db8::1',
 '127.0.0.1 10.10.10.10 foo[dot]com foo(dot)org',
 '"quoted.example.com" [hxxp://]evil[.]net',
 implode("\n",array_slice($seeds,0,750)),
];
foreach($payloads as $i=>$payload) {
    $baseline=$original->checkFreeText($payload);
    $new=$candidate->checkFreeText($payload);
    if ($baseline !== $new) throw new RuntimeException('full public method mismatch payload '.$i);
}
echo 'PASS checkFreeText output equivalence for '.count($payloads)." mixed payloads\n";
