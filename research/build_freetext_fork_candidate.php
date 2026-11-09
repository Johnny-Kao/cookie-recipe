<?php
declare(strict_types=1);
function extractFreetextMethod(string $path): string {
    $source = file_get_contents($path);
    if ($source === false) throw new RuntimeException("Missing source $path");
    $start = strpos($source, '    public function getFreetextFeedCorrelations(');
    if ($start === false) throw new RuntimeException('No freetext method');
    $end = strpos($source, "\n    /**", $start);
    if ($end === false) throw new RuntimeException('Missing method boundary');
    return substr($source, $start, $end-$start);
}
$baseline = extractFreetextMethod($argv[1] ?? '');
$candidate = extractFreetextMethod($argv[2] ?? '');
if ($baseline === $candidate) throw new RuntimeException('Fork candidate did not change method');
file_put_contents(__DIR__.'/../build/freetext-extracted.php',
    "<?php\nclass BaselineFreetext extends FreetextHarnessBase {\n".$baseline."\n}\n".
    "class CandidateFreetext extends FreetextHarnessBase {\n".$candidate."\n}\n");
echo "Extracted actual upstream + actual fork candidate methods\n";
