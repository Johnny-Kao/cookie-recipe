# Batched Membership Evaluation — maintainability gate

2026-11-09. Research only; MISP upstream untouched.

## Implementation scope

Candidate is independently generated from pinned MISP/MISP `app/Model/Feed.php` commit `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985`:

- Global `SISMEMBER` pipeline over the ordered part occurrences -> one `sMIsMember(prefix + combined, ...hashTable)` call.
- Per Feed/Server `SISMEMBER` pipeline -> one `sMIsMember(prefix + sourceId, ...sourceHashes)` call.
- Preserve occurrence ordering, repeated hashes, hit count, UUID `SMEMBERS`, source filtering, source order and ACL. No long-lived cache or new Redis keys.
- **PhpRedis native API is variadic:** `sMIsMember($key, ...$members)`. Supplying an array as the second argument led to a full-function regression failure; discovered in Actions #42567704526 and corrected. Prefer the native method; no custom protocol wrapper.

## Results

Run: https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42568122759

Two independent GitHub Actions jobs: Redis 7-alpine and **Valkey 7.2**, both PASS.
- All fixed-method regression cases, 100 deterministic randomized cases, Feed/Server permission/cutoff branches: same Attributes and Event `===` in the harness.
- Actual MISP method body extracted and run with mock CakePHP source lookup and real Redis/Valkey (not entire application).
- `SMEMBERS` UUID lookups remain unchanged, hence no cross-source UUID-set staleness is introduced by this candidate.
- Grouping membership commands **does change time-of-read atomicity** compared with a pipeline of multiple SISMEMBER calls. Strict equality for arbitrary interleaved writes is **not proven** and likely impossible to promise without a snapshot contract.

Example single-run data on Redis 7:
| Hashes | Feed sources | Membership commands baseline -> candidate | UUID SMEMBERS calls | Full-method baseline -> candidate (ms) |
| ---: | ---: | ---: | ---: | ---: |
| 30 | 3 | 120 -> 4 | 90 unchanged | 1.094 -> 0.955 |
| 100 | 5 | 600 -> 6 | 500 unchanged | 3.850 -> 3.366 |
| 200 | 10 | 2200 -> 11 | 2000 unchanged | 17.573 -> 15.355 |

Timing figures are one sample, not a statistically reliable speedup. These fixtures intentionally hit every Feed; production hit-rate distribution may be different.

## Compatibility and maintenance risks

1. Redis `SMISMEMBER` is available from **Redis 6.2**, not Redis 6.0 or older. Official MISP Docker Compose currently uses `valkey/valkey:7.2`, which passes in this lab. An enforced MISP 2.5-wide minimum Redis version >=6.2 could not be verified. Do NOT claim universally supported.
2. Native PhpRedis binding must be present and support the variadic method. Project-wide minimum PhpRedis version is not established.
3. Avoid an automatic dual-path compatibility framework with runtime version detection unless MISP maintainers confirm old servers are supported. Such a branch would increase testing and maintenance cost.
4. Large part lists produce large variadic calls; set a bounded chunk size (e.g. 512–2048 entries) **only if** real big-input testing justifies it. This adds code and could introduce processing/order complexity. Ensure 10k cutoff uses occurrence count, not unique hashes.
5. Updates during a pipelined membership scan can be observed differently from a single atomic batch. Need real writer/reader interleaving tests and maintainer interpretation of the cache consistency contract before using strict 'behavior-preserving' wording.
6. UUID lookups dominate high-hit synthetic runs; membership batching alone does not eliminate that cost. Never quote command-reduction percentages as end-to-end wins.
7. Need real application integration tests (CakePHP models/Redis instances, warnings, errors, timeouts, memory limit, test suite) before staging/upstream PR.

## Gate decision

**Keep the SMISMEMBER design as a promising candidate, but no upstream patch yet.** Favor two minimal replacements and native PhpRedis, avoiding custom caches. Do not alter existing Redis UUID representation or ACL. Before proposing: (a) confirm supported Redis/PhpRedis minimum, (b) concurrency-read contract, (c) repeated realistic benchmark, (d) full MISP integration regression. If minimum Redis <6.2 must be supported or strict per-command intermediate-state observation is contractual, reject unconditional batching.
