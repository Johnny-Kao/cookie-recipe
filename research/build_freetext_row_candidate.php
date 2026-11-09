<?php
declare(strict_types=1);
$src=file_get_contents($argv[1]??'');
if($src===false)throw new RuntimeException('No upstream Feed.php');
$st=strpos($src,'    public function getFreetextFeedCorrelations(');
$en=strpos($src,"\n    /**",$st);
if($st===false||$en===false)throw new RuntimeException('Method missing');
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
        foreach ($data as $key => $value) {
            if (isset($correlations[$value['value']])) {
                $data[$key]['correlations'] = array_values($correlations[$value['value']]);
            }
            // A request does not share membership results across different attributes.
            // Flush each row before visiting the next attribute.
            if ($redis && $feeds) {
                $hash = md5($value['value']);
                $pipe = $redis->pipeline();
                foreach ($feeds as $k => $v) {
                    $redis->sismember('misp:feed_cache:' . $v['Feed']['id'], $hash);
                }
                $hits = $pipe->exec();
                $position = 0;
                foreach ($feeds as $k => $v) {
                    if ($hits[$position++]) {
                        $data[$key]['feed_correlations'][] = array($v);
                    }
                }
            }
        }
CODE;
if(substr_count($baseline,$old)!==1)throw new RuntimeException('Method body changed');
$candidate=str_replace($old,$new,$baseline);
file_put_contents(__DIR__.'/../build/freetext-extracted.php',
 "<?php\nclass BaselineFreetext extends FreetextHarnessBase {\n".$baseline."\n}\n".
 "class CandidateFreetext extends FreetextHarnessBase {\n".$candidate."\n}\n");
echo "Built per-row-only pipelined freetext candidate\n";
