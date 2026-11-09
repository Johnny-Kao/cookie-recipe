# Cache-independent MISP optimization screening — 2026-11-09

**Scope:** MISP/2.5 pinned at `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985`. Research-only. No changes or submissions to upstream, fork, staging, Issues or PRs.

## Screening decision

P1/P2 remain parked due to unresolved semantics of concurrent cache reads and publication. SIMD directly in PHP is not a useful low-cost goal; a pure-PHP **fast path** can skip expensive PCRE calls when no defanging pattern is possible.

**Candidate:** `app/Lib/Tools/ComplexTypeTool.php::__refangInput()`. Precheck for `[`, `(`, and the four special prefixes `hxxp`, `hxtp`, `htxp`, `meow`. When none is present, perform only existing `rtrim(..., '.')` and return unchanged `['raw'=>...,'refanged'=>...]` shape. Original regex path remains unchanged for defanged inputs. No Redis, SQL, API, or cache changes.

## Results (GC on real PHP, no application integration)

[GC #42611674204](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42611674204) both Redis 7 and Valkey 7.2 jobs SUCCESS (Redis services incidental to other research tests). The script dynamically extracts and patches the exact upstream class.

- 2,229 strict private-method input/output equivalence cases: PASS.
- 5 mixed full public `checkFreeText()` payloads: strict output equivalence PASS.
- Full public `checkFreeText()`, 2,200 distinct rows each, Redis job: normal domains **14.178 → 11.741 ms (1.21x, -17%)**; IP **1.851 → 1.802 ms (1.03x)**; defanged URLs **16.279 → 16.486 ms (0.99x)**. All 2,200 outputs strictly identical.
- Isolated private-method speed: clean strings ~1.9–2x, defanged ~0.92x. Do not claim this as total parsing improvement.
- These are short GC measurements and synthetic indicator distributions, not measured whole MISP production traffic, not a formal proof for all Unicode/input data, and not full application integration.

## Cost and opportunity

Small, local PHP change; no new classes or dependencies in upstream; isolated research generator and regression script only. Potentially valuable on ordinary domain-heavy feeds. Caveats: some clean input types (IP) bypass refang already, and defanged input is marginally slower. Test more edge cases and verify PHP versions/whole-app integration before considering upstream.

**Recommendation:** Keep as the top non-cache research candidate, not Ready. Reject heavier SIMD extension, cache-rewriting or API migration for this specific patch. No upstream action authorized.
