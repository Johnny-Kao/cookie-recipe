<?php
declare(strict_types=1);
require __DIR__ . '/regression.php';

mt_srand(20261109);
$types = ['domain','url','domain|ip','hostname|port','comment'];
$feedSources = [];
for ($i=1; $i<=6; $i++) {
    $feedSources[] = ['Feed'=>['id'=>$i,'name'=>"Feed $i",'source_format'=>($i === 6 ? 'csv' : 'misp')]];
}
for ($case=0; $case<100; $case++) {
    $attrs=[];
    $data=[];
    $n = mt_rand(1,15);
    for ($k=0; $k<$n; $k++) {
        $type = $types[mt_rand(0,count($types)-1)];
        $value = $type === 'domain|ip' ? "domain-$k.invalid|192.0.2.$k" :
            ($type === 'hostname|port' ? "domain-$k.invalid|443" :
            ($type === 'url' ? "https://domain-$k.invalid/p" : "domain-$k.invalid"));
        $attr = ['type'=>$type,'value'=>$value];
        if (mt_rand(0,4) === 0) {
            $attr['disable_correlation'] = true;
        }
        if (mt_rand(0,2) === 0) {
            $existing = [];
            for ($j=0; $j<mt_rand(0,8); $j++) {
                $existing[] = ['id'=>[mt_rand(1,6), (string)mt_rand(1,6), null][mt_rand(0,2)],'name'=>'existing'];
            }
            $attr['Feed'] = $existing;
        }
        $attrs[] = $attr;
        $parts = explode('|',$value);
        $keys = [$value, ...$parts];
        if ($type === 'url') $keys[] = substr($value,strpos($value,'://')+3);
        $chosen = [];
        for ($s=1; $s<=6; $s++) {
            if (mt_rand(0,1)) $chosen[]=$s;
        }
        $data[] = ['keys'=>$keys,'source_ids'=>$chosen,'uuids'=>['00000000-0000-4000-8000-' . str_pad((string)$case,12,'0',STR_PAD_LEFT)]];
    }
    // Vary access roles and high-hit limit mode.
    $isAdmin = mt_rand(0,1) === 1;
    $hasPermission = mt_rand(0,9) !== 0;
    compareCase($r, "randomized fixture $case", 'Feed', $feedSources, $attrs, $data, user($isAdmin,$hasPermission), mt_rand(0,1)===1);
}
echo "PASS 100 deterministic randomized Feed regression fixtures\n";
