# Baseline audit

Target: MISP `app/Model/Feed.php`, `Feed::attachFeedCorrelations()`.

Initial inspected SHA: `1f6a9d8ab8b197a366e549333736a321348d8ed7` (checked 2026-11-09).

Observed call shape:

1. Pipeline `SISMEMBER` of global combined cache.
2. For each visible source, pipeline `SISMEMBER` for hit hashes.
3. For matching MISP-format feed / eligible server source, pipeline `SMEMBERS` of `event_uuid_lookup:<hash>`.
4. Filter the global set by the current source id, then attach source/event UUID structures.

The global lookup set contains entries such as `<feedId>/<eventUuid>`, so step 3 can request identical keys for multiple sources. This is a static finding, not a measured production improvement.

Correctness requirements: source ACL and membership, attribute multiplicity, URL protocol stripping, composite type handling, source/UUID ordering, `FeedCount` limit, Feed/Server scope separation and memory usage.
