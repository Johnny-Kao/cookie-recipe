# Freetext P2: per-attribute Pipeline alternative

2026-11-09. Upstream MISP remains unchanged; independent lab `Johnny-Kao/cookie-recipe`.

## What was tested

A more maintainable approach than batching 256 membership checks across multiple attributes:

- Preserve all existing Feed discovery, Cache visibility filters, ACL-scoped SQL lookup, event correlations, and output ordering.
- For **each input Attribute row**, compute MD5 once; issue the same `SISMEMBER` checks for only that row's eligible Feeds in a single Redis Pipeline; execute it **before** continuing to the next row.
- Avoid cross-row and cross-request cache, UUID memoization, new Redis keys, additional server requirements, and custom shared state.
- The only buffered values are results from one row's Feeds; memory overhead scales with the count of active Feeds, not all Attributes × Feeds.

## Evidence

[GitHub Actions run #42578352005](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42578352005): Redis 7 and Valkey 7.2 test jobs SUCCESS.

The candidate method is built from the exact pinned upstream `getFreetextFeedCorrelations()` method. Both run against actual ephemeral Redis/Valkey, with mocked CakePHP source and ACL queries.

Static equivalence cases, admin/non-admin filtering, existing fields, mixed keys, duplicates, empty values, 256-position boundary lengths and 1,400-row stress cases produced identical return arrays. The tests also demonstrate the difference for adversarial concurrent Cache updates and new error paths when Pipeline/EXEC fails; those are **behavioral divergences**, not successful strict-equivalence proofs.

Redis 7 median synthetic results:
| Data rows | Feed candidates | Direct ms | Per-row Pipeline ms | Speedup | Extra peak PHP memory |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 100 | 15 | 89.749 | 9.683 | 9.3x | 3,424 B |
| 300 | 25 | 452.224 | 34.588 | 13.1x | 544 B |
| 1400 | 16, fewer eligible | 1,352.7 | 126.2 | 10.7x | 3,208 B |

Actual number of eligible Feeds differs due to exclusion and cache existence. Redis membership command count unchanged, but Pipeline executions are per row (e.g. 100 at 100 rows). No independent CPU profiling. Figures do not represent whole MISP request latency.

## Decision and consistency contract

This row-scoped approach is preferable in maintainability and memory to a multi-row buffer, and avoids a new persistent Cache. It **still changes temporal read semantics**: in a deterministic injected writer test, the original sequential reads and queued Pipeline produced different results. Likewise, Pipeline and EXEC add failure points absent from the original code. An optimization cannot both eliminate Redis round trips and guarantee the same answers for *every* adversarial concurrent mutation time unless the writer/reader consistency contract changes.

Do not claim strict behavioral equivalence. Before upstream: confirm the Feed Cache is explicitly eventual-consistent or define acceptable read-time semantics, review error handling and fallback behavior, and run MISP integration tests. If the product requirement really is strict equivalence under arbitrary concurrent writes and Redis faults, keep the original sequential query implementation (and only make micro-optimizations that preserve the exact command order), or address Cache versioning/snapshots as a separate architectural effort.

**Status: strong performance and bounded memory; ordinary output PASS; strict concurrency/error equivalence FAIL; upstream PR HOLD.**
