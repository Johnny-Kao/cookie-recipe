# cookie-recipe — independent MISP correlation research

**Public research repository, not a fork or official MISP project.** Upstream is read-only: https://github.com/MISP/MISP.

## Current question

Can `Feed::attachFeedCorrelations()` do less work without changing existing behavior?

The first cross-source Redis UUID caching experiment reduced calls, but **failed strict equivalence under a concurrent Redis cache update**. It remains historical research only.

The **current candidate** retains every Redis command and its order, replacing one repeated PHP `array_column()/in_array()` check with a direct early-exit search over source IDs. It does not alter the Redis cache lifecycle.

## Evidence and status

See [research/OPTIMIZATION-REVIEW.md](research/OPTIMIZATION-REVIEW.md) for exact pinned upstream source, regression cases, concurrent-update test and measured performance.

- Static synthetic outputs: PASS on covered Feed/Server and ACL cases.
- Deterministic randomized synthetic Feed cases: PASS (100).
- Concurrent Redis modification: PASS; exact Redis operation sequence and outputs identical.
- PHP-side performance: small and workload-dependent; no meaningful production win demonstrated.
- **Full MISP integration tests: not yet executed. No upstream PR.**

## Workflow

GitHub Actions: [research.yml](.github/workflows/research.yml), manual `workflow_dispatch`, ephemeral Redis service. No production/customer data, secrets, or private feeds. Experimental code stays in this repo; any upstream work requires separate review and staging.

The older `benchmarks/redis_uuid_lookup.php` illustrates the rejected caching strategy and is **not** the active candidate.
