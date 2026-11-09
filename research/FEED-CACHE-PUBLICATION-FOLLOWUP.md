# Feed Cache publication: independent read-side research

Date: 2026-11-09. Scope: independent public research repository Johnny-Kao/cookie-recipe.
**No writes to MISP upstream, fork, staging, issues or PRs.**

## Source-confirmed baseline

In pinned MISP `app/Model/Feed.php`, `__cacheFreetextFeed()` first `DEL`s the published per-Feed Redis Set `misp:feed_cache:<feedId>` and then inserts hashes in chunks of 5000. This permits a reader to observe an absent or partially rebuilt Set during normal feed refresh. The cache timestamp is updated after success, and is not a snapshot or lock. This is an existing cache-publication limitation, independent of P1/P2 optimization.

## Independent Redis/Valkey experiment

[GC #42594631467](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42594631467): Redis 7 + Valkey 7.2 SUCCESS.

`research/test_cache_publication.php` uses two separate connections to an ephemeral test datastore and **only lab-owned keys**, never MISP database/production services. It verifies these expected observations:

- Starting from a valid old entry, deleting the published Set makes `EXISTS` false and an old membership query false.
- Rebuilding a new Set makes new data visible.
- Building a temporary Set leaves the old published Set readable; `RENAME(temp, published)` replaces the published Set atomically.
- A second atomic swap between two separate membership reads still permits different generations to be observed.

**Limits:** This is a deterministic low-level Redis test, not a production MISP incident reproduction, not a full CakePHP integration, and not a complete multi-key cache consistency proof. Empty sets and crash cleanup need separate handling before any cache publication patch.

## Relationship to P1/P2 correctness

- Original sequential membership reads do not offer request-scoped snapshot consistency; each query can independently observe a different published state. A pipeline can differ in timing without necessarily violating a documented application contract.
- A request-local membership memo **does** create a stale-read difference in the deterministic repeated-value test; avoid it without explicit invalidation semantics.
- An atomic per-Feed publication fix would prevent partial sets but **not** guarantee one consistent version across Feeds, `combined`, `event_uuid_lookup` or multiple membership calls.
- To keep P1/P2 narrowly scoped, treat any writer-side cache publication change as separate prospective research; no implicit dependence on a future cache redesign.
- For an upstream-quality P2 decision, determine expected membership freshness behavior, failure behavior and observed users' correctness contract; then compare a minimal pipeline against that contract, not against impossible global time-order identity.

## Research direction and status

The evidence supports a real low-level partial-publication window and the single-key atomic-swap mitigation. It does **not** establish that the current P2 pipeline is functionally wrong or right during refresh. Existing synthetic static results and failure-path experiments remain in separate records. Research is ongoing, no production patch authorized.

The GC workflow no longer reads the deleted MISP fork branch or the previously created PR candidate. All test execution remains on GitHub Actions.
