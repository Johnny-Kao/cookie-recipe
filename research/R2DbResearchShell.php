<?php
/**
 * R2 real CakePHP / MariaDB research, no app code modifications.
 * Executes unmodified Sighting::restSearch and decodes actual JSON output.
 */
App::uses('AppShell', 'Console/Command');

class R2DbResearchShell extends AppShell
{
    public $uses = array('Sighting');

    public function main()
    {
        // Site admin is a deliberate control: isolate query correctness from unrelated ACL.
        $user = array('id' => 9101, 'org_id' => 9101, 'Role' => array(
            'id' => 1, 'perm_site_admin' => true, 'perm_sync' => true, 'perm_auth' => true
        ));
        Configure::write('Plugin.Sightings_policy', Sighting::SIGHTING_POLICY_EVERYONE);
        Configure::write('MISP.disable_sighting_loading', false);
        $db = $this->Sighting->getDataSource();
        $rows = $db->fetchAll('SELECT id, org_id FROM sightings WHERE id IN (9101,9102) ORDER BY id');
        if (count($rows) !== 2) {
            throw new RuntimeException('Expected real DB sightings fixtures absent '.json_encode($rows));
        }
        echo 'PASS real DB fixture '.json_encode($rows)."\n";
        $a = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $b = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $unknown = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $mode = isset($this->args[0]) ? $this->args[0] : 'original';
        if (!in_array($mode, array('original', 'fixed'), true)) {
            throw new RuntimeException('Unknown test mode');
        }
        $cases = array(
            'control-a' => array($a),
            'control-b' => array($b),
            'control-two-valid' => array($a,$b),
            'mixed-valid-unknown' => array($a,$unknown),
            'mixed-valid-integer' => array($a,9102),
            'mixed-valid-negated-unknown' => array($a,'!'.$unknown),
            'numeric-only' => array(9101),
            'numeric-string-only' => array('9102'),
            'numeric-negation' => array('!9101'),
            'unknown-only' => array($unknown),
            'negated-unknown-only' => array('!'.$unknown),
            'valid-negation' => array('!'.$a),
            'valid-plus-negation' => array($a,'!'.$b),
        );
        foreach ($cases as $name => $orgIds) {
            $warnings = array();
            set_error_handler(function ($severity,$msg) use (&$warnings) {
                $warnings[] = $msg;
                return true;
            });
            try {
                // "last" includes the in-test fixtures, avoiding any date filtering confounders.
                $tmp = $this->Sighting->restSearch($user, 'json', array('org_id'=>$orgIds, 'last'=>'365d'));
                $json = $tmp->intoString();
                $response = json_decode($json,true);
                if (!is_array($response) || !isset($response['response'])) {
                    throw new RuntimeException('Invalid actual REST payload '.substr($json,0,550));
                }
                $ids = array();
                foreach ($response['response'] as $record) {
                    if (isset($record['Sighting']['id'])) {
                        $ids[] = (int)$record['Sighting']['id'];
                    } elseif (isset($record['id'])) {
                        $ids[] = (int)$record['id'];
                    } else {
                        throw new RuntimeException('Unexpected sighting schema '.json_encode($record));
                    }
                }
                sort($ids);
                $expectedObserved = array(
                    'control-a'=>array(9101),
                    'control-b'=>array(9102),
                    'control-two-valid'=>array(9101,9102),
                    'mixed-valid-unknown'=>array(9101),
                    'mixed-valid-integer'=>array(9101),
                    'mixed-valid-negated-unknown'=>array(),
                    'numeric-only'=>array(),
                    'numeric-string-only'=>array(),
                    'numeric-negation'=>array(),
                    'unknown-only'=>array(),
                    'negated-unknown-only'=>array(),
                    'valid-negation'=>array(9102),
                    'valid-plus-negation'=>array(9101),
                );
                if ($mode === 'fixed') {
                    $expectedObserved = array_merge($expectedObserved, array(
                        'mixed-valid-unknown'=>array(9101),
                        'mixed-valid-integer'=>array(9101,9102),
                        'mixed-valid-negated-unknown'=>array(9101),
                        'numeric-only'=>array(9101),
                        'numeric-string-only'=>array(9102),
                        'numeric-negation'=>array(9102),
                        'negated-unknown-only'=>array(9101,9102),
                    ));
                    if (in_array('Undefined variable $temp', $warnings, true)) {
                        throw new RuntimeException('Fixed code still uses undefined temp: '.$name);
                    }
                }
                if ($ids !== $expectedObserved[$name]) {
                    throw new RuntimeException('Observed R2 result changed: '.json_encode($ids).' expected '.json_encode($expectedObserved[$name]));
                }
                echo 'R2 DB RESULT '.json_encode(array('case'=>$name,'org_id'=>$orgIds,'sighting_ids'=>$ids,'warnings'=>$warnings))."\n";
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                echo 'R2 DB FAILURE '.json_encode(array('case'=>$name,'exception'=>get_class($e),'error'=>$e->getMessage(),'warnings'=>$warnings))."\n";
            } finally {
                restore_error_handler();
            }
        }
        echo "PASS R2 DB result-gathering completed\n";
    }
}
