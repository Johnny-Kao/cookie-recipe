# P2 local Cache validation — decision

2026-11-09. Independent research only; MISP upstream/staging not modified.

GC: https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42590415252 (Redis 7 and Valkey 7.2 SUCCESS).

## Question

Can request-local caching reduce repeated Redis membership reads inside Feed::getFreetextFeedCorrelations(), without making correctness or maintenance worse?

Two research candidates were extracted from pinned MISP app/Model/Feed.php:
1. MD5 once per row (no Redis read cache, same SISMEMBER calls/order).
2. Request-local memoization of exact raw Attribute value × Feed position membership; no new Redis keys or global state. This deliberately tests the semantic risk of caching lookup results.

Both compared against the exact extracted upstream method, real Redis/Valkey and stubbed CakePHP Feed selection / SQL ACL rows. Existing static output, edge, stress and deterministic concurrent mutation tests run for both variants. The harness asserts expected stale behavior for the memo candidate and identical output for MD5-only.

## Typical 60-row / 15-feed synthetic benchmark

The 15 candidates include excluded/current/missing sources, so there are 780 membership calls at baseline. Five-run medians; timings fluctuate across Actions runs and must not be treated as whole-page latency.

| Scenario | Redis 7 baseline / candidate (ms) | Redis 7 membership calls | Valkey 7.2 baseline / candidate (ms) | Valkey 7.2 membership calls |
| --- | --- | --- | --- | --- |
| 70% unique, MD5-only | 59.337 / 56.428 | 780 / 780 | 45.234 / 44.302 | 780 / 780 |
| 70% unique, memo | 57.586 / 42.199 | 780 / 546 | 44.713 / 30.265 | 780 / 546 |
| 100% unique, memo | 57.790 / 58.484 | 780 / 780 | 42.499 / 42.301 | 780 / 780 |
| 10% unique, memo | 57.587 / 7.204 | 780 / 78 | 44.521 / 5.209 | 780 / 78 |

Memory (60 rows, 70% unique) PHP peak incremental: baseline 109,736 B, MD5-only same, memo 127,992 B (+18,256 B). In 100% unique case, memo peak incremental 128,216 B vs baseline 103,040 B. At 1400-row stress, memo incremental peak 2,803,640 B vs original 2,394,296 B (+409,344 B).

## Deterministic concurrency correctness

research/freetext_local_cache_staleness.php uses the same value in two successive rows. The independent writer adds that value to the real Redis per-Feed set immediately **after** the first SISMEMBER call.

- Baseline sees a miss for the first row and a hit for the second.
- MD5-only has the same output as baseline.
- Local membership memo sees a miss both times, despite second row being able to observe the writer update in the original method.

The memo variant **fails temporal/read behavior preservation**. A request-scoped memo is not a snapshot and does not fix non-atomic upstream Feed Cache publication. Switching keys or caching first read at a higher layer merely changes this semantic gap.

## Decision

- **REJECT request-local membership result caching** for a narrowly scoped P2 optimization. It introduces stale reads and RAM state, and may not improve speed for distinct data.
- **MD5-only is safe in tested behavior**, but no significant end-to-end gain from reducing PHP MD5 calls when network waits dominate; low standalone PR value.
- **Pipeline variants remain separate proposals**: major latency reduction, but read-timing/error-path differences; MISP's existing User and Feed code already use Pipeline. Do not claim strict arbitrary-interleaving equivalence.
- Avoid modifying the whole upstream Redis Cache architecture as part of P2. Any versioned/atomic Cache publication is a **separate** project, not a hidden dependency of this performance PR.
- No upstream PR, no staging modifications. Next actionable gate is agreeing on the application's intended dynamic-cache read consistency and failure contract, or close P2 as not worth its complexity.

## Files

research/build_freetext_local_candidate.php, research/freetext_local_cache_staleness.php, research/test_freetext.php, .github/workflows/research.yml.
