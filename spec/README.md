# spec/

Implementation specs and plans. **The filename prefix is the status** -- there is no other
ledger, so a wrong prefix is a wrong answer to "what is still open?".

| Prefix | Meaning |
|---|---|
| `<version>-` | Shipped in that release. Historical record; do not edit. |
| `upcoming-` | Accepted, not implemented. Real open work. |
| `draft-` | Idea under consideration. Not committed to. |
| `v10-` | Scheduled for the next major. |

## Renaming on release

CLAUDE.md requires the version prefix to be applied **before** tagging:
`spec/my-feature.md` becomes `spec/8.6.1-my-feature.md`. Only the prefix changes -- keep the
slug so history and links survive.

This drifts easily. On 2026-09-01 five specs still said `upcoming-` for work that had shipped
long before -- parallel dispatch (5.0.0), its lock infrastructure (5.0.0), the race-condition
analysis and deep edge-case QA (8.6.3), and state change listeners (7.9.0). Each was dated
March 2026 and read as open work for months. The versions above were recovered from git
(`git log --diff-filter=A` on the implementing file, then `git describe --tags --contains`),
which is also how to date one if it drifts again.

## Companion files

A spec may carry `.tasks.json` (tp task file), `.tasks.json.lock`, `.derivation.md`, and
`-release-notes.md`. `spec/upcoming-*.tasks.json` is gitignored, so a tp run leaves an untracked
file behind that keeps saying `upcoming-` after the work ships -- check for one when the spec
itself gets its version prefix.
