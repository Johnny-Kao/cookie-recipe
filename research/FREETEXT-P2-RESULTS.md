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

Latest expanded suite: [GitHub Actions #42570522121](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42570522121), both Redis 7 and Valkey 7.2 test jobs SUCCESS. **Green jobs mean the research experiments ran to completion, not that strict behavioral equivalence passed.** The concurrency experiment intentionally expects and reports a divergence. Earlier benchmark: #42569776805.

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

1. **Concurrent refresh behavior — CONFIRMED DIFFERENCE:** `research/freetext_concurrency_tests.php` uses an independent writer to mutate a per-Feed Redis set between reads. Baseline and candidate return different correlations. This is expected for commands queued before later execution, but **fails the user's requirement of absolute observed-behavior equivalence** pending a formal consistency contract.
2. **Cache/SQL consistency:** The Feed exists/visible lists are collected first. No production refresh consistency guarantee has been confirmed.
3. **Error behavior — CONFIRMED DIFFERENCE:** `research/freetext_error_tests.php` injects `exists`, second `sismember`, `pipeline`, and `exec` failures. Existing and new membership/exists exception paths align in this harness, but pipeline construction and pipeline execution introduce **new failure points** absent from baseline; the candidate throws while baseline returns. No production-safe recovery contract has been established.
4. **Full integration:** Must use true MISP CakePHP fixtures and all project tests before staging/upstream. Stubs cannot prove security invariants.
5. **RAM and scale:** 256 pending pairs plus Redis return data are bounded by batching. Verified empty inputs, mixed string/numeric keys, duplicate rows, existing correlation fields, batch boundaries 255/256/257/511/512/513/1025 and 1400-row stress on both engines. At 1400 rows, Redis 7: baseline 2254.8 ms / candidate 48.6 ms; peak incremental PHP memory 2,394,296 B vs 2,455,616 B; Valkey 7.2: 1285.4 ms vs 34.3 ms, same memory readings. Synthetic, isolated, single stress sample; larger sizes and lifecycle still untested.
6. **Maintainability:** ~30 lines of buffer/flush logic is more than the original nested loop; consider compact helper only if reused. Avoid making ACL-sensitive logic harder to audit. Preserve complete original output order.
7. **Reproducibility:** Measure more realistic distributions and CPU separately; beware short loopback timings and the synthetic 100% hit case.

**Verdict — HOLD:** P2 offers large synthetic latency reduction and static-array equivalence, but adversarial concurrent mutation and injected Redis failures demonstrate non-equivalence. Do not label this candidate behavior-preserving or ready for staging/upstream. Next decision must first establish whether MISP permits batched read-time consistency and a revised error contract; otherwise a lower-impact technique must be used. Full MISP integration suite has **not** run. Maintainer TODO belongs to P1, not P2.
