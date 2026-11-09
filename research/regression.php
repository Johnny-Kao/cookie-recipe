<?php
declare(strict_types=1);
class MispAttribute {
    public const NON_CORRELATING_TYPES = ['comment'];
    public const PRIMARY_ONLY_CORRELATING_TYPES = ['hostname|port'];
}
class Configure {
    public static function read(string $key) { return $key === 'MISP.host_org_id' ? 1 : null; }
}
class AttributeStub {
    public function getCompositeTypes(): array { return ['domain|ip', 'hostname|port']; }
}
class FeedHarnessBase {
    public $Attribute;
    private $redis;
    private $sources;
    public function __construct($redis, array $sources) {
        $this->redis = $redis;
        $this->sources = $sources;
        $this->Attribute = new AttributeStub();
    }
    public function setupRedisWithException() { return $this->redis; }
    public function getCachedFeedsOrServers(array $user, string $scope): array { return $this->sources; }
}
require __DIR__ . '/../build/extracted.php';
$r = new Redis();
$r->connect('127.0.0.1', 6379, 2.0);
$prefixes = ['misp:feed_cache:', 'misp:server_cache:'];
// Each case runs on a clean synthetic namespace.
function clean(Redis $r): void {
    foreach (['misp:feed_cache:*','misp:server_cache:*'] as $pattern) {
        $keys = $r->keys($pattern);
        if ($keys) $r->del($keys);
    }
}
function user(bool $admin = true, bool $allowed = true): array {
    return ['Role' => ['perm_view_feed_correlations' => $allowed, 'perm_site_admin' => $admin], 'org_id' => $admin ? 1 : 2];
}
function fixture(Redis $r, string $scope, array $sources, array $attributes): void {
    $prefix = 'misp:' . strtolower($scope) . '_cache:';
    foreach ($sources as $source) {
        $id = $source[$scope]['id'];
        foreach ($attributes as $attr) {
            foreach (($attr['keys'] ?? []) as $key) {
                $hash = md5($key);
                $r->sAdd($prefix . 'combined', $hash);
                if (in_array($id, $attr['source_ids'] ?? [], true)) {
                    $r->sAdd($prefix . $id, $hash);
                    foreach (($attr['uuids'] ?? ['00000000-0000-4000-8000-000000000001']) as $uuid) {
                        $r->sAdd($prefix . 'event_uuid_lookup:' . $hash, $id . '/' . $uuid);
                    }
                }
            }
        }
    }
}
function compareCase(Redis $r, string $name, string $scope, array $sources, array $attrs, array $fixtureData, array $user, bool $override = false): array {
    clean($r);
    fixture($r, $scope, $sources, $fixtureData);
    $original = new BaselineFeed($r, $sources);
    $candidate = new CandidateFeed($r, $sources);
    $baseEvent = [];
    $candidateEvent = [];
    $base = $original->attachFeedCorrelations($attrs, $user, $baseEvent, $override, $scope);
    $test = $candidate->attachFeedCorrelations($attrs, $user, $candidateEvent, $override, $scope);
    if ($base !== $test || $baseEvent !== $candidateEvent) {
        fwrite(STDERR, "FAIL $name\n" . var_export(['baseline' => $base, 'candidate' => $test, 'baseEvent' => $baseEvent, 'candidateEvent' => $candidateEvent], true) . "\n");
        exit(1);
    }
    echo "PASS $name\n";
    return [$base, $baseEvent];
}
$feedSources = [
    ['Feed' => ['id' => 1, 'name' => 'MISP 1', 'source_format' => 'misp']],
    ['Feed' => ['id' => 2, 'name' => 'MISP 2', 'source_format' => 'misp']],
    ['Feed' => ['id' => 3, 'name' => 'CSV', 'source_format' => 'csv']]
];
$serverSources = [
    ['Server' => ['id' => 1, 'name' => 'Server 1', 'url' => 'https://local.invalid']],
    ['Server' => ['id' => 2, 'name' => 'Server 2', 'url' => 'https://remote.invalid']]
];
$attrs = [
    ['type'=>'domain', 'value'=>'example.org'],
    ['type'=>'domain', 'value'=>'example.org'],
    ['type'=>'domain|ip', 'value'=>'example.org|192.0.2.4'],
    ['type'=>'url', 'value'=>'https://example.org/path'],
    ['type'=>'hostname|port', 'value'=>'example.org|443'],
    ['type'=>'comment', 'value'=>'skip'],
    ['type'=>'domain', 'value'=>'ignore.invalid', 'disable_correlation'=>true]
];
$data = [
    ['keys'=>['example.org'], 'source_ids'=>[1,2,3], 'uuids'=>['00000000-0000-4000-8000-000000000001','00000000-0000-4000-8000-000000000002']],
    ['keys'=>['192.0.2.4'], 'source_ids'=>[2]],
    ['keys'=>['https://example.org/path','example.org/path'], 'source_ids'=>[1,3]],
    ['keys'=>['skip','ignore.invalid'], 'source_ids'=>[1]]
];
compareCase($r, 'feed multi-source and duplicates', 'Feed', $feedSources, $attrs, $data, user());
$prepopulated = $attrs;
$prepopulated[0]['Feed'] = [
    ['id'=>1, 'name'=>'previously attached'],
    ['id'=>'2', 'name'=>'numeric string'],
    ['id'=>null, 'name'=>'null id'],
    ['name'=>'missing id']
];
compareCase($r, 'existing source IDs including numeric strings and null', 'Feed', $feedSources, $prepopulated, $data, user());
$manyExisting = $attrs;
$manyExisting[0]['Feed'] = array_merge([['id'=>1, 'name'=>'early match']], array_map(static fn($id) => ['id'=>$id], range(50, 150)));
compareCase($r, 'early match in large existing source list', 'Feed', $feedSources, $manyExisting, $data, user());
$objectExisting = $attrs;
$objectExisting[0]['Feed'] = [(object)['id'=>1,'name'=>'object entry'], ['id'=>2,'name'=>'array entry']];
$csvOnly = [['Feed'=>['id'=>3,'name'=>'CSV','source_format'=>'csv']]];
$csvFixture = [['keys'=>['example.org'],'source_ids'=>[3]]];
compareCase($r, 'object-valued existing source fallback', 'Feed', $csvOnly, [$objectExisting[0]], $csvFixture, user());
compareCase($r, 'feed permission denied', 'Feed', $feedSources, $attrs, $data, user(true, false));
compareCase($r, 'feed no eligible attributes', 'Feed', $feedSources, [['type'=>'comment','value'=>'no']], $data, user());
compareCase($r, 'feed no cache match', 'Feed', $feedSources, [['type'=>'domain','value'=>'no-match']], $data, user());
compareCase($r, 'feed empty attributes', 'Feed', $feedSources, [], $data, user());
compareCase($r, 'feed override limit', 'Feed', $feedSources, $attrs, $data, user(), true);
compareCase($r, 'server admin', 'Server', $serverSources, $attrs, $data, user());
compareCase($r, 'server non-admin', 'Server', $serverSources, $attrs, $data, user(false));
$large = array_fill(0,10001,['type'=>'domain','value'=>'example.org']);
[$largeResult,$largeEvent] = compareCase($r, '10k hit cutoff', 'Feed', $feedSources, $large, $data, user());
if (($largeEvent['FeedCount'] ?? 0) !== 10001 || !isset($largeResult[0]['FeedHit'])) { throw new RuntimeException('10k fixture did not trigger cutoff'); }
clean($r);
echo "PASS all extracted-method regression cases\n";
