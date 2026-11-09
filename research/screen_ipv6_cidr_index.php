<?php
declare(strict_types=1);
$source = file_get_contents($argv[1] ?? '');
if ($source === false) throw new RuntimeException('Missing pinned CidrTool');
$baseline = str_replace('class CidrTool', 'class CidrToolOriginal', $source, $count);
if ($count !== 1) throw new RuntimeException('Class changed');
$candidate = str_replace('class CidrTool', 'class CidrToolIndexed', $source, $count);
if ($count !== 1) throw new RuntimeException('Class changed');
$patches = [
    'private $ipv6 = [];' => 'private $ipv6 = [];' . "\n    " . 'private $ipv6Index = [];',
    '$this->ipv6[$mask][] = $ipBytes;' => <<<'PHP'
$this->ipv6[$mask][] = $ipBytes;
                if (ctype_digit((string)$mask) && (int)$mask <= 128) {
                    $key = self::ipv6PrefixKey($ipBytes, (int)$mask);
                    if (!isset($this->ipv6Index[$mask][$key])) {
                        $this->ipv6Index[$mask][$key] = $ipBytes;
                    }
                }
PHP,
    '$value = unpack(\'n*\', inet_pton($value));' => '$packedValue = inet_pton($value);' . "\n            " . '$value = unpack(\'n*\', $packedValue);',
    <<<'PHP'
                foreach ($lv as $l) {
                    if ($this->ipv6InCidr($value, $l, $netmask)) {
                        $match = inet_ntop($l) . "/$netmask";
                        break;
                    }
                }
PHP => <<<'PHP'
                if (ctype_digit((string)$netmask) && (int)$netmask <= 128) {
                    $key = self::ipv6PrefixKey($packedValue, (int)$netmask);
                    if (isset($this->ipv6Index[$netmask][$key])) {
                        $match = inet_ntop($this->ipv6Index[$netmask][$key]) . "/$netmask";
                    }
                } else {
                    foreach ($lv as $l) {
                        if ($this->ipv6InCidr($value, $l, $netmask)) {
                            $match = inet_ntop($l) . "/$netmask";
                            break;
                        }
                    }
                }
PHP,
];
foreach ($patches as $needle=>$replacement) {
    if (substr_count($candidate,$needle)!==1) throw new RuntimeException('Patch signature mismatch: '.substr($needle,0,50));
    $candidate=str_replace($needle,$replacement,$candidate);
}
$injection=<<<'PHP'
    private static function ipv6PrefixKey(string $ip, int $mask): string
    {
        $full = intdiv($mask, 8);
        $remainder = $mask % 8;
        $key = substr($ip, 0, $full);
        if ($remainder) {
            $key .= chr(ord($ip[$full]) & (0xff << (8 - $remainder)));
        }
        return $key;
    }

PHP;
if (substr_count($candidate,'    public function __construct(')!==1) throw new RuntimeException('Constructor changed');
$candidate=str_replace('    public function __construct(', $injection.'    public function __construct(', $candidate);
$dir=dirname(__DIR__).'/build';
file_put_contents($dir.'/CidrToolOriginal.php',$baseline);
file_put_contents($dir.'/CidrToolIndexed.php',$candidate);
require $dir.'/CidrToolOriginal.php';
require $dir.'/CidrToolIndexed.php';
function ipv6(int $i):string {
    return inet_ntop(pack('n8',0x2001,0xdb8,$i%65536,($i*37)%65536,($i*17)%65536,($i*71)%65536,($i*127)%65536,($i*13)%65536));
}
function makeCidrs(int $size):array {
    $cidrs=['::1/128','2001:db8:1::/48'];
    $masks=[64,80,96,112,120,124,128];
    for($i=0;$i<$size;$i++)$cidrs[]=ipv6($i).'/'.$masks[$i%count($masks)];
    return $cidrs;
}
function makeQueries():array {
    $queries=['::1','::','2001:db8::1','ffff::1','::ffff:192.0.2.7','192.0.2.7','bad','2001:db8:1::/16','2001:db8:2::/128','2001:db8::/0','2001:db8::/129','2001:db8::/-1'];
    for($i=0;$i<1200;$i++) {
        $ip=ipv6($i%750);
        $queries[]=$i%7===0?$ip.'/64':$ip;
    }
    return $queries;
}
$queries=makeQueries();
foreach([0,50,500,3000] as $size) {
    $cidrs=makeCidrs($size);
    $a=new CidrToolOriginal($cidrs);
    $b=new CidrToolIndexed($cidrs);
    foreach($queries as $i=>$q) {
        $old=$a->contains($q);
        $new=$b->contains($q);
        if($old!==$new)throw new RuntimeException('Mismatch size='.$size.' i='.$i.' input='.$q.' original='.var_export($old,true).' indexed='.var_export($new,true));
    }
    echo 'PASS IPv6 contains strict equality '.count($queries).' queries list='.count($cidrs)."\n";
    if ($size===0)continue;
    // Time only lookup, separately report construction cost.
    $buildOriginal=timedBuild(fn()=>new CidrToolOriginal($cidrs));
    $buildIndexed=timedBuild(fn()=>new CidrToolIndexed($cidrs));
    $times=['original'=>[],'indexed'=>[]];
    for($round=0;$round<5;$round++){
        foreach(($round%2===0?['original','indexed']:['indexed','original']) as $mode){
            $obj=$mode==='original'?$a:$b;
            $start=hrtime(true);
            foreach($queries as $q)$obj->contains($q);
            $times[$mode][]=(hrtime(true)-$start)/1e6;
        }
    }
    sort($times['original']); sort($times['indexed']);
    $old=$times['original'][2];$new=$times['indexed'][2];
    echo json_encode(['cidrs'=>count($cidrs),'queries'=>count($queries),'lookup_original_ms'=>round($old,3),'lookup_indexed_ms'=>round($new,3),'speedup'=>round($old/$new,2),'build_original_ms'=>round($buildOriginal,3),'build_indexed_ms'=>round($buildIndexed,3)],JSON_UNESCAPED_SLASHES)."\n";
}
function timedBuild(callable $c):float {
    $samples=[];
    for($i=0;$i<4;$i++){ $start=hrtime(true);$c();$samples[]=(hrtime(true)-$start)/1e6; }
    sort($samples);
    return ($samples[1]+$samples[2])/2;
}
