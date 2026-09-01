# Dibs v1 — build plan (orchestration record)

Tier: **FULL** — migrations, a concurrency-critical booking path, four modules.
Acceptance contract: `docs/SPEC.md` (ledger §9 is kept current there, not here).

## Build decisions (beyond the spec's D1–D13)

| ID | Decision | Why |
|----|----------|-----|
| B1 | Models are **extendable (not `final`)**; every other class is `final`. Pint's `final_class` rule is replaced by `final_internal_class` configured so classes annotated `@extensible` are exempt. | R35 (config class-map, "must extend the package's") is unimplementable with `final` models. `final_class` has no per-class opt-out. Deviates from the canonical hey-you `pint.json` — flag to Ryan. |
| B2 | `table_prefix` config key kept alongside `models` and `token_length`. | Package convention (prefixer); scaffold already shipped it. Spec §7's "nothing else" targets tz/permission/notification config. |
| B3 | Concurrency test (R13) lives in `tests/Concurrency/` on `DatabaseTruncation` (real commits) with a second named connection `testing_b`; deterministic via `lock_timeout` while connection A holds the row lock, then retry after A commits → `SlotUnavailable`. | RefreshDatabase wraps each test in a transaction, so a second connection can never see the fixtures. |
| B4 | Action names: `PublishAvailability`, `CloseAvailability`, `ReopenAvailability`? — **no**: `PublishAvailability` handles both `draft→published` and `closed→published`. `UpdateAvailabilityGeometry` for D6 regeneration, `DuplicateAvailability`, `DeleteAvailability`. | Spec names most; geometry edit and deletion needed names. |
| B5 | `BookingOptions` readonly DTO: `guardHostOverlap=false`, `type=null`, `meta=[]`, `viaOffer=false`. `viaOffer` is the D11 relaxation switch used only by `AcceptOffer`. | One BookSlot code path for both routes. |
| B6 | Events dispatched with `DB::afterCommit(fn () => event(...))` inside the action's `DB::transaction`. No dispatcher contract. | Spec §5 mandates after-commit; hey-you's indirection buys nothing here. |
| B7 | Status enums are backed string enums (`AvailabilityStatus`, `SlotStatus`, `BookingStatus`, `OfferStatus`) with `canTransitionTo()`; columns stay `string`, models cast. | Spec's state machines in one place per model. |
| B8 | `Dibs::model(Foo::class)` static resolver (config `models` keyed by the package class) returns the configured `class-string<Foo>`; all package-internal relationships/queries go through it. | R35; class-keyed map keeps PHPStan generics honest. |
| B9 | `AdhocSlotSpec(startsAt, endsAt, location, capacity=1)` readonly DTO for adhoc slot creation in offers. | CreateOffer takes a mix of `Slot` and specs. |
| B10 | `Slot::bookable()` binds PHP `CarbonImmutable::now('UTC')` as the reference instant and does interval math in SQL (`make_interval`). | Testable with `Carbon::setTestNow`; UTC-only (D10/R36). |
| B11 | Morph `*_id` columns are `string`, `*_type` string; no `morphs()` helper. | Consumers may key by uuid or bigint; the package never assumes. |
| B12 | The suite registers a `Relation::morphMap` for its fixtures (`user`, `room`, `organization`), so stored `*_type` values are aliases, proving the package respects the consumer's map. | Spec §4. |
| B14 | Package relies on Laravel's default UTC application timezone for Eloquent date serialisation; no custom `$dateFormat`. Bound reference instants are cast `::timestamptz` in SQL. | Fleet apps run UTC; documented in README. |
| B15 | Geometry regeneration deletes only open slots with **zero** booking rows; an open slot with a cancelled booking survives (FK restrict + D3) and blocks its grid position like held/booked. | D6 says "delete all open slots"; D3/R4 forbid deleting any slot with a booking row. D3 wins. |
| B16 | DuplicateAvailability also copies context, min-notice/max-horizon and meta, not just geometry/type/name/location/pool. | Same tenant, same booking rules; D1 makes notice/horizon geometry parameters. |
| B17 | The overlap guard checks the hosts **being assigned** to the new booking (auto-assigned per D9, or supplied to CreateDirectBooking), not every pooled host. | Refusing because some unassigned pool member is busy would be a solver (D8). |
| B18 | CreateDirectBooking drops the spec's optional `context?` argument: adhoc slots have no context columns. | Nowhere to store it; consumers scope through the booking's parties/hosts. |
| B19 | `CompleteBooking` / `MarkNoShow` leave the slot `booked` (release belongs to cancellation only, §5.2); a completed slot is in the past and never re-bookable anyway. | Spec assigns slot release to CancelBooking only. |
| B20 | `CreateDirectBooking` creates the adhoc slot `open` and lets the shared settle step flip it to `booked` — identical at capacity 1, correct above it. | One code path (D4). |
| B21 | A pool row whose host record no longer resolves is skipped by auto-assign rather than assigned or thrown on. | Consumer deleted a host; booking must still succeed. |
| B22 | A slot/offer row that vanished between the caller's copy and the lock is reported as `SlotNotOfferable` / `OfferNotAcceptable` ("no longer exists"); `WithdrawOffer` on a vanished offer throws `InvalidTransition` from the in-memory status. | No new exception types for a corner case. |
| B23 | ~~Adhoc offer specs are not checked at creation~~ **Superseded by B25** after review (offers #2). | — |
| B24 | `CreateOffer` clamps the token length to `max(40, config('dibs.token_length'))`. | A misconfigured consumer cannot weaken the only lookup key. |
| B25 | `AdhocSlotSpec::ensureValid()` (end after start, start in the future) runs in `CreateOffer` and `CreateDirectBooking`; `CreateOffer` also refuses an `expiresAt` not after now. An invalid spec writes nothing. | An unbookable held slot only `WithdrawOffer` could free is worse than an early `InvalidArgumentException`. |
| B26 | `ExpireOffers` isolates failures per offer and rethrows the first after the loop; successfully expired offers stay expired (own transactions). | A sweep that dies on the offer someone is accepting must not leave every other overdue offer pending. |
| B27 | **Ryan, 2026-09-01:** keep extendable models + `final_internal_class`; the rule is ported to the harness canonical `pint.json` (harness `340cca2`); per-package rollout of the other seven copies is a `package-check` sweep item. | Decision 1 sign-off. |
| B28 | **Ryan, 2026-09-01:** tenancy `context` is stamped on bookings (copied from the availability, or supplied) and on offers (supplied); `forContext()` scopes on Availability/Booking/Offer. Supersedes B18. | Direct appointments and all-adhoc offers had no path to a ward; ccstake is multi-org. |
| B29 | **Ryan, 2026-09-01:** an open slot with cancelled-booking history displaced by a regeneration becomes `retired` (fourth slot state) rather than staying bookable at its old time. Supersedes B15's "survives and blocks its position". | Regular grid after reshape; history kept. |
| B30 | Connection-pinning defect (QUEUE) fixed in the same follow-up: the two availability actions query slots via `Dibs::query(Slot::class)`. | Ryan chose fix-now. |
| B13 | Concurrency-safe slot fullness: BookSlot counts active bookings under the row lock rather than trusting `status`. | `status` is a derived cache; the lock + count is the truth. |

## Module ownership (disjoint)

| Module | Owner | Files |
|---|---|---|
| **Foundation** (frozen after commit) | orchestrator | `database/migrations/*`, `database/factories/*`, `src/Concerns/*`, `src/Enums/*`, `src/Models/*`, `src/Exceptions/*`, `src/Events/*`, `src/Data/*`, `src/Support/Dibs.php`, `src/Support/TablePrefixer.php`, `src/DibsServiceProvider.php`, `config/dibs.php`, `tests/TestCase.php`, `tests/Pest.php`, `tests/Fixtures/*`, `tests/Feature/Foundation/*`, `pint.json` |
| **A — Availability lifecycle** | builder A | `src/Actions/PublishAvailability.php`, `CloseAvailability.php`, `UpdateAvailabilityGeometry.php`, `DuplicateAvailability.php`, `DeleteAvailability.php`, `src/Support/SlotGrid.php`, `tests/Unit/SlotGridTest.php`, `tests/Feature/Availability/*` |
| **B — Booking core** | builder B | `src/Actions/BookSlot.php`, `CreateDirectBooking.php`, `CancelBooking.php`, `CompleteBooking.php`, `MarkNoShow.php`, `src/Support/OverlapCheck.php`, `src/Support/ReleaseSlot.php`, `tests/Feature/Booking/*`, `tests/Concurrency/*` |
| **C — Offers** (wave 2, after B merges) | builder C | `src/Actions/CreateOffer.php`, `AcceptOffer.php`, `WithdrawOffer.php`, `ExpireOffers.php`, `tests/Feature/Offer/*` |
| Manifests (orchestrator only) | orchestrator | `CHANGELOG.md`, `README.md`, `docs/SPEC.md`, `QUEUE.md`, this file |

Foundation stubs for cross-module contracts (`BookSlot`, `ReleaseSlot`) ship with
`throw new LogicException('not implemented')` bodies and are replaced by module B.

Worktrees: `.claude/worktrees/<mod>` on branches `feature/<mod>`; test DB per tree via
`DIBS_TEST_DB_DATABASE=testing_wt_<mod>`; driven with
`ddev exec --dir /var/www/html/.claude/worktrees/<mod> "..."`.

## Waves

1. Foundation (solo) → commit on `feature/v1`.
2. Builders A + B in parallel → merge into `feature/v1` → full gate.
3. Builder C (offers) + reviewers for A and B in parallel.
4. Merge C → full gate → reviewer C → single remediator → full gate → audit.

## Status (2026-09-01)

All four waves complete. Final gate on `feature/v1`: Pint 109 files, PHPStan L8 zero-ignore, Rector clean,
Pest **231 passed / 631 assertions** (10 two-connection concurrency tests). Every review finding fixed
with a mutation-verified test (`docs/plans/reviews/*.md`). Worktrees and `testing_wt_*` databases torn down.

## Review findings

Written to `docs/plans/reviews/<module>.md` (no GitHub remote; no PRs).
