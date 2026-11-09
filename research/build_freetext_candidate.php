<?php
declare(strict_types=1);
$src=file_get_contents($argv[1]??'');
if($src===false) throw new RuntimeException('No upstream source');
$st=strpos($src,'    public function getFreetextFeedCorrelations(');
$en=strpos($src,"\n    /**",$st);
if($st===false||$en===false) throw new RuntimeException('Cannot extract freetext method');
$baseline=substr($src,$st,$en-$st);
$old=<<<'CODE'
        foreach ($data as $key => $value) {
            if (isset($correlations[$value['value']])) {
                $data[$key]['correlations'] = array_values($correlations[$value['value']]);
            }
            if ($redis) {
                foreach ($feeds as $k => $v) {
                    if ($redis->sismember('misp:feed_cache:' . $v['Feed']['id'], md5($value['value']))) {
                        $data[$key]['feed_correlations'][] = array($v);
                    }
                }
            }
        }
CODE;
$new=<<<'CODE'
        // Preserve original data/Feed traversal order while reducing Redis round trips.
        // Flush bounded pipeline batches before annotating results, maintaining positions.
        $pending = [];
        $pipe = null;
        $batchSize = 256;
        foreach ($data as $key => $value) {
            if (isset($correlations[$value['value']])) {
                $data[$key]['correlations'] = array_values($correlations[$value['value']]);
            }
            if ($redis) {
                $hash = md5($value['value']);
                foreach ($feeds as $k => $v) {
                    if ($pipe === null) {
                        $pipe = $redis->pipeline();
                    }
                    $redis->sismember('misp:feed_cache:' . $v['Feed']['id'], $hash);
                    $pending[] = [$key, $k];
                    if (count($pending) === $batchSize) {
                        $hits = $pipe->exec();
                        foreach ($pending as $position => $reference) {
                            if ($hits[$position]) {
                                $data[$reference[0]]['feed_correlations'][] = [$feeds[$reference[1]]];
                            }
                        }
                        $pending = [];
                        $pipe = null;
                    }
                }
            }
        }
        if ($pipe !== null) {
            $hits = $pipe->exec();
            foreach ($pending as $position => $reference) {
                if ($hits[$position]) {
                    $data[$reference[0]]['feed_correlations'][] = [$feeds[$reference[1]]];
                }
            }
        }
CODE;
if(substr_count($baseline,$old)!==1)throw new RuntimeException('Upstream target changed');
$candidate=str_replace($old,$new,$baseline);
file_put_contents(__DIR__.'/../build/freetext-extracted.php',
    "<?php\nclass BaselineFreetext extends FreetextHarnessBase {\n".$baseline."\n}\n".
    "class CandidateFreetext extends FreetextHarnessBase {\n".$candidate."\n}\n");
echo "Built pinned exact freetext method with bounded pipeline research candidate\n";
