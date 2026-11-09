<?php
declare(strict_types=1);

// Extract the actual upstream method; produce a minimal no-cache candidate.
// Redis calls and their order must remain EXACTLY the same.
$src = file_get_contents($argv[1] ?? '');
if ($src === false) {
    throw new RuntimeException('Missing upstream Feed.php');
}
$start = strpos($src, '    public function attachFeedCorrelations(');
$end = strpos($src, "\n    /**", $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Cannot locate upstream method');
}
$baseline = substr($src, $start, $end - $start);

// Avoid allocating an array_column() temporary for every source/attribute match.
// Direct iteration preserves the original non-strict in_array() comparison semantics.
$before = <<<'CODE'
                    $alreadyAttached = isset($attributes[$attributePosition][$scope]) &&
                        in_array($sourceId, array_column($attributes[$attributePosition][$scope], 'id'));
CODE;
$after = <<<'CODE'
                    $alreadyAttached = false;
                    if (isset($attributes[$attributePosition][$scope])) {
                        foreach ($attributes[$attributePosition][$scope] as $existingSource) {
                            if (is_object($existingSource)) {
                                // Keep array_column() semantics for unusual object-valued entries.
                                $alreadyAttached = in_array($sourceId, array_column($attributes[$attributePosition][$scope], 'id'));
                                break;
                            }
                            if (is_array($existingSource) && array_key_exists('id', $existingSource) && $existingSource['id'] == $sourceId) {
                                $alreadyAttached = true;
                                break;
                            }
                        }
                    }
CODE;
if (substr_count($baseline, $before) !== 1) {
    throw new RuntimeException('Target expression changed upstream');
}
$candidate = str_replace($before, $after, $baseline);

$out = "<?php\nclass BaselineFeed extends FeedHarnessBase {\n" . $baseline . "\n}\n"
     . "class CandidateFeed extends FeedHarnessBase {\n" . $candidate . "\n}\n";
file_put_contents(__DIR__ . '/../build/extracted.php', $out);
echo "Built exact-upstream baseline and strict Redis-order candidate\n";
