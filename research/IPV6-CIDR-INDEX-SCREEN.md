# MISP non-cache performance rescreen — IPv6 CIDR indexed lookup

Date: 2026-11-09. **Research-only.** No upstream, fork, staging, PR, issue, or comment actions.

## Why this path

The pinned MISP version `04fc5f8b7ccb52bb6614ca3e9ccdbfdf6585d985` has a linear IPv6 scan in `app/Lib/Tools/CidrTool.php::contains()`. `Warninglist::getFilteredEntries()` constructs a `CidrTool` for CIDR warninglists; `Warninglist::checkValue()` invokes `contains()` for matching inputs. Cost therefore grows with the IPv6 CIDRs in an enabled warninglist, independent of Redis availability or cache publication.

The public upstream `MISP/misp-warninglists` includes `lists/datacenter-ipv6/list.json` containing **8,752 entries** (file blob `542997f580077305a8868d6ad349b93cd871f22c`, last change commit `f8d976e7948baacc523af1eed75896aae0462bac`). Actual installations may have this list disabled; workload relevance depends on enabled warninglists and IPv6 traffic.

## Candidate

Create a prefix lookup per netmask in the `CidrTool` constructor, keyed by masked binary IPv6 prefixes. Keep the first original CIDR for colliding prefixes *within the same netmask group*. During `contains()`, probe this lookup instead of iterating every network. Preserve original per-netmask group order and overwrite behavior: later matching netmask groups can replace earlier results. Retain the legacy scan for invalid/unsupported masks.

The research-only candidate is produced dynamically from the pinned upstream source, with source signature checks. No new PHP dependency or MISP application change. The current prototype **retains both original IPv6 arrays and the prefix index**, therefore memory cost is higher.

## Validation

- [Initial source-derived synthetic run #42619417734](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42619417734): **FAIL**. Candidate incorrectly stopped at the first matching netmask group. This exposed a non-obvious, output-relevant ordering contract. Corrected in commit `532eaa9`.
- [Corrected synthetic run #42619530507](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42619530507): PASS strict `contains()` equality on 1,212 queries for each of 2, 52, 502, and 3,002 CIDR cases. Lookup speedups 7.30x (52), 61.78x (502), 336.49x (3,002). Synthetic test is not a production speed estimate.
- [Real warninglist run #42619943529](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42619943529): PASS strict `contains()` equality for 1,083 queries against the **8,752 real datacenter IPv6 CIDRs**, 572 hit cases. Six repeated timing rounds:
  - Original lookup **7,034.805 ms**; indexed lookup **40.799 ms**; **172.42x lookup-only speedup**.
  - Original construction **6.171 ms**; indexed construction **16.476 ms**.
  - Original object heap **711,784 bytes**; prototype object heap **1,636,448 bytes** (2.30x).
- [Adversarial + real-list repeat #42620482118](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42620482118): PASS **7 adversarial CIDR-list configurations × 1,212 queries** (including /0, overlaps, duplicates, non-canonical host bits, and IPv4-mapped input), plus all synthetic strict equivalence cases and 1,083 real-list comparisons. Repeated real-list timing: **9,343.588 → 53.353 ms, 175.13x**. The variation reinforces magnitude, not an exact production speedup prediction.
- Verified the current upstream default-branch SHA for both `CidrTool.php` and `Warninglist.php` still matches this pinned source on 2026-11-09; the hotspot is not only in a stale checkout.
- Auxiliary [Warninglist hostname screen #42618870705](https://github.com/Johnny-Kao/cookie-recipe/actions/runs/42618870705): strict equality PASS 25,020 inputs; isolated improvement only **1.57%** / 30,000 hostname lookups. **Reject hostname candidate**.

## Open gates

1. Extend fuzz testing for malformed/odd numeric mask syntax and arbitrary warninglist ordering. Existing adversarial /0, duplicates, overlaps, non-canonical host bits and IPv4-mapped input tests pass, but are not exhaustive.
2. Validate full `Warninglist::checkValue()` and application integration with type filters, not only `CidrTool::contains()`.
3. Benchmark lookup hit/miss ratios and list sizes, different PHP versions, and object lifetime in real requests.
4. Evaluate a more memory-efficient index representation without compromising fallback/ordering semantics.
5. Check current upstream implementation and maintainer preferences before any separate upstream action. Historical [MISP PR #6926](https://github.com/MISP/MISP/pull/6926) optimized `CidrTool` in 2021, but does not alone establish current proposal acceptance.

## Decision

**PROMISING, research stage only.** This is a materially more interesting candidate than generic Regex changes: reproducible large-list CPU savings in a real documented Warninglist use case, without modifying Redis or MISP API. It is **not** evidence of whole-MISP 172x speedup. No upstream work authorized.

Other upstream directions already being worked on include feed memory, tag/correlation batching, and sighting indexes; avoid duplicating their existing PRs/issues.
