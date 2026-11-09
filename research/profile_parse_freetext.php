<?php
declare(strict_types=1);
require __DIR__ . '/../build/ComplexTypeTool.php';
$tool = new ComplexTypeTool();
$tool->setTLDs(['com','org','net','io','co','jp','uk','ch','gov','de']);
$parse = new ReflectionMethod(ComplexTypeTool::class, 'parseFreetext');

function median(array $values): float {
    sort($values, SORT_NUMERIC);
    $n = count($values);
    return ($values[intdiv($n-1,2)] + $values[intdiv($n,2)]) / 2;
}
function timed(callable $callback, int $repeat = 14): float {
    $callback(); $callback();
    $samples = [];
    for ($i=0; $i<$repeat; ++$i) {
        $start=hrtime(true);
        $callback();
        $samples[]=(hrtime(true)-$start)/1e6;
    }
    return median($samples);
}
$datasets = [];
for ($i=0;$i<2400;$i++) {
    $datasets['domains'][] = "n{$i}.example.com";
    $datasets['urls'][] = "https://example.com/path/{$i}?q=1";
    $datasets['hashes'][] = hash('sha256', 'hash-'.$i);
    $datasets['ips'][] = sprintf('192.0.%d.%d',intdiv($i,256),$i%256);
    $datasets['defanged'][] = "hxxp://n{$i}[.]example.com";
    $datasets['quoted'][] = '"n'.$i.'.example.com" other'.$i.'.example.org';
    $datasets['noisy'][] = "prefix={$i}, <n{$i}.example.com>; words\tother".$i.".example.net";
}
$datasets['mixed']=[];
foreach (range(0,2399) as $i) {
    $types=['domains','urls','hashes','ips','defanged','quoted','noisy'];
    $datasets['mixed'][] = $datasets[$types[$i%count($types)]][$i];
}
foreach ($datasets as $name => $rows) {
    $payload = implode("\n", $rows);
    $expected = $tool->checkFreeText($payload);
    $parsed = $parse->invoke($tool,$payload);
    if (!is_array($parsed)) throw new RuntimeException('unexpected parse output');
    $p=timed(fn()=> $parse->invoke($tool,$payload));
    $full=timed(fn()=> $tool->checkFreeText($payload));
    echo json_encode(['dataset'=>$name,'rows'=>count($rows),'tokens'=>count($parsed),'outputs'=>count($expected),'parse_ms'=>round($p,3),'full_ms'=>round($full,3),'independent_ratio_pct'=>round(100*$p/max(0.000001,$full),2)],JSON_UNESCAPED_SLASHES)."\n";
}
// No rewrite candidate is produced: the ratio is a separate-run estimate, not an instrumented call-graph fraction.
