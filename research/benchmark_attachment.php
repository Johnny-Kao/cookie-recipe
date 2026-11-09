<?php
declare(strict_types=1);
require __DIR__ . '/regression.php';

// Stress only the previously costly array_column/in_array path.
// Not representative of typical source-list sizes.
$cases = [[100, 20], [250, 80], [400, 120]];
$summary = [];
foreach ($cases as [$attributeCount, $existingCount]) {
    $sources = [['Feed'=>['id'=>1,'name'=>'main','source_format'=>'misp']]];
    $attrs = [];
    $data = [];
    $existing = [['id'=>1,'name'=>'matching first']];
    foreach (range(2, $existingCount) as $id) {
        $existing[] = ['id'=>$id,'name'=>'other'];
    }
    for ($i=0; $i<$attributeCount; $i++) {
        $value = 'synthetic-' . $i . '.invalid';
        $attrs[] = ['type'=>'domain', 'value'=>$value, 'Feed'=>$existing];
        $data[] = ['keys'=>[$value], 'source_ids'=>[1]];
    }
    clean($r);
    fixture($r,'Feed',$sources,$data);
    $samples=['baseline'=>[], 'candidate'=>[]];
    for ($repeat=0; $repeat<9; $repeat++) {
        $pair = ($repeat % 2 === 0) ? ['baseline','candidate'] : ['candidate','baseline'];
        $outputs = [];
        foreach ($pair as $which) {
            $class = $which === 'baseline' ? BaselineFeed::class : CandidateFeed::class;
            $feed = new $class($r,$sources);
            $event = [];
            $start=hrtime(true);
            $results = $feed->attachFeedCorrelations($attrs,user(),$event);
            $samples[$which][] = (hrtime(true)-$start)/1e6;
            $outputs[$which] = [$results,$event];
        }
        if ($outputs['baseline'] !== $outputs['candidate']) {
            throw new RuntimeException('Output mismatch in performance fixture');
        }
    }
    foreach ($samples as &$v) { sort($v, SORT_NUMERIC); }
    unset($v);
    $baselineMedian = $samples['baseline'][4];
    $candidateMedian = $samples['candidate'][4];
    $summary[] = [
        'attributes'=>$attributeCount,
        'existing_source_entries'=>$existingCount,
        'iterations'=>9,
        'median_baseline_ms'=>round($baselineMedian,3),
        'median_candidate_ms'=>round($candidateMedian,3),
        'median_improvement_pct'=>round(100*($baselineMedian-$candidateMedian)/$baselineMedian,1),
        'equivalent'=>true
    ];
}
clean($r);
echo json_encode(['benchmark'=>'PHP duplicate source attachment check','results'=>$summary,'caveat'=>'synthetic early-match heavy preexisting source lists; real workloads may have little benefit'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
