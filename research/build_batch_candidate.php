<?php
declare(strict_types=1);
// Research-only extraction of the exact upstream method and membership batching.
// Leaves UUID SMEMBERS, source iteration and result assembly unchanged.
$src=file_get_contents($argv[1]??'');
if ($src===false) throw new RuntimeException('Missing upstream source');
$start=strpos($src,'    public function attachFeedCorrelations(');
$end=strpos($src,"\n    /**",$start);
if ($start===false||$end===false) throw new RuntimeException('Upstream method not found');
$baseline=substr($src,$start,$end-$start);
$original=<<<'CODE'
        $pipe = $redis->pipeline();
        $hashTable = [];
        $redisResultToAttributePosition = [];
CODE;
$replacement=<<<'CODE'
        $hashTable = [];
        $redisResultToAttributePosition = [];
CODE;
if (substr_count($baseline,$original)!==1) throw new RuntimeException('Global pipeline declaration changed');
$candidate=str_replace($original,$replacement,$baseline);
$original=<<<'CODE'
                $redis->sismember($cachePrefix . 'combined', $md5);
CODE;
if (substr_count($candidate,$original)!==1) throw new RuntimeException('Global checks changed');
$candidate=str_replace($original,'                // Deferred to a single ordered membership command.', $candidate);
$original=<<<'CODE'
            $pipe->discard();
            return $attributes;
CODE;
$replacement=<<<'CODE'
            return $attributes;
CODE;
if(substr_count($candidate,$original)!==1)throw new RuntimeException('Empty branch changed');
$candidate=str_replace($original,$replacement,$candidate);
$original=<<<'CODE'
        $results = $pipe->exec();
CODE;
$replacement=<<<'CODE'
        $results = $redis->sMIsMember($cachePrefix . 'combined', $hashTable);
CODE;
if(substr_count($candidate,$original)!==1)throw new RuntimeException('Combined results changed');
$candidate=str_replace($original,$replacement,$candidate);
$original=<<<'CODE'
            $pipe = $redis->pipeline();
            foreach ($hitIds as $k) {
                $redis->sismember($cachePrefix . $sourceId, $hashTable[$k]);
            }
            $sourceHits = $pipe->exec();
CODE;
$replacement=<<<'CODE'
            $sourceHashes = [];
            foreach ($hitIds as $k) {
                $sourceHashes[] = $hashTable[$k];
            }
            $sourceHits = $redis->sMIsMember($cachePrefix . $sourceId, $sourceHashes);
CODE;
if(substr_count($candidate,$original)!==1)throw new RuntimeException('Per-source checks changed');
$candidate=str_replace($original,$replacement,$candidate);
file_put_contents(__DIR__.'/../build/batch-extracted.php',
    "<?php\nclass BaselineFeed extends FeedHarnessBase {\n".$baseline."\n}\n".
    "class CandidateFeed extends FeedHarnessBase {\n".$candidate."\n}\n");
echo "Built research-only SMISMEMBER candidate\n";
