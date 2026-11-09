<?php
declare(strict_types=1);
// Independent Redis/Valkey experiment. No MISP application or upstream writes.
$reader = new Redis();
$writer = new Redis();
$reader->connect('127.0.0.1', 6379, 2);
$writer->connect('127.0.0.1', 6379, 2);
$k = 'cookie-recipe:publication:feed';
$tmp = 'cookie-recipe:publication:staging';
$old = md5('old.example');
$new = md5('new.example');
function check(bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException("FAIL: ".$description);
    echo "PASS ".$description."\n";
}
try {
    $writer->del([$k,$tmp]);
    $writer->sAdd($k,$old);
    check($reader->sIsMember($k,$old), 'old set present before rebuild');
    // Existing MISP __cacheFreetextFeed first deletes the live key.
    $writer->del($k);
    check(!$reader->exists($k), 'delete-and-rebuild exposes missing live key');
    check(!$reader->sIsMember($k,$old), 'old entry falsely absent during rebuild');
    $writer->sAdd($k,$new);
    check($reader->sIsMember($k,$new) && !$reader->sIsMember($k,$old),
        'new set visible after rebuild');
    // Alternative experiment only: stage under another key and switch with RENAME.
    $writer->del([$k,$tmp]);
    $writer->sAdd($k,$old);
    $writer->sAdd($tmp,$new);
    check($reader->sIsMember($k,$old) && !$reader->sIsMember($k,$new),
        'temporary-set construction leaves published set intact');
    $writer->rename($tmp,$k);
    check($reader->sIsMember($k,$new) && !$reader->sIsMember($k,$old),
        'atomic replacement exposes fully built new set');
    // An atomic single-key swap is not a multi-key request snapshot.
    $writer->sAdd($tmp,$old);
    $a = $reader->sIsMember($k,$new); // read new generation
    $writer->rename($tmp,$k);
    $b = $reader->sIsMember($k,$new); // read old generation
    check($a && !$b, 'individual atomic swaps do not pin a version across two reads');
} finally {
    $writer->del([$k,$tmp]);
}
