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
| B31 | A spent-history open slot is retired **only when displaced** — no position of the new grid is identical to it; an identical position keeps the slot as-is (it *is* the grid slot). Re-submitting the same geometry changes nothing. | §2 says "displaced by a grid regeneration"; without this, identical edits duplicate slots (follow-up review). |
| B32 | Regeneration locks every slot row of the availability `FOR UPDATE` before deleting/retiring, so a concurrent BookSlot serialises behind it (READ COMMITTED re-checks the row but not the `NOT EXISTS` subquery). | Follow-up review BLOCKER, reproduced. |
| B33 | Identity preservation on regeneration applies to **every** open slot that exactly matches a new-grid position (not only spent-history ones): it keeps its row/id/status; a consumer-edited capacity survives an unchanged position. Resubmitting the same geometry writes nothing. | Remediator's generalisation of B31; accepted — fewer churned rows, no behaviour a consumer would miss. |
| B13 | Concurrency-safe slot fullness: BookSlot counts active bookings under the row lock rather than trusting `status`. | `status` is a derived cache; the lock + count is the truth. |
| B34 | `AssignBookingHost` refuses **only** a cancelled booking (`InvalidTransition::for($booking, $status, BookingStatus::Booked)`), not every non-`booked` one. | Asked for as "refuse if not active" in one line of the brief and "throws when the booking is cancelled" in the next; the two must agree with `UnassignBookingHost`, which is explicitly allowed on a completed booking so its record can be corrected. Being able to clear a completed booking's interviewer but not set the right one is half a feature. `InvalidTransition` rather than a new exception type, per B22 — consumers already catch it from the outcome actions, and "cannot move from cancelled to booked" is the sense of the refusal. |
| B35 | A `dibs_booking_hosts` row whose host record no longer resolves is still deleted by `UnassignBookingHost`, but fires no `BookingHostUnassigned` (the event's `previousHost` is non-nullable); `AssignBookingHost` reports it as `previousHost: null`. | Same rule as B21 — a consumer-deleted host must not block the operation, and an event cannot carry a model that is gone. |
| B36 | `AssignBookingHost`'s idempotence test is "the role's rows are exactly one and it names this host". Any other shape (legacy multi-row) is replaced, with `previousHost` the earliest row's host by `id` (uuid v7 = creation order). | D14 says one host per role; the multi-row case can only be pre-D14 data, and silently keeping it would defeat the replace. |
| B37 | The overlap predicate is extracted to `OverlapCheck::overlappingSlots($slots, $start, $end)` and every caller reads it: `OverlapCheck::for/forSlot`, `HostAvailability::busyBookings/freeHosts`. The `Slot::bookable(requireFreeHost:)` filter restates it in SQL as column-to-column comparisons because it compares two slot rows, not a row to two bound instants — commented as the same rule. | "Overlaps" must have one definition (D15). The scope's version cannot call the Eloquent one without an N+1. |
| B38 | Host-availability questions ignore bookings on the slot being asked about — `freeHosts` and the `requireFreeHost` filter both exclude them. | The already-ratified R19/`forSlot` rule. Without it a capacity-N slot with a pool of one would leave `bookable(requireFreeHost: true)` the instant its own first booking landed, contradicting the capacity rule (R15). |
| B39 | `Offer::createdBy()` must be entered from a builder — `Offer::query()->createdBy($u)`, not `Offer::createdBy($u)`. | The model already has a `createdBy()` morphTo relation; a public method wins over `__callStatic`, so the static form returns the relation. Renaming the scope was rejected — the name is the one the consumer asked for, and every other scope is used on a builder anyway. Documented on the scope and in the README. |
| B40 | `HostAvailability::freeHosts` orders the pool by `id` (uuid v7 = creation order) to make "pool order" a fact rather than whatever Postgres returns. | The signature promises an order; an unordered scan does not have one. |
| B41 | The booking gate asks `HostAvailability::freeHolders(..., exclusiveHosts: false)` rather than `Slot::capacityFor()`, which honours the config. | The gate subtracts the slot's own claims by counting them (`activeBookings >= capacity`). Under `exclusive_hosts` those same claims also take their holder out of `capacityFor`, so counting both would cost a slot one appointment for every claim it already had — a three-person slot would take two. `capacityFor()` keeps the config-honouring reading, which is the right one for display and for `bookable(requireFreeHost:)`. |
| B42 | *(reverted in 0.3.4 — the four cases are back on their v0.2.0 fixtures, because a capacity-1 slot is gated by its column again and the guard is reachable without a second pool member.)* Four `HostAssignmentTest` cases were re-fixtured, not re-asserted: their pools gained a second free member (a room) so the slot still has capacity and the `guardHostOverlap` behaviour each one names is still what is on trial. | D18 makes a pooled slot with one busy holder refuse before the guard runs, so those fixtures could no longer reach the guard at all. The R18/R19 rules they exist for are unchanged; only the setup that exposes them moved. |
| B43 | The kind of a slot's capacity is a column on the **availability** (`capacity_from_pool`), not an argument to `PublishAvailability` or a field on `AvailabilityGeometry`. A pool-derived slot may still be held whole by an offer (D12's capacity-1 rule reads the column, and null is not a number above one). With `exclusive_hosts` on, a slot's own claims take their host out of `bookable(requireFreeHost:)` only when its capacity is pool-derived. | A grid is laid down again by paths that never see the original call — a geometry edit, a series regeneration, `ResumeSeries`, a duplicate — and all of them read the availability row. Anywhere else and a remade day would come back the wrong kind of time. The offer rule follows from that too: before the column could be null a series-made slot read 1 and was offerable, and an offer holds the whole time either way. And a numbered capacity is already the whole of a slot's cap, so letting its own claim also subtract a host would take a two-appointment time down to one — the B41 double-count, in the filter rather than the gate.
| B44 | An away is **reported, never enforced**: `AssignBookingHost(guardHostOverlap: true)` and `BookSlot`'s guard still refuse only a real double booking, and an away changes neither. | The first consumer's own spec says a leader may book a busy interviewer after a warning, and D15/N3 already put conflict *enforcement* out of scope. An away that refused an assignment would take that decision away from the person making it — and there would be no way to say "yes, anyway". |
| B45 | `bookable(requireFreeHost:)` reads the ground it covers (one grouped query for the contexts and the first/last instant it reaches) before it can filter on aways, and pays that query whether or not anything is away. | A standing away is a wall-clock rule with no horizon of its own — "never Sundays 1–2" has to be laid against *something* to become instants, and what the read actually reaches is the only honest something. Fetching per slot instead would have made the cost grow with the listing, which is the thing R47 exists to prevent. |
| B46 | Wall-clock aways are turned into concrete UTC spans in PHP and handed to the SQL filter as a values list, joined against the slot rows. | SQL cannot call PHP, and the alternative — evaluating each slot in PHP — would stop `bookable()` being a scope a consumer can compose and paginate. The values list grows with the horizon, not with the number of slots. |

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
Pest **256 passed / 724 assertions** after the 2026-09-01 follow-up and its remediation (context, retired, connection fix, regeneration lock). Every review finding fixed
with a mutation-verified test (`docs/plans/reviews/*.md`). Worktrees and `testing_wt_*` databases torn down.

**0.2.0 (2026-09-01, solo on `develop`)** — host availability queries, the `requireFreeHost`
filter on `Slot::bookable()`, and the two offer scopes (spec D15, R45–R48). Gate: Pint 122 files,
PHPStan L8 zero-ignore, Rector clean, Pest **298 passed / 812 assertions**. Every new assertion was
mutation-checked: dropping the same-slot exclusion, closing the half-open interval, dropping the
no-pool escape hatch, decorrelating the pool subquery, ignoring `$except`, and dropping the
slot-start ordering each turn exactly the intended test red.

## Review findings

Written to `docs/plans/reviews/<module>.md` (no GitHub remote; no PRs).
