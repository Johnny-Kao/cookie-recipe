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

## Extended validation (GC #42614286613)

[GC #42614286613](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42614286613): both Redis 7 and Valkey 7.2 jobs SUCCESS. Research-only commit `47d2464` adds `research/refang_extended_validation.php` and the workflow invocation; the candidate itself is unchanged.

- Strict `__refangInput()` equivalence: **12,062 cases PASS**, including case variations, Unicode/full-width brackets, control characters, empty and long strings, overlapping defang patterns, and 12,000 seeded random strings.
- Six separate `checkFreeText()` datasets (2,400 unique rows each): strict full-output equality PASS.
- Interleaved ABBA timing, 16 samples/variant, median of middle two; **elapsed time reduction** (Redis 7 / Valkey 7.2):
  - Domains: **14.72% / 14.04%**.
  - Normal URLs: **11.18% / 10.64%**.
  - Mixed (20% each of domain/URL/hash/IP/defanged): **6.42% / 5.19%**.
  - IP: 0.38% / 0.57%.
  - Hash: -0.38% / -0.19%.
  - Defanged URL: **-2.09% / -2.48%** (regression).
- Note: service labels identify GC matrix runners, **not a claim that Redis or Valkey changes parser speed**. No actual production IOC distribution, memory profile, or full MISP application integration is measured.
- Interpretation: stable benefits for normal domains and URLs; modest benefit for the synthetic balanced mix. Already-short-circuited IP/hash paths do not benefit, and defanged patterns are slower. The next meaningful gate is a representative feed distribution and a low-cost profiling comparison, rather than another large candidate patch.
- Other hotspots screened in the pinned source: `__resolveType()` already short-circuits clean IP/hash/email; `parseFreetext()` uses three regex passes and a split, but replacing them risks Unicode/control-character and quoted-token semantics. Do not propose reordering/resynthesis without profiling and full equality testing.

## parseFreetext() cost screen (GC #42615493575)

[GC #42615493575](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42615493575) completed SUCCESS on Redis 7 and Valkey 7.2 runners. Research-only profiling script: `research/profile_parse_freetext.php` (commit `0f33fd5`). No application patch.

For 2,400 input lines per distribution, independently timed `parseFreetext()` against public `checkFreeText()` (14 repeats, median; same input and warmed functions):

| Distribution | Split as % of full (two runners) | Split time per 2400 rows |
| --- | ---: | ---: |
| Domain | 2.79–2.82% | ~0.55 ms |
| URL | 3.80–3.89% | ~0.96 ms |
| Hash | 20.37–20.54% | ~1.83 ms |
| IP | 14.71–15.69% | ~0.38–0.45 ms |
| Defanged | 3.57% | ~0.78 ms |
| Quoted | 2.40–2.43% | ~1.42 ms |
| Noisy | 2.40–2.44% | ~1.96 ms |
| Mixed | 3.57–3.60% | ~1.14 ms |

**Decision:** Park generic split/Regex rewrite: it has a small cost share for domain, URL, noisy, quoted, and mixed feeds, while preserving Unicode, control-character, and quoted-token semantics is risky. Hash-heavy is an exception in percentage only: the absolute split cost remains ~1.83 ms/2400 rows. IP-heavy is similarly small in absolute cost. These independently timed ratios are *not instrumented inclusive call-graph shares* and are not projected speedups. There is no new validated patch candidate. Refang remains the only active candidate.

## Cost and opportunity

Small, local PHP change; no new classes or dependencies in upstream; isolated research generator and regression script only. Potentially valuable on ordinary domain-heavy feeds. Caveats: some clean input types (IP) bypass refang already, and defanged input is marginally slower. Test more edge cases and verify PHP versions/whole-app integration before considering upstream.

**Recommendation:** Keep as the top non-cache research candidate, not Ready. Reject heavier SIMD extension, cache-rewriting or API migration for this specific patch. No upstream action authorized.
