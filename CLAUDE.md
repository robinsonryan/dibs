# Dibs

Headless slot-based booking engine for Laravel: availabilities generate slots, offers hold them, bookings claim them; polymorphic hosts with roles. No UI, no auth, no notifications - fires events, consumers decide.

Composer name: `robinsonryan/dibs` — a **library**, not an application.

## The spec is the acceptance contract

**`docs/SPEC.md`** holds the approved v1 spec: lexicon, thirteen design decisions
(D1–D13), full data model, behaviors, a 39-row requirements ledger, and twelve
explicit non-goals. Build against it requirement-by-requirement and keep the
ledger's status column current — no unqualified "done" (see the `verification`
skill). The ccstake consumer integration is **informative only** there (§10); its
normative spec lives in the ccstake repo.

## Reuse first-party packages where they apply

Before adding a dependency or hand-rolling a capability, check the existing
`robinsonryan/*` packages (`~/dev/php/packages/robinsonryan/`): `taxon`
(hierarchical tags — the reference package for structure and TestCase),
`permixion` (roles/permissions — consumer-side, never a dibs dependency),
`hey-you` (contact points/consent — consumer-side for reminders). Dibs itself
stays dependency-light: illuminate components only.

## Conventions

@import ./constitution.md
@import ./imports/package-conventions.md
@import ./imports/package-quality-gate.md
@import ./imports/testing-conventions.md
@import ./imports/php-conventions.md
@import ./imports/git-conventions.md

> Linking the `laravel-package` stack also drops the inherited Laravel app
> conventions into `.claude/imports/` — `authorization-conventions.md`,
> `frontend-conventions.md`, `pwa-conventions.md`, `ddev-worktrees.md`. They are
> **deliberately not imported above**: they describe Inertia `can` maps, app-shaped
> Vite wiring, and nested app worktrees, none of which exist in a package. Read
> them if a question genuinely calls for one; do not load them by default.
>
> The exception is `frontend-conventions.md` — **import it if this package has a
> frontend half** (today: `yikes`, `four-corners`).

> `.claude/` is a set of **harness symlinks** and is gitignored — a fresh clone has
> none of them and the `@import`s above resolve to nothing. If a convention file is
> missing, restore the link rather than guessing:
> `~/workspace/harness/link.sh project laravel-package $(pwd)`

## The gate

`ddev composer quality` — `lint:check` → `analyze` → `refactor:check` → `test`.
Verify-only: it never rewrites files. Fix with `ddev composer lint` /
`ddev composer refactor` and re-stage.

`.githooks/pre-commit` runs **the whole gate, tests included** — packages are
small enough (13–21 s measured) that the apps' exclude-the-tests compromise does
not apply. It is path-aware, so a docs-only commit skips it. Never bypass with
`--no-verify`; `PACKAGE_SKIP_GATE=1` is a human emergency valve and **agents must
never set it**.

That hook file is a **copy** of the harness's canonical one. Do not edit it here
— edit `$CLAUDE_HARNESS_DIR/core/stacks/laravel-package/hooks/pre-commit` and
re-run that directory's `install.sh`.

The gate never rewrites your source and never stages anything. If this package
**commits a build artifact**, the hook additionally refuses any commit where the
gate itself changed a tracked file — that means the committed artifact no longer
matches its source, and the rebuild would otherwise ship outside your commit.
Stage the rebuilt artifact alongside the source that produced it.

`harness package-check` sweeps every first-party package: the gate, a
`--prefer-lowest` run proving the declared version floor really resolves,
outdated and vulnerability scans, and in-constraint updates behind a re-run of
the gate. It never tags a release. Run it before any app re-resolves its
packages.

Full definition: `imports/package-quality-gate.md`. Skill: `/package-quality`.

## Testing

Pest + Orchestra Testbench against **real Postgres** (DDEV's `db` service,
`testing` database — SQLite cannot express the `uuidv7()` column defaults).

```bash
ddev composer test
ddev exec vendor/bin/pest --filter=SomeTest
```

There is no `ddev artisan` and no `ddev pest` here — those are app commands.

## Releases

**Never tag.** Automation may update, gate, commit and push a branch, then report
"ready to tag" with a suggested version. Ryan cuts every tag. A version number is
a claim about behavior that a green gate cannot substantiate.

Behavior changes land in `CHANGELOG.md` in the commit that makes them.

## Reference package

`~/dev/php/packages/robinsonryan/hey-you/` is the reference implementation —
service provider shape, Testbench setup, tool configs, table prefixing. Read it
before inventing a variant.

## Quick reference

- **DDEV**: `ddev start`, `ddev ssh`
- **Gate**: `ddev composer quality`
- **Tests**: `ddev composer test`
- **Style fix**: `ddev composer lint`
- **Rector fix**: `ddev composer refactor`
