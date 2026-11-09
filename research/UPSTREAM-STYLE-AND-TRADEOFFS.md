# Freetext P2 — upstream patterns and total-cost review

Date: 2026-11-09. Independent research only. MISP upstream/staging unchanged.
Source pinned to MISP/MISP commit 04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985.

## Real request shape

FeedsController::__previewFreetext() normally slices to 60 data rows **before** Feed::getFreetextFeedCorrelations(). A special page value 'all' skips the 60-row slice. Previously reported 300/1400-row cases are therefore stress tests, not standard previews. REST output also consumes these correlation results.

## Precedents already in MISP

- Model/User.php — attachIsUserMonitored(): RedisTool::init(), Redis pipeline, pipelined SISMEMBER over users, exec(), ordered output mapped back. This is the closest direct read-path precedent.
- Lib/Tools/RedisTool.php — sizeByPrefix(): SCAN 1000 keys then Pipeline commands per chunk. Not a general batched set-membership API.
- Model/Correlation.php — generateTopCorrelations(): pages DB data, Pipeline for each page. Explicitly disables DB callbacks to avoid memory leaks.
- Model/Warninglist.php — attachWarninglistToAttributes(): Redis unavailable -> domain-specific uncached fallback; write cache uses Pipeline. Fault handling is domain-specific.
- Model/Feed.php — attachFeedCorrelations(): Redis Pipeline for another feed-correlation read path; separate UUID lookup logic.
- Existing Feed::setupRedis() already handles this method's connection; do not replace with another helper purely for style.

## Typical 60-row performance (synthetic, extracted exact method)

GitHub Actions https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42579089157
Both Redis 7 / Valkey 7.2 jobs SUCCESS; five-repetition median, 60 rows / 15 Feed candidates / 30% synthetic hit rate. Exclusion, missing cache keys leave 780 membership commands for each approach.

| Engine | Sequential original | Per-row Pipeline | Cross-row 256 batch |
| --- | ---: | ---: | ---: |
| Redis 7 | 91.866 ms | 10.409 ms | 4.016 ms |
| Valkey 7.2 | 92.445 ms | 9.840 ms | 3.744 ms |

These are different benchmark sequences, not a paired statistical confidence interval. No independent CPU profile or whole-request timing.

Peak incremental PHP RAM in both environments:
- Sequential 109,736 B.
- Per-row Pipeline 113,376 B (+3,640 B).
- Cross-row batch 191,288 B (+81,552 B).

Per-row uses 60 Pipeline executions; cross-row uses 4. The faster path isn't necessarily cheaper to maintain; sequential calls are not intrinsically faster for network I/O. No approach automatically guarantees a Redis call cannot hang; liveness depends on timeouts and failure handling.

## Behavioral / maintenance gate

- Keep Feed visibility filtering, user-scoped SQL, original ordering, key conventions, and current SISMEMBER commands.
- If using Pipeline, follow User.php idiom; avoid custom cached lookup layer and unnecessary RedisTool abstraction.
- **Both Pipeline variants differ from sequential baseline under deterministic concurrent cache writes.** They also add pipeline/exec exception paths. Existing static fixture tests passing does not prove full behavior equivalence.
- If strict equivalence is nonnegotiable, keep the original query order and make only safe local optimizations (e.g. MD5 once per Attribute), accepting lower performance gain.
- Further work requires actual application route integration, Redis failure policy, versioned consistency semantics, CPU measurement and realistic workload metrics. A fixed 60-row default changes the likely production payoff vs large stress benchmarks.

Decision: no upstream/staging patch yet. Optimize total performance, memory, failure semantics and maintenance cost, not headline speed alone.
