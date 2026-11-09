# P2: Freetext Feed correlation bounded pipeline research

2026-11-09. Independent public research `Johnny-Kao/cookie-recipe`; no MISP upstream changes.

## Maintainer TODO attribution

The comment **"TODO: This can be optimised in future to do that in one pass"** is inside `Feed::attachFeedCorrelations()`, immediately before the MISP/Server `event_uuid_lookup` processing, **not** in `getFreetextFeedCorrelations()`. The P2 target does not contain that TODO. Source: MISP/MISP `app/Model/Feed.php`, pinned commit `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985` (blob `1f6a9d8ab8b197a366e549333736a321348d8ed7`).

## What is slow

`getFreetextFeedCorrelations()` loops over each returned attribute and each visible cached Feed. For each pair, it computes `md5($value['value'])` and executes **a separate synchronous** Redis `SISMEMBER`. The initial DB query `fetchAttributesSimple($user,...)` is permission-scoped; Feed candidates are restricted using `visibleConditions($user)`. Neither SQL/ACL branch may be reordered or weakened.

## Current research candidate (not production patch)

- Retains original Feed discovery, SQL query, ACL and correlation processing, original source and attribute ordering.
- Computes MD5 **once per data row** rather than once per candidate Feed.
- Enqueues the same `SISMEMBER` keys and members in the original traversal order.
- Executes bounded 256-command Redis Pipelines and attaches matching results by the original `[dataKey, feedKey]` positions.
- No Redis 6.2 dependency, persistent cache, new Redis keys, SQL change or new visibility rules.
- **Membership Redis command count does not change**; round trips decrease considerably. Batch boundaries are a maintenance/runtime tradeoff.

## Validation

[GitHub Actions #42569776805](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42569776805), Redis 7 and Valkey 7.2 jobs PASS, after fixing a **test fixture error** and ensuring `pipefail` propagates PHP failures.

Both classes are extracted directly from the pinned actual MISP method and execute against ephemeral real Redis/Valkey, with stubbed CakePHP `find`, `ClassRegistry`, `visibleConditions`, and `fetchAttributesSimple` to simulate permitted feeds / DB results. The harness compares exact returned arrays `===`, including attribute keys, source names, and cross-event correlations. It covers admin vs non-admin visibility, zero/high/partial hit ratios, duplicate values and string keys. **This is not full MISP integration, nor proof of all ACL paths**.

Reported median of 3–5 sequential repeated runs, excerpt:
| Datastore | Scenario | Original ms | Candidate ms | Membership commands both | Pipeline execs candidate | PHP peak incremental bytes baseline/candidate |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Redis 7 | 100 attrs / 15 feeds, 30% hits | 155.500 | 5.337 | 1,300 | 6 | 191,320 / 267,800 |
| Redis 7 | 300 attrs / 25 feeds, 100% hits | 781.035 | 19.435 | 6,600 | 26 | 1,811,992 / 1,871,536 |
| Redis 7 | 100 attrs / 15 feeds, simulated 300µs per request | 642.121 | 12.714 | 1,300 | 6 | 191,320 / 267,800 |
| Valkey 7.2 | 100 attrs / 15 feeds, 30% hits | 95.277 | 3.980 | 1,300 | 6 | 191,320 / 267,800 |
| Valkey 7.2 | 300 attrs / 25 feeds, 100% hits | 484.712 | 14.237 | 6,600 | 26 | 1,811,992 / 1,871,536 |

**Caveats:** artificial fixtures and local/container loopback; injected latency simulated in the PHP proxy, not live network; no independent CPU profiling; extra memory values depend on PHP allocator and peak measurement approach. In these cases, candidate saved substantial wall time because it removed synchronous per-Feed round trips, not because Redis processes fewer SISMEMBER operations.

## Remaining problems / gating criteria

1. **Concurrent refresh behavior:** pipelining a batch collapses read timing. If a writer mutates a per-Feed Redis set while processing, the exact observation timing differs versus sequential reads. This was not tested for P2, and strict equality for arbitrary writers is not guaranteed. Feed Cache is inherently mutable.
2. **Cache/SQL consistency:** The Feed exists/visible lists are collected first. No production refresh consistency guarantee has been confirmed.
3. **Error behavior:** A Redis error mid-request occurs at a different stage with pipeline; partial results / exceptions may differ. Test this and identify expected contract.
4. **Full integration:** Must use true MISP CakePHP fixtures and all project tests before staging/upstream. Stubs cannot prove security invariants.
5. **RAM and scale:** 256 pending pairs plus Redis return data appears bounded, but large lines, unusual keys and very large Feed candidate lists need stress testing and batch-size review.
6. **Maintainability:** ~30 lines of buffer/flush logic is more than the original nested loop; consider compact helper only if reused. Avoid making ACL-sensitive logic harder to audit. Preserve complete original output order.
7. **Reproducibility:** Measure more realistic distributions and CPU separately; beware short loopback timings and the synthetic 100% hit case.

**Verdict:** P2 is a stronger performance opportunity than the earlier P1 SMISMEMBER change. Research candidate PASS on listed static fixtures. Not production-validated; do not open upstream PR yet. TODO belongs to P1, not P2.
