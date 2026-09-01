# Dibs

Headless slot-based booking engine for Laravel: availabilities generate slots, offers hold them, bookings claim them; polymorphic hosts with roles. No UI, no auth, no notifications - fires events, consumers decide.

Composer name: `robinsonryan/dibs` — a **library**, not an application.

## The spec is the acceptance contract

**`docs/SPEC.md`** holds the approved v1 spec: lexicon, fourteen design decisions
(D1–D14), full data model, behaviors, a 44-row requirements ledger (complete as of
0.1.2, 2026-09-01), and twelve explicit non-goals. New work amends the spec first,
then builds against it requirement-by-requirement, keeping the ledger's status
column current — no unqualified "done" (see the `verification` skill).

Anything decided *outside* the spec during the build is in
`docs/plans/v1-build.md` §Build decisions (B1–B33: why the overlap guard checks
only assigned hosts, why regeneration retires rather than deletes, why models are
extendable, …). Read it before re-litigating a behavior. Review findings and their
dispositions are under `docs/plans/reviews/`; deferred work is `QUEUE.md`.

The ccstake consumer integration is **informative only** in the spec (§10); its
normative spec lives in the ccstake repo. Supported range: PHP ^8.2, Laravel
`^12|^13` (`^11` dropped 2026-09-01 — the dev constraints never resolved to it).

## Reuse first-party packages where they apply

Before adding a dependency or hand-rolling a capability, check the existing
`robinsonryan/*` packages (`~/dev/php/packages/robinsonryan/`): `taxon`
(hierarchical tags — the reference package for structure and TestCase),
`permixion` (roles/permissions — consumer-side, never a dibs dependency),
`hey-you` (contact points/consent — consumer-side for reminders). Dibs itself
stays dependency-light: illuminate components only.

## Conventions the reviews enforce

Three adversarial review passes converged on these; every one produced findings
when skipped.

- **Models are `@extensible` and non-final by design** — consumers substitute
  subclasses through `config('dibs.models')`. Everything else is `final`; Pint
  enforces it via `final_internal_class` with an `@extensible` opt-out. Never add
  `final` to a model, never add `@extensible` to anything that is not a model.
- **Every package-internal query goes through the class-map**:
  `Dibs::model()`, `Dibs::make()`, `Dibs::query()`, `Dibs::lock()` — never
  `Slot::query()` / `new Slot` in `src/`. Relationship methods already do this.
- **Decide state transitions from a locked re-read** (`Dibs::lock($model)`,
  `FOR UPDATE`), never from `refresh()` or the caller's in-memory copy. Count
  capacity under the slot's row lock. A regeneration locks every slot of the
  availability before it deletes or retires one.
- **Actions are `final` invokables** in `src/Actions/`, wrapped in
  `DB::transaction`, firing their event with `DB::afterCommit(fn () => event(...))`
  inside the transaction, with the event's models loaded first. Invalid moves
  throw `InvalidTransition::for()`; the enums' `canTransitionTo()` is the table.
- **Raw SQL must be a literal string** (Larastan). Table names come from config,
  so they cannot appear in `whereRaw`; `Slot::bookable()` shows the way out — a
  derived table (`fromSub`) so unqualified column names resolve.
- **UTC instants only** (D10): `CarbonImmutable::now('UTC')` / `Slot::instant()`;
  no `timezone`/`setTimezone`/`parse` in `src/`.

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

`.githooks/pre-commit` runs **the whole gate, tests included** — ~40 s at 257
tests (2026-09-01), still far from the apps' exclude-the-tests compromise. It is path-aware, so a docs-only commit skips it. Never bypass with
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
ddev composer test                 # or: ddev test
ddev exec vendor/bin/pest --filter=SomeTest
```

There is no `ddev artisan` and no `ddev pest` here — those are app commands.

### Worktrees get their own database automatically

`tests/TestCase.php` derives the database from the checkout path: a tree nested
at `.claude/worktrees/<slug>/` runs against `testing_wt_<slug>`, created on first
run; the main checkout uses `testing`; `DIBS_TEST_DB_*` overrides all. Drive a
worktree from the main root — never `cd` in and run `ddev`:

```bash
git worktree add .claude/worktrees/<slug> -b feature/<slug> develop
ddev exec --dir /var/www/html/.claude/worktrees/<slug> "composer install"
ddev exec --dir /var/www/html/.claude/worktrees/<slug> "vendor/bin/pest tests/Feature/Offer"
# teardown
git worktree remove .claude/worktrees/<slug>; ddev exec "dropdb -h db -U db testing_wt_<slug>"
```

### Concurrency tests — the lock must have a test that fails without it

`tests/Concurrency/` runs on `DatabaseTruncation` (real commits) with a second
connection `testing_b` on the same database. Worked example:
`tests/Concurrency/BookSlotConcurrencyTest.php`. The recipe: session A holds
`SELECT … FOR UPDATE` on the row; session B (`DB::setDefaultConnection('testing_b')`,
`set lock_timeout = '300ms'`) runs the action and must fail with SQLSTATE `55P03`
having issued **no** insert/update/delete (assert on `enableQueryLog()`); A commits;
B re-runs and gets the domain exception. Mutate the lock out and confirm the test
goes red before you trust it — three review findings were locks no test covered.
`afterEach`: reset the default connection and `TRUNCATE dibs_*, fixture_* CASCADE`,
because the Feature suite shares the database in the same process.

## Branches and releases

Remote: `git@personal.github.com:robinsonryan/dibs.git` (public). `develop` is
the working branch — commit there. `main` only ever moves by fast-forward from
`develop` and carries the tags; ccstake consumes tags.

Tag when all four hold (stack constitution §4): the gate is green on the commit
being tagged, `CHANGELOG.md` names what changed (behavior changes land there **in
the commit that makes them**), the version follows caret-on-zero semver (a
consumer-visible behavior change takes the minor, a fix or addition the patch),
and the tag is annotated. Then:

```bash
git checkout main && git merge --ff-only develop
git tag -a vX.Y.Z -m "dibs X.Y.Z — <one line>"
git push origin main develop vX.Y.Z
git checkout develop
```

Say in the report what you tagged and why that number. Do not tag with review
findings open or the version genuinely contested — report the options instead.

## Reference package

`~/dev/php/packages/robinsonryan/hey-you/` is the reference implementation —
service provider shape, Testbench setup, tool configs, table prefixing. Read it
before inventing a variant.

## Pitfalls seen in practice

- **`ddev start` failed only on `ddev-router`** (shared across projects): web and
  db are up but the post-start hooks never ran. Do them by hand:
  `ddev exec composer install`,
  `ddev exec 'psql -h db -U db -d db -c "CREATE DATABASE testing"'`,
  `git config core.hooksPath .githooks`.
- **Pint snake_cases any method in a test class whose name starts with `test`**
  (`testDatabaseName()` → `test_database_name()`, which Rector then flags as
  unused). Name helpers in `tests/TestCase.php` something else.
- **After a `harness package-check` sweep** the main checkout's tools may be
  newer than a worktree's; a branch that was green in its tree can fail Pint on
  merge. Run `ddev composer lint` in the main checkout and commit the style fix.

## Quick reference

- **DDEV**: `ddev start`, `ddev ssh`
- **Gate**: `ddev composer quality` (or `ddev quality`)
- **Tests**: `ddev composer test` (or `ddev test`)
- **Style fix**: `ddev composer lint`
- **Rector fix**: `ddev composer refactor`
- **Build record**: `docs/plans/v1-build.md`, reviews in `docs/plans/reviews/`
