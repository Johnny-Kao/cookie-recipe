<?php
declare(strict_types=1);
$sourceFile = $argv[1] ?? '';
$source = file_get_contents($sourceFile);
if ($source === false) throw new RuntimeException('Missing pinned ComplexTypeTool source');
$needle = <<<'PHP'
        $refanged = $input['raw'];
        foreach (self::REFANG_REGEX_TABLE as $regex) {
PHP;
$replacement = <<<'PHP'
        $refanged = $input['raw'];
        // Plain indicators need neither refanging nor the bracket callback.
        // The prefix list mirrors the beginning of REFANG_REGEX_TABLE[0].
        if (strcspn($refanged, '[(') === strlen($refanged) &&
            !in_array(strtolower(substr($refanged, 0, 4)), ['hxxp', 'hxtp', 'htxp', 'meow'], true)) {
            $input['refanged'] = rtrim($refanged, '.');
            return $input;
        }
        foreach (self::REFANG_REGEX_TABLE as $regex) {
PHP;
$functionStart = strpos($source, '    private function __refangInput(');
$functionEnd = strpos($source, '    private function __checkForSimpleRegex(', $functionStart);
if ($functionStart === false || $functionEnd === false) throw new RuntimeException('Source methods changed');
$segment = substr($source, $functionStart, $functionEnd - $functionStart);
if (substr_count($segment, $needle) !== 1) throw new RuntimeException('Source check failed');
$updatedSegment = str_replace($needle, $replacement, $segment);
$updated = substr($source, 0, $functionStart) . $updatedSegment . substr($source, $functionEnd);
$updated = str_replace('class ComplexTypeTool' . PHP_EOL, 'class ComplexTypeToolFastPath' . PHP_EOL, $updated, $count);
if ($count !== 1) throw new RuntimeException('Class declaration changed');
file_put_contents(__DIR__.'/../build/ComplexTypeToolFastPath.php', $updated);
echo "Generated isolated fast-path candidate from pinned upstream method\n";
