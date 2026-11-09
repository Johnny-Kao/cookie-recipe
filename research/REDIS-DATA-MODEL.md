# Redis data model and query optimization decision

Reviewed from MISP/MISP `app/Model/Feed.php`, pinned commit `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985` (blob `1f6a9d8ab8b197a366e549333736a321348d8ed7`). Synthetic examples are illustrative, not actual feed records.

## Input and fanout

The method accepts an array of MISP attributes indexed by original attribute position. Each has `type`, `value`, and optionally `disable_correlation`, `Feed` or `Server` attached correlations.

- Ignore NON_CORRELATING_TYPES or disabled correlations.
- Composite types: split `value` and optionally only correlate primary part.
- URL/URI: in addition to full value, also correlate protocol-stripped value.
- Each part hashes to `md5(part)`.
- `hashTable[index]` retains a hash for each **part occurrence**, not each unique hash.
- `redisResultToAttributePosition[index]` maps that occurrence to its attribute; repeated values cannot simply be dropped.

## Redis sets and namespacing

For `scope=Feed` use `misp:feed_cache:`; for `scope=Server` use `misp:server_cache:`.

| Redis key | Type | Contents | Purpose |
| --- | --- | --- | --- |
| `<prefix>combined` | Set | MD5 part hashes | Global prefilter |
| `<prefix><sourceId>` | Set | MD5 part hashes | Match a particular Feed/Server |
| `<prefix>event_uuid_lookup:<hash>` | Set | Strings `<sourceId>/<eventUuid>` | Hash-to-source/event UUID mapping |

Example `misp:feed_cache:event_uuid_lookup:<hash>` = `{ "101/UUID-A", "102/UUID-B" }`.
The same global lookup key is fetched per matching source, followed by filtering each `<sourceId>/<eventUuid>` entry. This permits duplicate reads of an identical key.

## Baseline calls

1. `SCARD combined`: one check.
2. `SISMEMBER combined hash`: one for **each hash occurrence**, pipelined.
3. `SISMEMBER sourceId hash`: for each combined hit and each selected source, pipelined per source.
4. `SMEMBERS event_uuid_lookup:hash`: for each source hash hit where eligible, pipelined per source.
5. Filter UUID members by current source ID, update both `event` and `attributes` in order, preserve access and limit semantics.

`SISMEMBER` and `SMEMBERS` counts are **commands**, not network round trips: the existing implementation uses pipelines.

## Tradeoff: static correctness versus strict concurrent equivalence

- If lookup keys do not change during the call, fetching unique UUID sets once and rebuilding each occurrence/source result can produce the same output (covered by previous tests), but memory and ordering must be checked.
- If a writer adds/deletes a member after source A's read and before source B's read, baseline may observe the update on B; a cross-source in-process cache may not. This difference is demonstrated in run #42553649025.
- Reducing the same sequence of Redis reads while promising identical results for **every arbitrary concurrent write schedule** is not generally possible unless a consistency/snapshot contract is changed or writers participate in synchronization.
- There is no evidence yet that MISP formally promises per-source freshest reads; we must not equate an observable scheduling difference with a confirmed production bug.
- A small PHP-only `array_column` elimination candidate preserves Redis call order, but does **not** accomplish the main Redis-call-reduction objective and is considered secondary.

## Research gates before optimization patch

1. Inspect realistic MISP Attribute/source hit distribution and UUID set cardinalities, using synthetic or anonymized counts. Measure unique hash ratio, average matching sources/hash, `SMEMBERS` command duplication, return bytes, peak memory, wall time.
2. Audit the writer/reader cache lifecycle and whether temporal differences within one request are permitted. MISP feed cache rebuilds use both `DEL` and `SADD`; establish whether source membership and UUID lookup are updated atomically.
3. Design two candidates: per-source dedup with order reconstruction; cross-source bounded memoization with explicit cache-consistency tradeoff. Keep originals and candidates in the independent research repo.
4. Compare attribute positions, duplicates, UUID order, Feed/Server ACL, cutoff, retries, concurrent modifications and OOM risk. Avoid overstating synthetic speedups.
5. Full MISP integration suite and maintainability review before staging or upstream.

**Decision: HOLD on an upstream optimization. Main objective remains reducing redundant Redis commands subject to an explicit consistency contract.**
