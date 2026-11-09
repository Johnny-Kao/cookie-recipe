<?php
declare(strict_types=1);

/**
 * Independent Redis microbenchmark of redundant global UUID lookups.
 * This is NOT a drop-in patch or a claim of MISP integration equivalence.
 */
if (!class_exists('Redis')) {
    fwrite(STDERR, "phpredis extension missing\n");
    exit(2);
}
$redis = new Redis();
$redis->connect('127.0.0.1', 6379, 2);
$prefix = 'misp-research-' . bin2hex(random_bytes(6)) . ':';
$hashes = (int) (getenv('HASHES') ?: 1000);
$sources = (int) (getenv('SOURCES') ?: 10);
if ($hashes < 1 || $sources < 1 || $hashes > 10000 || $sources > 100) {
    throw new InvalidArgumentException('Invalid benchmark dimensions');
}
$keys = [];
try {
    for ($h = 0; $h < $hashes; $h++) {
        $key = $prefix . 'event_uuid_lookup:' . md5('attribute-' . $h);
        $keys[] = $key;
        for ($s = 1; $s <= $sources; $s++) {
            $redis->sAdd($key, $s . '/00000000-0000-4000-8000-' . str_pad((string)$h, 12, '0', STR_PAD_LEFT));
        }
    }
    $run = static function (bool $optimized) use ($redis, $keys, $sources): array {
        $count = 0;
        $start = hrtime(true);
        $memoryStart = memory_get_peak_usage(true);
        $result = [];
        if ($optimized) {
            $cache = [];
            foreach ($keys as $key) {
                $cache[$key] = $redis->sMembers($key);
                $count++;
            }
            foreach (range(1, $sources) as $source) {
                foreach ($keys as $key) {
                    $result[$source][$key] = array_values(array_filter($cache[$key], static fn($v) => explode('/', $v, 2)[0] === (string)$source));
                }
            }
        } else {
            foreach (range(1, $sources) as $source) {
                foreach ($keys as $key) {
                    $members = $redis->sMembers($key);
                    $count++;
                    $result[$source][$key] = array_values(array_filter($members, static fn($v) => explode('/', $v, 2)[0] === (string)$source));
                }
            }
        }
        foreach ($result as &$group) {
            foreach ($group as &$members) {
                sort($members);
            }
        }
        unset($group, $members);
        return [$result, $count, (hrtime(true) - $start) / 1e6, memory_get_peak_usage(true) - $memoryStart];
    };
    [$before, $originalCommands, $originalMs, $originalPeak] = $run(false);
    [$after, $optimizedCommands, $optimizedMs, $optimizedPeak] = $run(true);
    if ($before !== $after) {
        fwrite(STDERR, "FAIL: synthetic output mismatch\n");
        exit(1);
    }
    echo json_encode([
        'status' => 'PASS', 'benchmark' => 'synthetic-redis-uuid-lookup',
        'hashes' => $hashes, 'sources' => $sources,
        'baseline_smembers' => $originalCommands,
        'candidate_smembers' => $optimizedCommands,
        'baseline_ms' => round($originalMs, 3),
        'candidate_ms' => round($optimizedMs, 3),
        'baseline_peak_growth_bytes' => $originalPeak,
        'candidate_peak_growth_bytes' => $optimizedPeak,
        'limitation' => 'synthetic-only; candidate cache is unbounded and not a proposed production patch'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    foreach ($keys as $key) {
        $redis->del($key);
    }
}
