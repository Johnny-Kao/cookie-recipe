# MISP Feed correlation — strict-preservation optimization review

2026-11-09. Independent research at https://github.com/Johnny-Kao/cookie-recipe. No upstream write, issue or PR.

## Current candidate: PHP-side search only

**The previous cross-source UUID Redis cache candidate is rejected for strict behavior preservation.** It skipped repeated `SMEMBERS` calls and could observe a different UUID result if the global Redis set changed between source reads. This divergence was experimentally reproduced in [run #42553649025](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42553649025).

The current candidate makes **one localized change** to `Feed::attachFeedCorrelations()`:
- Original: allocate `array_column($attributes[$attributePosition][$scope], 'id')` and use non-strict `in_array()` to detect an already-attached source.
- Candidate: iterate existing source entries and stop at the first loosely equal ID, eliminating intermediate array allocation.
- Redis read/write calls, their order, pipeline boundaries, input processing, source/UUID traversal and output-attachment logic remain unchanged.
- The target upstream method is extracted from pinned `MISP/MISP` commit `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985`, file blob `1f6a9d8ab8b197a366e549333736a321348d8ed7`.

## Tests and evidence

Latest PASS: [GitHub Actions #42564498074](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42564498074).

- Target method extracted from upstream and executed both ways against real Redis 7.
- Exact `===` comparison of returned Attribute arrays and modified Event, with source and UUID ordering retained.
- Deterministic regression cases: duplicate attributes, URL stripping, composite values, already-attached sources, numeric-string IDs, null IDs, missing IDs, empty data, disabled correlation, permission restrictions, Feed and Server scopes, 10k hit cutoff.
- 100 deterministic randomized Feed fixtures tested.
- Explicit mid-query Redis mutation injection; original and candidate return the same outputs **and issue the same Redis operations in the same sequence**. Injected UUID is visible to the later source.
- No full MISP/CakePHP integration suite has been run.

### Performance

9 repetitions, alternating baseline/candidate execution, real Redis, synthetic scenarios designed to emphasize large pre-existing source lists and early matches:

| Attributes | Previously attached sources per attribute | Baseline median ms | Candidate median ms | Improvement |
|---:|---:|---:|---:|---:|
| 100 | 20 | 1.388 | 1.351 | 2.7% |
| 250 | 80 | 3.234 | 2.787 | 13.8% |
| 400 | 120 | 4.015 | 3.675 | 8.5% |

A single separate typical-sized full-method measurement was approximately neutral. The improvement is CPU-side and workload-dependent. **Do not reuse the earlier 80–96% Redis-command reductions or 19–26% timings** as results for this candidate: those refer to the *rejected* caching experiment.

## Remaining gates before staging/upstream

1. Run true MISP integration / project tests: actual models, permissions, real feed/server source shapes, other callers, PHP versions, no warnings.
2. Test malformed/non-standard embedded source entries (including objects) if supported by the public API; current fast path is designed for MISP's nested array data model.
3. Determine whether the measured CPU improvement is meaningful in realistic MISP workloads; small hit lists may show negligible benefit.
4. Consider whether an even narrower change or no change is preferable given maintenance costs.

**Status: isolated candidate passed covered behavior equivalence, including concurrent mutation. NOT yet validated by full MISP tests; NOT ready for upstream PR.**
