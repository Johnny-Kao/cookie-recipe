<?php
/**
 * Research-only CakePHP shell installed into an ephemeral upstream checkout.
 * Uses the real CakePHP bootstrap, MISP models and MariaDB data; no mocked services.
 */
App::uses('AppShell', 'Console/Command');

class R1DbResearchShell extends AppShell
{
    public $uses = array('Sighting');

    public function main()
    {
        $mode = isset($this->args[0]) ? $this->args[0] : 'original';
        if (!in_array($mode, array('original', 'fixed'), true)) {
            throw new RuntimeException('Invalid mode');
        }
        Configure::write('Plugin.Sightings_policy', Sighting::SIGHTING_POLICY_SIGHTING_REPORTER);
        $user = array(
            'id' => 9001,
            'org_id' => 9001,
            'Role' => array(
                'id' => 1,
                'perm_site_admin' => true,
                'perm_sync' => true,
                'perm_auth' => true,
                'perm_tagger' => true,
                'perm_publish' => true,
                'perm_modify' => true,
            )
        );
        $connection = $this->Sighting->getDataSource();
        $fixture = $connection->fetchAll('SELECT id, attribute_id, date_sighting FROM sightings WHERE id = 9001');
        if (count($fixture) !== 1 || (int)$fixture[0]['sightings']['attribute_id'] !== 9001) {
            throw new RuntimeException('MariaDB sighting fixture missing');
        }
        echo "PASS real MariaDB sighting fixture present\n";
        foreach (array(9001 => true, 9002 => false) as $attributeId => $hasHit) {
            $result = null;
            try {
                $result = $this->Sighting->getLastSightingForAttribute($user, $attributeId);
                if ($mode === 'original') {
                    throw new RuntimeException('Original unexpectedly returned '.json_encode($result));
                }
                if ($hasHit) {
                    if (!is_array($result) || (int)$result['Sighting']['id'] !== 9001) {
                        throw new RuntimeException('Fixed unexpected hit result '.json_encode($result));
                    }
                } elseif ($result !== array()) {
                    throw new RuntimeException('Fixed unexpected empty result '.json_encode($result));
                }
                echo "PASS fixed R1 mode attribute=$attributeId\n";
            } catch (TypeError $e) {
                if ($mode !== 'original' || !$hasHit) {
                    throw $e;
                }
                echo 'CONFIRMED original R1 TypeError in real CakePHP + MariaDB: '.$e->getMessage()."\n";
            } catch (ErrorException $e) {
                if ($mode !== 'original' || $hasHit || strpos($e->getMessage(), 'Undefined array key 0') === false) {
                    throw $e;
                }
                echo 'CONFIRMED original R1 empty-list ErrorException: '.$e->getMessage()."\n";
            }
        }
        if ($mode === 'fixed') {
            foreach (array(0,2,3) as $policy) {
                Configure::write('Plugin.Sightings_policy', $policy);
                $result = $this->Sighting->getLastSightingForAttribute($user, 9001);
                if (!is_array($result) || (int)$result['Sighting']['id'] !== 9001) {
                    throw new RuntimeException('Regression in policy '.$policy.': '.json_encode($result));
                }
                echo "PASS fixed other policy $policy\n";
            }
        }
        echo 'PASS R1 real CakePHP / MariaDB '.$mode."\n";
    }
}
