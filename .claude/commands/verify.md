---
description: Run all quality gates and review a feature (Verify phase)
argument-hint: <feature-name>
---

Verify the feature `$1` against the Definition of Done (Constitution §6).

1. Run the full gate chain: `make check` (php-cs-fixer, PHPStan max, Deptrac, PHPUnit). Fix any
   failures via the owning agent, then re-run.
2. Delegate to the **reviewer** agent with the list of changed files (`git diff --name-only main...`).
   It returns PASS or NEEDS CHANGES with CRITICAL/MAJOR/MINOR/STYLE findings.
3. Check every acceptance criterion in `docs/specs/$1/feature-spec.md` off against the implementation.

**Escalation rule:** if the reviewer returns NEEDS CHANGES (any CRITICAL or MAJOR), send the findings
back to the owning agent, fix, and re-verify — up to **3 iterations**. After 3 without PASS, **stop
and bring it to the user**: repeated failure means the spec or design is wrong, not the code.

On PASS with all criteria met: confirm the feature is done, remind the user to update docs
(CLAUDE.md / ADR / FORboehpyk.md) where behaviour changed, and that the spec in `docs/specs/$1/` may
now be archived — its behaviour lives in tests and code.
