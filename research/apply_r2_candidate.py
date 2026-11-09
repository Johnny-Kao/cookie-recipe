#!/usr/bin/env python3
"""Research-only patch applied to a temporary MISP checkout in GitHub Actions."""
from pathlib import Path

p = Path("app/Model/Sighting.php")
source = p.read_text()
start = source.index("        if (isset($filters['org_id'])) {", source.index("public function restSearch("))
end = source.index("\n        if (isset($filters['source'])) {", start)
old = source[start:end]
assert "Sighting.org_id NOT IN" in old and "$temp = $org['Organisation']['id']" in old
replacement = """        if (isset($filters['org_id'])) {
            if (!is_array($filters['org_id'])) {
                $filters['org_id'] = array($filters['org_id']);
            }
            foreach ($filters['org_id'] as $org_id) {
                $negation = false;
                if (is_string($org_id) && isset($org_id[0]) && $org_id[0] === '!') {
                    $negation = true;
                    $org_id = substr($org_id, 1);
                }
                $temp = null;
                if (is_scalar($org_id) && ctype_digit((string)$org_id)) {
                    $temp = (int)$org_id;
                } elseif (Validation::uuid($org_id)) {
                    $org = $this->Organisation->find('first', array(
                        'conditions' => array('Organisation.uuid' => $org_id),
                        'recursive' => -1,
                        'fields' => array('Organisation.id'),
                    ));
                    if (!empty($org)) {
                        $temp = $org['Organisation']['id'];
                    }
                }
                if ($temp === null) {
                    if (!$negation) {
                        // An unknown positive organisation must not broaden the search.
                        $conditions['Sighting.org_id'][] = -1;
                    }
                    continue;
                }
                if ($negation) {
                    $conditions['Sighting.org_id NOT IN'][] = $temp;
                } else {
                    $conditions['Sighting.org_id'][] = $temp;
                }
            }
        }
"""
p.write_text(source[:start] + replacement + source[end:])
print("Research-only patch: org_id filter block replaced, other source unchanged.")
