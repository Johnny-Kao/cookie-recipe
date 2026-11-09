# MISP Feed Correlation Research

Independent CoreFoundry research. **Not a fork, not an official MISP project.**

Upstream: https://github.com/MISP/MISP

## Hypothesis

`Feed::attachFeedCorrelations()` uses per-source Redis membership checks and then repeatedly reads the same global `event_uuid_lookup:<hash>` set when one hash matches several MISP feeds. Reuse a fetched lookup by unique hash without changing user visibility, source membership, output ordering or UUID association.

## Safety and scope

- Never push experimental code to MISP upstream.
- All benchmarks use synthetic, public-safe data; no production events, UUIDs, tokens, credentials or customer feeds.
- Keep Feed and Server cache namespaces separate.
- Maintain baseline and optimized output equivalence, including duplicate hash/attribute cases, URL stripping, composite types, and non-admin server restrictions.
- Do not claim end-to-end speedup from a synthetic microbenchmark.
- Keep this repo private between intentional GitHub Actions test windows; public exposure can be copied and is not reversible.

## Research plan

1. Pin an upstream revision and inspect existing tests and call sites.
2. Reproduce Redis `SMEMBERS` duplication with a real ephemeral Redis instance.
3. Build behavior-equivalence cases (including ACL and ordering).
4. Implement a minimal bounded-cache candidate in independent research only.
5. Measure commands, bytes, wall time and peak PHP memory (1k/5k/10k attributes; 5/25/100 sources).
6. Only after the comparison passes, consider a separate staging branch; upstream is out of scope for this research phase.

Run GitHub Actions via `workflow_dispatch`. Results are saved as artifacts. The main branch never automatically publishes or opens PRs.
