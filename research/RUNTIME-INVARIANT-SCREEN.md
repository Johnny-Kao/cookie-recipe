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

## Priorities / next gates

1. **R1 high confidence, low repair cost.** Verify the exact extracted candidate using application-level `computeCurrentScore()` and standard unit test harness, both hit/no-hit and ACL outcomes. Candidate is research-only.
2. **R2 high confidence as code-level warning, conditional severity.** Confirm intended org filter representations and design a safe unknown-UUID rejection/match-none rule without changing permissions or negation semantics.
3. Search further for runtime failure/invariant issues at state transitions, worker retries, import boundaries, and normal error paths. Do not return to performance optimization without new instruction.

The GC assertions intentionally reproduce failures for original code and verify contracts on isolated source-derived copies. A successful harness is **not** evidence that full MISP integration tests pass.
