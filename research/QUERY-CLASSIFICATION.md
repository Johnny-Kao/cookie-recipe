# MISP Redis query classification — research before patch

Date: 2026-11-09. No MISP patch was applied. Independent public lab: `Johnny-Kao/cookie-recipe`.

Source inspected: MISP/MISP `app/Model/Feed.php` at commit `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985`; downstream caller in `app/Controller/AttributesController.php`; event enrichment guide in `app/View/Themed/EventTest/AJAX_ENDPOINTS_GUIDE.md`.

## Key layout and input invariants

Input is a list of Attribute arrays; each `type` and `value` becomes one or two correlation parts (composite primary/secondary, URL full/protocol-free). Each part occurrence is retained as its own position. Original `hitCount` is **occurrence count**, not distinct hash count. Permission checks precede cache lookup; Feed/Server namespaces must remain separate.

| Stage | Key | Current commands | Classification idea |
| --- | --- | --- | --- |
| Check global cache nonempty | `<prefix>combined` | one SCARD | keep |
| Global hash matching | `<prefix>combined` | one pipelined SISMEMBER for **each part occurrence** | One SMISMEMBER over occurrence-ordered array (optional chunks), preserving same result count and positions |
| Per-source matching | `<prefix><sourceId>` | one pipelined SISMEMBER for each global hit | One SMISMEMBER per source, using same ordered hits (optional chunks) |
| UUID lookup | `<prefix>event_uuid_lookup:<hash>` | one SMEMBERS per matching source/hash occurrence | Cross-source caching reduces repeated reads, **but changes concurrent freshness**. Hold this separate. |
| Attach sources and UUIDs | Attribute index, Feed/Server source ID, Event UUID | local PHP logic | preserve original traversal and ACL |

The first two classifications **reduce Redis command count without storing UUID sets**. They do not eliminate the server-side O(number of members) membership computation; they reduce command handling and response framing, which might matter despite the existing pipeline.

## Verified Redis batch experiment

Independent probe `research/redis_membership_batch_probe.php` compares pipelined `SISMEMBER` with `SMISMEMBER` using **the same ordered member list (including duplicates)** against a real Redis 7.4.12 instance. PHP Redis reports native sMIsMember available, but the probe uses `rawCommand` to avoid assuming a specific wrapper signature. 9 repetitions per test. See [Actions #42567263278](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42567263278).

| Member occurrences | SISMEMBER commands | SMISMEMBER commands | Pipeline median ms | Batch median ms | Output |
| ---: | ---: | ---: | ---: | ---: | --- |
| 10 | 10 | 1 | 0.119 | 0.107 | identical |
| 100 | 100 | 1 | 0.231 | 0.138 | identical |
| 400 | 400 | 1 | 0.571 | 0.213 | identical |
| 800 | 800 | 1 | 0.971 | 0.269 | identical |

This is **not MISP whole-function performance**. Redis documentation: SMISMEMBER available since Redis 6.2, returns one result for each input position, O(N) in member count. Deployment Redis version and compatible PHP Redis version **must be checked**, not assumed.

## Semantics / tradeoffs requiring further proof

1. Redis batch command versus many pipelined membership commands: static data results identical, but an intervening writer may observe different intermediate membership snapshots; strict concurrent observational equivalence is **not guaranteed**.
2. The existing pipeline is not a cross-command transaction. A single batched command is atomic and may actually improve request-level consistency, but that is a change in temporal observation semantics if the original intermediate states are considered contractual.
3. `hitCount > 10000` cutoff must use the original number of positive **part occurrences**, not the number of unique hashes. De-duplicating input without re-expansion is invalid.
4. `array_column` performance micro-optimization from prior session is secondary; it does not address expensive membership and UUID queries.
5. `SMEMBERS` remains costly when many feeds share a hash. Cross-source reuse should be evaluated only once cache-update contract, bounded memory and event consistency are understood.
6. Need production-like distributions: attribute part occurrences, unique hash ratio, per-source positive rates, duplicate UUID lookup count, cardinality, Redis and PHP versions.
7. Test full extracted `attachFeedCorrelations` function with batch-member candidate and compare actual Attributes/Event, ACL, order, timeout, errors, long arrays and behavior across concurrent writers, **before** any upstream/staging patch.

## Research verdict

**Priority A: batched membership classification** as a plausible low-complexity high-command-reduction path; **Priority B: UUID lookup caching** only with accepted consistency semantics; **Priority C: PHP-only loop** secondary. There is not yet enough integration or concurrency-contract evidence to patch upstream. No write to MISP or upstream staging has occurred.
