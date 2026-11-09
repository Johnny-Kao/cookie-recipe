# MISP reliability / invariant research — 2026-11-09

**Research-only scope.** No upstream, fork, staging, PR, issue, or comment actions. Pinned MISP revision: `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985`. Current default-branch `Sighting.php` blob also matches this pinned version (`27d6da3ed1a1ebe09b87add98c19ba3c07c35a11`).

## R1 — Sighting Reporter Policy return contract violated (confirmed)

**File:** `app/Model/Sighting.php::getLastSightingForAttribute()`.

The public method declares `: array`. Under `SIGHTING_POLICY_SIGHTING_REPORTER` (policy 1), it calls `listSightings()` and returns `$all_sightings[0]['Sighting']['date_sighting']` as a scalar. With a normal numeric timestamp, PHP raises `TypeError: Return value must be of type array, int returned`. With zero sightings, it indexes a missing array element and raises `Undefined array key 0`; under an error-to-exception handler this aborts the request. The actual caller `app/Model/DecayingModelsFormulas/Base.php::computeCurrentScore()` expects `$last_sighting['Sighting']['date_sighting']`, so merely changing the declared return type would not fix the contract.

**Call-chain confirmation:** `listSightings()` orders results newest first then applies the Reporter ACL filter, which may legitimately leave an empty array. `DecayingModelsFormulas/Base.php::computeCurrentScore()` reads the full `['Sighting']['date_sighting']` field, independently confirming the required return structure. Open performance PR #11075 batches some sighting reads but does not establish this specific contract defect is fixed.

**Source-derived GC reproduction:** [Run #42621958403](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42621958403) and [Run #42622204591](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42622204591), both PASS harness. The harness extracts the exact original public method into a fake CakePHP model, simulates Reporter hit/no-hit, and asserts the expected failure. It generates an isolated candidate returning `empty($all_sightings) ? [] : $all_sightings[0]`; candidate returns the correct array shape and preserves original behavior for policies 0, 2 and 3. **No upstream code modified.**

**Impact:** Decay score computation may terminate on a legitimate Reporter policy setting. **Unverified:** real CakePHP integration, complete ACL behavior and application error-handler configuration. No demonstrated remote unauthenticated exploit or CVE claim. Prior work search did not find a dedicated existing issue; nearby Sighting performance PRs exist, so recheck upstream before any proposed action.

## R2 — Sighting REST Search org filter uses undefined `$temp` (confirmed)

**File:** `app/Model/Sighting.php::restSearch()`, the `$filters['org_id']` block.

`$temp` is assigned only when `Validation::uuid($org_id)` is true **and** the organisation is found. But every entry appends `$temp` to a Sighting query condition, even for a numeric organisation ID, a numeric string, or a UUID without a matching organisation. This can produce `Undefined variable $temp`; depending on PHP error handling, it may abort or generate an incorrect SQL filter. Mixed entries may also reuse the previous iteration's `$temp`.

**Source-derived GC reproduction:** [Run #42622204591](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42622204591) PASS harness. Numeric `17`, numeric string `'17'`, and unknown UUID each produce the undefined variable warning (converted to `ErrorException` in the isolated harness). Known UUID resolves to organisation ID 17. This reproduces a defective branch; full `restSearch()` integration and input API contract are not yet validated.

**Second-pass confirmation:** [GC #42627071428](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42627071428) PASS. With PHP warnings left nonfatal, a mixed filter containing a known UUID (organisation 17) followed by an unknown UUID yields `['Sighting.org_id' => [17, 17]]` with **no warning**; known UUID followed by numeric 18 does the same. A known UUID followed by a negated unknown UUID yields **both** `Sighting.org_id => [17]` and `Sighting.org_id NOT IN => [17]`, again no warning: internally contradictory conditions. An unknown UUID on the first iteration yields a warning and `null` in the conditions. These are exact original source-derived filter-block outputs, not full SQL/ACL integration results.

**Impact:** organisation-filter searches may fail or silently select wrong or zero results; **do not claim data leakage without an integration / ACL test**. Related historical [Issue #9880](https://github.com/MISP/MISP/issues/9880) discusses reversed negation behavior in this same endpoint (closed in 2024), but it does not itself validate the present uninitialized-local problem. Before preparing a candidate patch, establish the supported numeric-ID/unknown UUID semantics from request documentation and adjacent REST search implementations. Resetting `$temp` alone is insufficient to define correct filtering.

## Full-class validation using the unchanged MISP source

[GC #42629590896](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42629590896) loaded the full original `Sighting` PHP class with framework/service doubles. Its unmodified `listSightings()`, `getLastSightingForAttribute()` and `restSearch()` actually ran (unlike the earlier extracted-method harness). R1 produced `TypeError` for a visible Reporter hit; an ACL-hidden row and an empty list both generated `Undefined array key 0`. R2 reached `find('column')` with the stale or contradictory real condition arrays, and an unknown UUID or integer ID produced `Undefined variable $temp`.

[GC #42630110237](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42630110237) also generated a **full-source, one-method R1 candidate** changing the Reporter return to `empty($all_sightings) ? [] : $all_sightings[0]`. Tests PASS for visible, ACL-hidden and empty sightings, and policies 0, 2 and 3 return exactly the same values as unmodified MISP. The original failures remain reproduced in the same run. Candidate is **research-only**, with no upstream patch.

**R2 intended input semantics cross-check:** `RestSearchComponent::$paramArray['Sighting']` permits `org_id`. The adjacent `SightingsController::listSightings()` uses `Toolbox::findIdByUuid()`, which explicitly supports UUID and numeric ID (and throws `NotFoundException` when the lookup fails). That is strong codebase evidence that numeric organisation IDs are legitimate, although the exact error contract for `Sighting::restSearch()` still needs to be agreed. Negation and mixed list semantics must be preserved. Directly replacing `$temp` with `null` would not be a safe fix.

**Limit:** Real methods and ACL branches execute, but CakePHP, actual database queries, auth, exporters and production error handling use fakes. These results confirm source-level runtime defects, not a deployed end-to-end test or security impact.

## R1 real CakePHP + MariaDB integration — PASS

**Final GC:** [#42634625234](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42634625234), completed SUCCESS, using upstream MISP commit `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985`, PHP 8.3, actual CakePHP shell, real MariaDB 10.11 with MISP SQL schema, actual MISP `Sighting`, `MispAttribute` and `Event` models and `DecayingModelBase::computeCurrentScore()`. Full working copy exists **only in ephemeral GH Actions runner**; no modifications to Upstream, Fork or Staging.

- Original source: one real Sighting yields `TypeError: array, int returned`; no Sighting yields CakePHP warnings followed by `TypeError: array, null returned`. Original full `computeCurrentScore()` caller also reproduces both failures.
- One-branch candidate: `return empty($all_sightings) ? [] : $all_sightings[0];`. Actual CakePHP+DB both Sighting hit and no-hit PASS; actual `computeCurrentScore()` score for both cases PASS (no-hit correctly falls back to Attribute timestamp).
- Regression: Sighting Policy 0, 2 and 3 PASS.
- Actual upstream PHP lint: **2,193 files PASS**.
- Actual upstream PHPUnit suite after matching its GPG fixture setup: **945 tests; 3,114 assertions; 2 skipped; 0 failures; 0 errors**.

Earlier GC [#42634112178](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42634112178) had two GPG unit test environment errors due to missing test GPG setup, not R1. The final successful run fixed only test-runner GPG configuration, without changing the R1 patch.

**Gate:** R1 is research-validated and technically suitable for independent PR staging. For an upstream-submittable patch, put the permanent regression into the project's accepted test layout, confirm clean staging diff and follow the project PR conventions. This research did not create a staging branch or an upstream PR. PHP 8.1 matrix has not yet been rerun; current real DB gate is PHP 8.3.

## 2026-11-09 decision update — R1 PARK, R2 active

**R1 PARK by owner decision.** R1 has verified correctness defect and successful CakePHP/DB integration, but its scope/impact does not justify further upstream work now. Do not prepare R1 staging/PR unless the decision changes.

### R2: real REST search and MariaDB data loss from results — reproducible

**Evidence:** [GC #42639654918](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42639654918) PASS. It runs the unchanged pinned MISP `Sighting::restSearch()` through **real CakePHP / MariaDB 10.11** with two actual organisations, events, attributes, and sightings; site-admin user and everyone sightings policy intentionally isolate query semantics from ACL. The test reads the returned **real REST JSON**, not just constructed SQL conditions. Assertions fix observed results and fail if the behavior differs.

| `org_id` filter | Real returned Sighting IDs | Expected from valid identifiers / negation | Result |
|---|---|---|---|
| org A UUID | 9101 | 9101 | correct |
| org B UUID | 9102 | 9102 | correct |
| [A UUID, B UUID] | 9101, 9102 | 9101, 9102 | correct |
| **integer 9101** | **empty + undefined-var warning** | **9101** | **incorrect; documented API input** |
| **numeric string "9102"** | **empty + undefined-var warning** | **9102** | **incorrect** |
| [A UUID, integer 9102] | **9101 only; no warning** | 9101, 9102 | **silent omission** |
| [A UUID, !unknown UUID] | **empty; no warning** | 9101 (unknown org should not exclude A) | **silent false negative** |
| !A UUID | 9102 | 9102 | correct |
| [A UUID, !B UUID] | 9101 | 9101 | correct |
| unknown UUID only | empty + warning | policy on invalid org is undefined | do not infer severity |

**Official input contract:** MISP `docs/API_Doc.md`, the `/sightings/restSearch/[context]` endpoint, explicitly declares `org_id | integer` under optional parameters: https://github.com/MISP/MISP/blob/2.5/docs/API_Doc.md . This directly establishes that numeric org_id is an intended input, not merely an adjacent-API inference. The `RestSearchComponent::$paramArray['Sighting']` also permits `org_id`; `ToolboxComponent::findIdByUuid()` used elsewhere accepts both numeric org IDs and UUIDs. **Important:** the docs only explicitly say integer, not that every mixed-format array or negation is guaranteed public contract; those extended cases are secondary evidence.

**Impact:** Documented legitimate API request returns an incorrect empty result even though the Sighting exists. Mixed valid inputs can silently suppress results, while an invalid negated org UUID can suppress all results. This is a genuine user-observable correctness defect of a REST endpoint, not demonstrated data corruption, ACL leak, security vulnerability, or widespread operational outage. Severity likely moderate for users depending on organisation-filtered Sighting exports; prevalence unknown.

**Research-only verdict:** **R2 should proceed to a narrowly scoped fix/DB regression candidate**. Define numeric-ID vs UUID resolution and invalid/negative semantics before coding. Favor project-local parsing patterns, preserve existing valid UUID and negation behavior, and do not claim `Upstream Ready` until patched integration and standard upstream PHP tests pass. No upstream, staging or PR changes have been made.

## R2 research-only minimal fix — real CakePHP/DB + upstream suite PASS

**Final CI:** [GC #42641659165](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42641659165), SUCCESS, MISP pinned commit `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985`, PHP 8.3, real CakePHP, MariaDB 10.11. Research candidate `research/apply_r2_candidate.py` modifies only the `org_id` clause of `Sighting::restSearch()` in the **ephemeral runner checkout**; no fork/upstream/staging edits. Test shell `research/R2DbResearchShell.php` asserts original and fixed **actual REST JSON** outputs, including two DB-backed organisations and a non-admin ACL control.

- Existing code only resolves UUIDs into `$temp`; numerical IDs leave `$temp` undefined. Subsequent invalid entries silently reuse stale `$temp`.
- Candidate uses existing PHP `ctype_digit` / cast and MISP `Validation::uuid()` + existing `Organisation->find('first')`. It initializes the temporary ID on every iteration. Unknown positive IDs become the impossible `-1` condition (no accidental widening); unknown negative IDs are ignored rather than excluding unrelated data. No new helper, model, API, DB column, query engine or ACL code. Original UUID and supported `!` filtering semantics remain.
- Original `org_id=9101` returns empty with warning; patched returns `[9101]`. Original `[A UUID,9102]` silently omits B; patched returns `[9101,9102]`. Original `[A UUID,!unknown UUID]` silently empties; patched returns `[9101]`. Numeric string, negated numeric, unknown-only and negated-unknown-only checked, plus valid UUID/negation control cases.
- ACL control: a non-admin user from org 9102 cannot see org A's private event for either UUID or numeric filters; old and candidate both return empty, so no **observed** ACL regression in this fixture. This is a focused coverage check, not an exhaustive ACL security audit.
- Upstream PHP lint: **2,193 files PASS**. Upstream PHPUnit: **945 tests, 3,114 assertions, 2 skipped, 0 errors or failures**. Other upstream workflows/languages, PHP 8.1, end-to-end HTTP request/authentication, and broader permissions matrix not rerun.
- Operational severity: real API correctness failure for a **documented normal integer filter**, some silent false-negatives; likely **moderate**, not proven general outage or security issue. Distinguish from R1, which is parked.
- Related **[Issue #9880](https://github.com/MISP/MISP/issues/9880)** (closed) and **[PR #9881](https://github.com/MISP/MISP/pull/9881)** (merged 2024-08-26) corrected reversed *positive/negation* logic by swapping condition branches; they did NOT fix numeric IDs, undefined/stale temporary or unknown-ID handling. No directly duplicating open issue/PR found in targeted current search. Historical **[Issue #4992](https://github.com/MISP/MISP/issues/4992)** also addresses UUID lookup in another context; not this issue. The unchanged current Sighting.php blob is `27d6da3ed1a1ebe09b87add98c19ba3c07c35a11`.
- **PR readiness:** suitable for independent PR staging, **subject to confirming invalid/unknown ID behavior with maintainer expectations, deciding whether to add a permanent regression test in the project's accepted test structure, and doing normal staging review**. No claim of complete production ACL/HTTP matrix. The candidate changes **observable behavior deliberately** to restore documented semantics; does not affect other MISP features.

## Priorities / next gates

1. **R1 PARK.** No more work requested despite previously validated CakePHP/DB candidate.
2. **R2 active: project-style candidate and real CakePHP/MariaDB / ACL focused validation / upstream PHP suite PASS.** Next: settle unknown-ID semantics and permanent upstream regression layout in staging prior to submitting an independent PR.
3. Search further for runtime failure/invariant issues at state transitions, worker retries, import boundaries, and normal error paths. Do not return to performance optimization without new instruction.

Early isolated GC harnesses prove source-level defects only. Subsequent R1 and R2 CI runs separately establish real CakePHP/MariaDB results and passing upstream PHP lint/PHPUnit; neither replaces the full multi-version MISP CI and production end-to-end security matrix.
