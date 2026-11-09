<?php
declare(strict_types=1);
$mode = $argv[2] ?? 'md5';
if (!in_array($mode, ['md5','memo'], true)) throw new InvalidArgumentException('Expected md5 or memo');
$src = file_get_contents($argv[1] ?? '');
if ($src === false) throw new RuntimeException('Cannot read pinned upstream file');
$st = strpos($src, '    public function getFreetextFeedCorrelations(');
$en = strpos($src, "\n    /**", $st);
if ($st === false || $en === false) throw new RuntimeException('Cannot extract method');
$baseline = substr($src, $st, $en-$st);
$old = <<<'CODE'
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
$md5 = <<<'CODE'
        foreach ($data as $key => $value) {
            if (isset($correlations[$value['value']])) {
                $data[$key]['correlations'] = array_values($correlations[$value['value']]);
            }
            if ($redis) {
                $hash = md5($value['value']);
                foreach ($feeds as $k => $v) {
                    if ($redis->sismember('misp:feed_cache:' . $v['Feed']['id'], $hash)) {
                        $data[$key]['feed_correlations'][] = array($v);
                    }
                }
            }
        }
CODE;
$memo = <<<'CODE'
        $membershipCache = [];
        foreach ($data as $key => $value) {
            if (isset($correlations[$value['value']])) {
                $data[$key]['correlations'] = array_values($correlations[$value['value']]);
            }
            if ($redis) {
                $hash = md5($value['value']);
                foreach ($feeds as $k => $v) {
                    // Only valid if cached membership cannot change during a request.
                    if (!isset($membershipCache[$value['value']][$k])) {
                        $membershipCache[$value['value']][$k] =
                            $redis->sismember('misp:feed_cache:' . $v['Feed']['id'], $hash) ? 1 : -1;
                    }
                    if ($membershipCache[$value['value']][$k] === 1) {
                        $data[$key]['feed_correlations'][] = array($v);
                    }
                }
            }
        }
CODE;
if (substr_count($baseline,$old) !== 1) throw new RuntimeException('Pinned source changed');
$replacement = $mode === 'md5' ? $md5 : $memo;
$candidate = str_replace($old,$replacement,$baseline);
file_put_contents(__DIR__.'/../build/freetext-extracted.php',
 "<?php\nclass BaselineFreetext extends FreetextHarnessBase {\n".$baseline."\n}\n".
 "class CandidateFreetext extends FreetextHarnessBase {\n".$candidate."\n}\n");
echo "Built local research variant $mode\n";
