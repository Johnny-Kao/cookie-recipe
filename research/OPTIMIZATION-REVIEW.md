# Optimization review — 2026-11-09

Research repository: https://github.com/Johnny-Kao/cookie-recipe (public, independent, not a fork).

Upstream read-only baseline: MISP/MISP `app/Model/Feed.php`, commit `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985`, blob `1f6a9d8ab8b197a366e549333736a321348d8ed7`.

## Hypothesis and candidate

Inside `Feed::attachFeedCorrelations()`, one global `event_uuid_lookup:<hash>` Redis set can be read once for each matching source and for repeated attribute hashes. The isolated candidate changes only this inner lookup: collect source-specific hash hits in their existing order, fetch uncached unique hashes through the existing pipeline API, and rebuild the result sequence in its original order from a per-call hash lookup cache. No cross-request persistent cache is used.

This is a **research candidate**, not an upstream patch.

## Verified

Run: https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42553378536

- Exact upstream function extracted and dynamically compiled side by side with the candidate.
- Same synthetic Redis instance, same fixture, deep `===` comparison of full returned Attributes and modified Event.
- Nine regression cases: multiple source matches, duplicates, permission denied, no correlatable attributes, no cached match, empty input, limit override, Server admin/non-admin, 10k hit cutoff.
- Redis 7 with PHP Redis on GitHub Actions; no changes to upstream.
- Extracted function measurements, **one sample per case**:

| Hashes | Matching sources | Baseline SMEMBERS | Candidate SMEMBERS | Baseline ms | Candidate ms | Baseline peak increment (B) | Candidate peak increment (B) |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 30 | 3 | 90 | 30 | 1.082 | 0.878 | 111224 | 113952 |
| 100 | 5 | 500 | 100 | 3.710 | 2.870 | 502384 | 514608 |
| 200 | 10 | 2000 | 200 | 16.571 | 12.231 | 1969840 | 1901864 |

These figures do **not** establish production or statistically significant performance benefits.

## Unresolved correctness and deployment risks

1. **Concurrent Redis writes:** The original repeated `SMEMBERS` queries can observe updated sets between sources. An in-request cache can return older contents for the later source. If strict temporal consistency with baseline is required, this is a contract change. Existing cache refresh paths write/delete UUID lookup sets and must be audited.
2. **Memory:** The candidate retains every previously seen UUID set for the remainder of this function call. When many hashes or large sets exist, PHP memory cost can grow. No safe bounded memory policy or representative worst-case fixture has been validated yet.
3. **Harness limits:** This is the exact target function but in a minimal host harness with synthetic stubs for CakePHP models, source retrieval and role inputs. It is not a full MISP application test, nor an exhaustive check of all input permutations.
4. **Order:** The function's iteration order is retained and checked for static fixtures, but concurrency and Redis set update behavior are not covered.
5. **Benchmark:** One run per dimension and many synthetic all-match cases bias results toward a cache win. Real-world hit distributions and repeated measurements remain necessary.

## Decision

**HOLD** on upstream PR. The isolated optimization is behavior-equivalent on covered static cases and promising on limited performance measurements. Next gates: audit cache refresh lifecycle, design memory control, test concurrent writers, add randomized/realistic hit distributions and full MISP integration regression. An enhancement must not silently change visible results or ACLs.
