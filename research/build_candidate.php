<?php
declare(strict_types=1);

// Pin the upstream file and extract the actual function, not a reconstructed copy.
$src = file_get_contents($argv[1] ?? '');
if ($src === false) { throw new RuntimeException('Missing upstream Feed.php'); }
$start = strpos($src, '    public function attachFeedCorrelations(');
$end = strpos($src, "\n    /**", $start);
if ($start === false || $end === false) { throw new RuntimeException('Cannot locate upstream method'); }
$baseline = substr($src, $start, $end - $start);
$before = <<<'CODE'
        foreach ($sources as $source) {
CODE;
$after = <<<'CODE'
        $eventUuidLookupCache = [];
        foreach ($sources as $source) {
CODE;
if (substr_count($baseline, $before) !== 1) { throw new RuntimeException('Source loop changed'); }
$candidate = str_replace($before, $after, $baseline);
$before = <<<'CODE'
                $pipe = $redis->pipeline();
                $eventUuidHitPosition = [];
                foreach ($hitIds as $sourceHitPos => $k) {
                    if ($sourceHits[$sourceHitPos]) {
                        $redis->smembers($cachePrefix . 'event_uuid_lookup:' . $hashTable[$k]);
                        $eventUuidHitPosition[] = $redisResultToAttributePosition[$k];
                    }
                }
                $mispFeedHits = $pipe->exec();
CODE;
$after = <<<'CODE'
                $eventUuidHitPosition = [];
                $lookupHashes = [];
                $missingHashes = [];
                foreach ($hitIds as $sourceHitPos => $k) {
                    if ($sourceHits[$sourceHitPos]) {
                        $hash = $hashTable[$k];
                        $lookupHashes[] = $hash;
                        $eventUuidHitPosition[] = $redisResultToAttributePosition[$k];
                        if (!array_key_exists($hash, $eventUuidLookupCache)) {
                            $missingHashes[$hash] = true;
                        }
                    }
                }
                if ($missingHashes) {
                    $pipe = $redis->pipeline();
                    foreach ($missingHashes as $hash => $_) {
                        $redis->smembers($cachePrefix . 'event_uuid_lookup:' . $hash);
                    }
                    $loaded = $pipe->exec();
                    foreach (array_keys($missingHashes) as $position => $hash) {
                        $eventUuidLookupCache[$hash] = $loaded[$position];
                    }
                }
                $mispFeedHits = [];
                foreach ($lookupHashes as $hash) {
                    $mispFeedHits[] = $eventUuidLookupCache[$hash];
                }
CODE;
if (substr_count($candidate, $before) !== 1) { throw new RuntimeException('Target block changed'); }
$candidate = str_replace($before, $after, $candidate);
$out = "<?php\nclass BaselineFeed extends FeedHarnessBase {\n" . $baseline . "\n}\n"
     . "class CandidateFeed extends FeedHarnessBase {\n" . $candidate . "\n}\n";
file_put_contents(__DIR__ . '/../build/extracted.php', $out);
echo "Built exact-upstream baseline and isolated candidate\n";
