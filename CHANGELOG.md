# Changelog

All notable changes to this package are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Behavior changes land here in the commit that makes them, not at tag time.

## [Unreleased]

## [0.3.4] - 2026-09-04

### Fixed

- **A host pool no longer decides how many appointments every time holds.**
  0.3.2 made *every* slot whose availability has a pool book against its free
  resolved holders and ignore the `capacity` column. A consumer that puts a
  candidate **list** of people on an ordinary time — the whole bishopric on a set
  of open times, one appointment per time — had those times silently become
  N-appointment times, and a deliberate, acknowledged double booking (booking
  the time anyway and assigning a host with
  `AssignBookingHost(guardHostOverlap: false)`) was refused before it could be
  made, because nobody in the pool was free. A candidate list and a
  derived-capacity time were two different things wearing one table.

  The rule is now narrower and explicit: **pool-derived capacity applies only to
  a slot whose `capacity` column is null.** A numbered capacity is the cap
  everywhere — `BookSlot`'s gate and settle step, `Support\ReleaseSlot`,
  `Slot::capacityFor()` — whatever the pool says, and `bookable(requireFreeHost:
  true)` keeps its older meaning of "at least one of the pool is free". A null
  capacity is measured by who is free, exactly as 0.3.2 described; with no pool
  behind it, it seats one.

### Added

- **`dibs_slots.capacity` is nullable** (migration
  `2024_01_01_000012_make_dibs_slots_capacity_nullable`). The default stays 1, so
  every path that does not ask for the pool rule keeps opening one-appointment
  times.
- **`dibs_availabilities.capacity_from_pool`** (boolean, default `false`;
  migration `2024_01_01_000013_add_capacity_from_pool_to_dibs_availabilities_table`)
  says a day's times are measured by its host pool. `PublishAvailability` and
  `UpdateAvailabilityGeometry` read it every time they lay a grid down — through
  `Availability::slotCapacity()` — so a day remade by a geometry edit, a series
  regeneration or a resume comes back the same kind of time; `DuplicateAvailability`
  carries it. It lives on the availability rather than in a call argument because
  none of those paths sees the original call.
- **`MaterialiseSeries` sets it on every occurrence it creates**, so a time laid
  down by a repeating rule is pool-derived: the rule says who fulfils the day, and
  how many appointments the day holds follows from how many of them are free.
- `SlotFactory::fromPool()` — a slot written with a null capacity.

### Changed

- **`exclusive_hosts` now bites only on pool-derived times.** With it on, a live
  booking on the very slot being asked about takes its host out of
  `bookable(requireFreeHost: true)` for that slot only when the slot's capacity
  comes from the pool. A numbered capacity is already the whole of that slot's
  cap, so counting its own claims twice would take a two-appointment time down to
  one.
- **An offer may hold a pool-derived time**, as it always could: `CreateOffer`
  refuses a *numbered* capacity above one, and a null column is not one. The hold
  takes the whole time, however many of the pool are free (D12 stands).
- The four `HostAssignmentTest` cases 0.3.2 re-fixtured are back on their v0.2.0
  fixtures and v0.2.0 assertions — a capacity-1 slot is gated by its column again,
  so the `guardHostOverlap` behaviour each one names is reachable without a second
  pool member propping the pool up.

### Spec

- D18 rewritten to the narrower rule; D15/§4/§5.1/§5.2/§5.4/§5.6 and ledger rows
  R25, R67, R68 amended; R84 (a number is the cap everywhere) and R85
  (`capacity_from_pool` is read by every path that lays a grid down) added. Build
  decision B43; B42 marked reverted.

## [0.3.3] - 2026-09-03

### Fixed

- **A series edit no longer deletes a day a pending offer is holding.**
  `RegenerateSeries` treated a held slot as clean, deleted the day, and
  `dibs_offer_slots` cascaded: the offer stayed `pending` with no slots, the
  invitee's link pointed at nothing, and nobody was told. A held slot is now a
  live claim exactly as a booking is — the day is left standing on its old rule
  version — and the deletion of a genuinely clean day runs through
  `DeleteAvailability`, so the held-slot refusal every other caller meets covers
  the race as well; a refusal leaves the day standing rather than cascading.
- **A released day no longer offers its old times.** When a rule moves and a day
  whose bookings are all spent is released (closed and cut loose), its remaining
  open slots are now **retired**, so the day leaves `Slot::upcoming()` as well as
  `bookable()` and a leader's list no longer shows the old times beside the
  remade day's. `PublishAvailability` cannot bring them back: it generates a grid
  only for an availability that has no slots at all, and a retired row is still a
  row. That rule is now stated in its docblock and in SPEC §5.6.
- **`DeleteSeries` counts released days.** The refusal read only the days the
  series still points at, so a rule that had plainly been used could be deleted
  once a cancellation and an edit had cut the used day loose. Release now stamps
  `meta.released_from_series` on the day and the refusal looks there too.

### Changed

- **A booked day the new hours still cover is reshaped in place** instead of
  being left behind. Regeneration would not touch a day carrying a live claim at
  all, so widening 6–8 to 6–9 left that day at 6–8, on the old `rule_version`,
  with nothing to say it had been passed over. Where the rule still opens the
  date and the block, and every booked or held time still falls inside the new
  hours, the day's window now moves through `UpdateAvailabilityGeometry` — open
  times regenerated around the claimed ones, which keep their rows and their ids
  — its pool and carried details are brought into line, and it is stamped with
  the current version. Where a claim would not survive the move, the day is left
  standing exactly as before, for the consumer to settle first.
- **`ResumeSeries` reopens only the times of days that are still published.** A
  day closed by hand, or by `RemoveOccurrenceWindow`, no longer has its retired
  times put back into `Slot::upcoming()` when the rule resumes.
- **The `HostResolver` is asked far less often, and the docs say how often.** It
  was called once per pool row per reachable availability: sixteen days times
  three pooled positions was 48 calls for one
  `bookable(requireFreeHost: true)` read, and a resolver that queries (ccstake's
  does, per calling per ward) turned a member's page into hundreds of
  statements. It is now asked once per distinct `(entry, context, availability
  date)` for the length of one read — two roles naming one position, and two
  blocks of the same day, are one question; a second date is a second question.
  Nothing is remembered across reads. README and SPEC no longer claim a flat
  query count for the resolver: the fixed handful is the package's own
  statements, and the resolver's own bound is now stated beside it and covered
  by a test with a **counting** resolver rather than the identity one.
  A resolver that answered differently for two moments on one calendar date now
  sees the first answer used for both, within a single read.
- **`UpdateSeries` refuses a context change** with `InvalidSeries`, reason
  `context.immutable`, instead of half-applying it. The context is stamped on
  every occurrence and every copy of the pool and the action rewrites neither,
  so a spec naming a different one used to leave the series in tenant B with all
  its existing days — and their pools — still in tenant A. Moving a rule between
  tenants means creating it in the new one.
- **`FindSeriesConflicts` judges "still fits" by block index**, the same
  `(occurs_on, window_index)` key regeneration works from, instead of by time
  alone. Merging 6–7 and 7:30–8:30 into one 6–9 block with an appointment at
  7:30 reported nothing, and the regeneration then remade block 0 while leaving
  the booked block 1 standing on the old rule version — two open slots at the
  same hour. Consumers will see conflicts reported for merges and splits that
  were silently accepted before; a block that keeps its index and still covers
  the booked time is not a conflict.
- **`SeriesSpec::ensureValid()` checks the timezone and the ordinals.** An
  unknown zone (`Mars/Olympus`) was stored and surfaced much later as Carbon's
  `InvalidTimeZoneException` inside the materialisation transaction; it is now
  refused up front with reason `timezone.invalid`. An ordinal outside
  {1,2,3,4,5,-1} — `0`, `6`, `-2` — was accepted and silently matched no date;
  reason `ordinals.bounds`. `SeriesSpec::ordinals()` is what `CreateSeries` and
  `UpdateSeries` store: each ordinal once, in order.
- **A window a daylight-saving jump swallows is skipped for that date.** An
  02:00–03:00 window on a spring-forward date converts to a zero-length instant,
  and `PublishAvailability` threw `InvalidGeometry`, rolling back the whole
  materialisation — every other date and block of that series — and failing
  again every night. That one date's occurrence for that one block is now
  skipped silently; the rest of the rule is laid down as normal.
- **`FollowSeries` on a paused or ended series re-attaches without
  regenerating.** Regeneration remakes nothing for a series that materialises
  nothing, so the day was deleted and never rebuilt — the action returned a
  model that was no longer in the database. It now clears `detached_at`, marks
  the day stale and stops; `ResumeSeries` regenerates (rather than only
  materialising), so the day is remade on resume.
- **A slot let go while its series is paused steps aside instead of going back
  on sale.** `PauseSeries` leaves a held slot alone — the invitee is still
  deciding — but when the offer lapsed, `ReleaseSlot` returned the slot to
  `open` and nothing consulted the series, so a paused series offered that time
  again and the sweep (which skips paused series) left it there until resume.
  Such a slot is now `retired`, which also keeps it out of `Slot::upcoming()`;
  `ResumeSeries` reopens that very row with the rest of them.
- **`ReleaseSlot` measures a pooled slot the way `BookSlot` does.** It compared
  live claims against the `capacity` column, so cancelling one of three
  appointments on a pooled slot whose column said 1 left the slot `booked` and
  the remaining free holder's hour unsellable. Capacity now has exactly one
  definition — `Support\SlotCapacity` — read by `BookSlot`'s gate and settle
  steps, by `ReleaseSlot`, and by `Slot::capacityFor()`.
- **`SweepSeries` regenerates each active series** rather than only materialising
  it. A day the last edit had to leave standing — it carried a booking, or a slot
  an offer was holding — is caught up on the first sweep after the claim is
  settled, without anybody editing the rule a second time. A series with nothing
  stale pays one extra query.
- **`ResumeSeries`'s `$through` is optional**: `ResumeSeries(Series $series,
  ?CarbonImmutable $through = null)`. Omitted, it derives the same horizon
  `RegenerateSeries` and `SweepSeries` derive (`max_horizon_days`, 90 days when
  the series names none), so the three verbs cannot disagree about how far ahead a
  series is open. Existing calls that pass a date are unchanged.

### Added

- **`Actions\RemoveOccurrenceWindow(Availability): Availability`** — "this block
  does not happen on this date", made to stick. It closes the day, retires its
  unclaimed times and detaches it, which keeps the occurrence key occupied;
  deleting the day would have freed the key and the next sweep would simply have
  laid it down again. Appointments already made stand — closing is not
  cancelling (D6). `FollowSeries` is the way back.
- `Models\Series::blocks()` — the rule's windows grouped by weekday in clock
  order, where a block's position **is** its `window_index`. Materialisation and
  regeneration now read the same method instead of grouping separately.
- `Support\HostResolution` — the memo that holds the resolver's call volume down,
  and the honest statement of it.
- `InvalidSeries` reasons `timezone.invalid`, `ordinals.bounds` and
  `context.immutable`.
- `RegenerateSeries(Series $series, ?CarbonImmutable $through = null)` — the
  second argument is new and optional; omitted, the horizon is derived as
  before. `ResumeSeries` passes the date its caller asked for.
- `Support\SeriesClock::date()` — the calendar-date reading `Series` used to do
  with a `shiftTimezone` call of its own, so D10's "one file reads a clock" is
  literally true again.
- `Support\SlotCapacity` — the one definition of how many appointments a slot
  can take, with `forClaim()` (the booking/release reading, exclusive hosts
  forced off) and `of()` (the reporting reading `Slot::capacityFor()` takes).
- `Support\SlotStatusSweep` — the one lock-then-retire/reopen helper, carrying the
  READ COMMITTED rationale once, used by `PauseSeries`, `ResumeSeries`,
  `SweepSeries` and the released-day retirement.

## [0.3.2] - 2026-09-03

### Changed

- **A pooled slot's capacity is who is free** (spec D18). `BookSlot` now gates a
  slot whose availability has a host pool on `Slot::capacityFor()` — the people
  the pool resolves to with nothing booked across the slot elsewhere — and no
  longer on the `capacity` column, which from here on decides only slots with no
  pool. Three free interviewers at six o'clock are three appointments at six
  o'clock however the column reads; a pool that resolves to nobody, or whose
  every member is booked across the slot, refuses the first claim. The slot's
  `booked` status settles against the same number.

  Two consequences for consumers that pool hosts: a capacity-N pooled slot no
  longer takes N claims unless N people are free for it, and a pooled slot whose
  only holder is double-booked is now refused with `SlotUnavailable` before the
  opt-in `guardHostOverlap` check can report `HostOverlap`. Slots with no pool —
  adhoc slots and availabilities nobody was pooled on — are unchanged.

### Added

- **`exclusive_hosts` config key** (default `false`), the second half of D18:
  with it on, a live booking on the very slot being asked about makes its host
  busy for that slot, so a host with a claim on it stops counting towards its
  capacity, drops out of `HostAvailability::freeHolders` and
  `Slot::bookable(requireFreeHost: true)`, and is refused by
  `AssignBookingHost(guardHostOverlap: true)`. Off is the behaviour the package
  always had — one host may seat several attendees in one session; on is what a
  one-to-one appointment needs. It is read in one place,
  `Support\OverlapCheck::hostsAreExclusive()`, so every reading of "busy"
  honours it.
- `HostAvailability::freeHolders()` takes a fourth argument,
  `?bool $exclusiveHosts = null`, overriding the config for one question. The
  booking gate asks with it `false`, because it subtracts the slot's own claims
  by counting them and must not subtract them twice.

## [0.3.1] - 2026-09-03

### Changed

- **`Contracts\HostResolver::resolve()` takes the context it is being asked
  about**: `resolve(Model $host, CarbonInterface $at, ?Model $context = null)`,
  where `$context` is the availability's own context (the tenant/organisation
  morph). A pooled *position* is often a catalog row several tenants share, so
  its holders cannot be named without knowing which tenant is asking.
  `HostAvailability::freeHosts`/`freeHolders`, `Slot::capacityFor()` and
  `Slot::scopeBookable(requireFreeHost:)` all pass it. The parameter is optional
  and `IdentityHostResolver` ignores it, so the default binding is unchanged; a
  consumer with its own resolver must widen the signature.

## [0.3.0] - 2026-09-03

### Added

- **Series** — a repeating rule that materialises into ordinary availabilities,
  reversing spec D5/N1's "no recurrence". `dibs_series` (title unique per
  context, case-insensitively; timezone; cadence + ordinals; start/end dates;
  duration, padding, notice, horizon, location; status; rule_version; meta),
  `dibs_series_windows` (weekday + minutes from local midnight, several rows per
  weekday for several blocks a day) and `dibs_series_hosts` (the pool each
  occurrence is given a copy of). `dibs_availabilities` gains `series_id`
  (nulled, not cascaded, when the series goes - its bookings are history),
  `occurs_on`, `window_index`, `rule_version` and `detached_at`, with a partial
  unique index on `(series_id, occurs_on, window_index)`.
- `Enums\Cadence` (`weekly`, `fortnightly`, `monthly-ordinal`, `once`) and
  `Enums\SeriesStatus` (`active`, `paused`, `ended`).
- Models `Series`, `SeriesWindow`, `SeriesHost` (all substitutable through
  `config('dibs.models')`), with factories. `Series::occursOn($localDate)` and
  `Series::occurrenceDates($from, $through)` answer the calendar: Sunday-based
  week indices counted from the week containing `starts_on`, ordinals applied to
  every weekday the rule has, `-1` meaning the last of that weekday in the
  month, and a month with no fifth simply yielding nothing.
- `Availability::series()`, `Availability::detached()` and
  `Availability::isDetached()`.
- `Data\SeriesSpec` and `Data\WindowSpec` — a whole rule as the caller means
  it. `ensureValid()` enforces only what is true of any consumer's series (at
  least one window and one host, an end after the start, ordinals on the monthly
  cadence and nowhere else, windows inside their day, and windows sharing a
  weekday far enough apart for one whole appointment to fit between them) and
  throws `Exceptions\InvalidSeries`, which carries a machine `reason` -
  `windows.overlap`, `windows.gap`, `windows.bounds`, `ends_before_starts`,
  `ordinals.required`, `ordinals.forbidden`, `windows.required`,
  `hosts.required` - so the consumer writes the sentence a person reads.
- `Contracts\HostResolver` and its default `Support\IdentityHostResolver`,
  bound in the service provider. A pool entry need not be a person: a consumer
  may pool a position and mean "whoever holds it then". Empty means vacant.
- `Slot::capacityFor($now = null)` - how many appointments a slot can really
  take: the people its pool resolves to who have nothing else booked across it.
  A slot with no pool falls back to its `capacity` column; a pool that resolves
  to nobody gives 0.
- `HostAvailability::freeHolders($availability, $slot, $at = null)` - the
  role-agnostic reading of `freeHosts`, used by capacity.
- `Actions\CreateSeries(SeriesSpec): Series` - records the rule at version 1
  and materialises nothing; the consumer says how far ahead to open times.
- `Actions\MaterialiseSeries(Series, CarbonImmutable $through): int` - lays the
  rule down as ordinary published availabilities from today to `$through`, one
  per window per matching date, each with its own copy of the pool. Idempotent:
  an occurrence is keyed `(series_id, occurs_on, window_index)` and a key that
  already has a row is skipped, so a second run creates nothing and a booked,
  detached or hand-left day is never remade. Dates before today are never
  reached. Materialises nothing for a paused or ended series. Fires
  `Events\SeriesMaterialised` (with the occurrences created) after commit.
- `Actions\UpdateSeries(Series, SeriesSpec): Series` - an edit that moves the
  rule (windows, cadence, ordinals, dates, duration, padding, place, pool,
  timezone) bumps `rule_version` and regenerates; an edit that only changes what
  a day carries (title, meta, notice, horizon) is copied straight onto the
  future days that follow the series, with no version bump and nobody's booking
  disturbed.
- `Actions\RegenerateSeries(Series): int` - remakes every future, following day
  still stamped with an older rule version, then materialises out to
  `max_horizon_days` (90 days when the series names none). A day carrying a live
  booking is left standing for the consumer to settle. A day whose bookings are
  all spent cannot be deleted (D3) and is **released** instead - closed and cut
  loose from the series - so its record stands and the date is free for the new
  rule.
- `Actions\FindSeriesConflicts(Series, SeriesSpec): Collection<Booking>` - the
  live future bookings a proposed rule would strand, as a pure read, so the
  consumer can ask a person before cancelling on their behalf. A shorter horizon
  is not a conflict; past and detached days are ignored.
- `Actions\ReparentSlotAsAdhoc(Slot): Slot` - cuts a booked slot loose from its
  day, keeping the booking, its hosts, its time and its place (copied down from
  the day when the slot had none), so the day can be remade around it.
- `Actions\PauseSeries(Series): Series` - retires every unclaimed time still
  ahead, including on days somebody detached (a paused series offers nothing),
  and leaves booked ones alone.
- `Actions\ResumeSeries(Series, CarbonImmutable $through): Series` - reopens
  exactly those rows rather than remaking the days, so nothing can be
  duplicated, then materialises the dates that came due while it was paused.
- `Actions\DeleteSeries(Series): void` - refused (`DeletionRefused`) if any of
  its days ever carried a booking, cancelled ones included; otherwise the rule,
  its blocks, its pool and its days all go, each day through
  `DeleteAvailability` so a held slot still refuses in the words it always did.
- `Actions\DetachOccurrence(Availability): Availability` and
  `Actions\FollowSeries(Availability): Availability` - take one day out of the
  rule's hands and put it back. Following marks the day as being on an older
  rule version and lets `RegenerateSeries` do the rest, so there is one code
  path that remakes a day.
- `Actions\SweepSeries(?CarbonInterface $now = null): int` - the nightly job a
  consumer schedules (the package ships no commands, as with `ExpireOffers`):
  rolls every open series forward to its horizon, retires the unclaimed times
  that have passed, and ends a series whose last date has gone by on its own
  calendar. One series failing does not stop the sweep.
- Events `SeriesPaused`, `SeriesResumed`, `SeriesDeleted`.
- `Support\SeriesClock` - the one place the package reads a wall clock.
- The window-to-instant conversion in `Support\SeriesClock` is the single
  sanctioned exception to "UTC instants only" (spec D10): a wall-clock window
  needs the series' timezone to know which instant it is on a given date, and
  6 pm has to stay 6 pm across a daylight-saving change. `MaterialiseSeries`
  uses it to place occurrences and `FindSeriesConflicts` to ask the same
  question backwards. Everything written is still a UTC instant.

### Changed

- Spec **D5** and non-goal **N1** are reversed: recurrence is in, as a materialised
  series. RRULE strings specifically stay out - the cadence is an enum plus
  ordinals, not a parser. Spec **D10** gains its one exception, `SeriesClock`.
- `HostAvailability::freeHosts()` and `Slot::bookable(requireFreeHost: true)`
  now put each pool entry through the bound `HostResolver` before asking who is
  busy, and count a person two entries stand for once. With the default identity
  resolver both answer exactly as they did. `bookable(requireFreeHost: true)` is
  no longer a single statement - the pool is resolved in PHP first - but the
  number of queries is fixed and does not grow with the number of slots.

### Upgrading

Additive: four new migrations, no existing column changed, no existing behaviour
changed with the default resolver bound. Run `php artisan migrate` (or republish
the migrations if you took ownership of them). A consumer that pools positions
rather than people binds its own `HostResolver`; everyone else does nothing.

## [0.2.0] - 2026-09-01

### Added

- `Support\HostAvailability` — the read side of the booking-time overlap guard.
  `busyBookings($host, $start, $end, $except = null)` returns the host's active
  bookings in any role whose slot overlaps `[$start, $end)`, earliest slot first,
  with one booking optionally excluded; `isFree(...)` is the same question as a
  bool; `freeHosts($availability, $slot, $role = 'host')` returns the pool
  members with nothing else booked across the slot, as the consumer's own host
  models, in pool order. None of them picks a host — that stays the consumer's
  (spec D8/D15).
- `Slot::bookable($now, requireFreeHost: true)` — additionally drops a slot whose
  availability has a host pool and none of whose pool is free across it: what a
  member may be offered, as against what a leader may book into. An availability
  with no host pool is never excluded, and it stays one SQL statement however
  many slots are asked about.
- `Offer::pendingFor($party)` (pending, unexpired, offered to that party) and
  `Offer::createdBy($party)` scopes. `createdBy` must be entered from a builder
  (`Offer::query()->createdBy($party)`); the model's relation of the same name
  wins the static call.

### Changed

- `OverlapCheck::query()` is public, and the half-open overlap predicate it used
  inline is now `OverlapCheck::overlappingSlots()` — one definition of "overlaps"
  for every Eloquent caller. No behaviour change: a booking ending exactly when
  another starts still does not conflict.
- `Slot::bookable()` called without the new argument is unchanged.

## [0.1.2] - 2026-09-01

### Added

- `AssignBookingHost(booking, host, role = 'host', guardHostOverlap = false)` —
  changes who fulfils a booking after it exists: a pool member takes an
  unassigned booking, an administrator reassigns one. Decided from the booking's
  locked row; **replaces** the role's assignment (one host per role); assigning
  the host who already holds the role writes nothing and fires nothing; the
  optional overlap guard runs the same `OverlapCheck` the booking path uses and
  throws `HostOverlap` before anything is written. Fires `BookingHostAssigned`
  (with the displaced host, if any) after commit.
- `UnassignBookingHost(booking, role = 'host')` — clears the role's assignment
  from the locked row, firing `BookingHostUnassigned` per host removed. No rows
  for the role is a no-op, with no event.
- Events `BookingHostAssigned` and `BookingHostUnassigned`.
- Both actions refuse a **cancelled** booking (`InvalidTransition`) and allow a
  completed or no-show one, whose record may still be corrected (spec D14).

## [0.1.1] - 2026-09-01

### Fixed

- `AcceptOffer` stamped the booking's context from the offer's tenant *model*;
  if that row had been deleted while the offer was pending, the booking silently
  inherited the availability's context (or none). The offer's stored
  `context_type`/`context_id` pair is now passed through verbatim.

### Added

- `BookingOptions` accepts an already-stored scope as `contextType`/`contextId`
  (takes precedence over the `context` model); `BookingOptions::contextPair()`.

## [0.1.0] - 2026-09-01

First release: the v1 spec (`docs/SPEC.md`) built requirement-by-requirement.
Supports Laravel 12 and 13 on PHP 8.2+ with PostgreSQL 18+.

### Added

- Schema: seven `dibs_*` tables (availabilities, slots, availability_hosts,
  bookings, booking_hosts, offers, offer_slots) with database-generated uuid v7
  keys, `timestamptz` instants, `jsonb` meta, a partial unique index preventing
  two live claims by one party on one slot, and `restrictOnDelete` on
  `bookings.slot_id` so a slot with history can never be deleted.
- Models `Availability`, `Slot`, `AvailabilityHost`, `Booking`, `BookingHost`,
  `Offer`, `OfferSlot` — extendable, substituted through the `dibs.models`
  class-map — with query scopes `Availability::published()`,
  `Slot::bookable()` / `upcoming()`, `Booking::active()` / `upcoming()`,
  `Offer::pending()`.
- Status enums with their transition rules, the ten domain events, and
  factories with a state per status.
- Availability lifecycle actions: `PublishAvailability`, `CloseAvailability`,
  `UpdateAvailabilityGeometry`, `DuplicateAvailability`, `DeleteAvailability`.
- `Support\SlotGrid` — the slot positions an `AvailabilityGeometry` describes
  (duration + padding, trailing remainder unused).
- Booking actions: `BookSlot` (row-locked, capacity-aware, auto-assigns a
  pool of one per role, optional host-overlap guard), `CreateDirectBooking`,
  `CancelBooking` (releases the slot per the origin rule), `CompleteBooking`,
  `MarkNoShow`.
- `OverlapCheck::for()` — a host's overlapping active bookings, as public API;
  `HostAssignment` data object for supplying hosts to a direct booking;
  `ReleaseSlot` — the origin rule (availability-born → open, unbooked adhoc → deleted).
- Offer actions: `CreateOffer` (hold existing open capacity-1 slots and/or
  create adhoc ones as held, behind a unique token of at least 40 characters;
  all-or-nothing), `AcceptOffer` (book the invitee's chosen slot on the offer
  path — a since-closed availability and the notice/horizon window are waived —
  and release the losers per the origin rule), `WithdrawOffer`, and the
  idempotent `ExpireOffers` sweep for a consumer's scheduler (one
  `OfferExpired` per offer, each in its own transaction).
- `Dibs::lock(Model)` — a `FOR UPDATE` re-read through the class-map; every
  state transition in the package decides from a locked row, never a snapshot.
- `OverlapCheck::forSlot(host, slot)` — the overlap question with that slot
  itself excluded (what the booking guard uses); `OverlapCheck::for()` is unchanged.
- `CreateOffer` and `CreateDirectBooking` reject an inverted, zero-length or
  already-past adhoc window, and `CreateOffer` rejects an expiry at or before
  now, with `InvalidArgumentException` before anything is written.
- `ExpireOffers` sweeps longest-overdue first, keeps going when one offer
  cannot be settled (e.g. someone is accepting it), and rethrows that first
  failure once the sweep has finished.
- Repeated `HostAssignment`s fold to one assignment; a slot named twice in
  one offer is held once; existing slots are locked in key order.
- `DuplicateAvailability` copies every column of the source (a consumer
  subclass's extra columns travel with the copy).

- Tenancy: `context_type`/`context_id` on bookings (copied from the
  availability at booking time, or supplied for a direct booking) and on offers
  (supplied), with `forContext($model)` scopes on `Availability`, `Booking` and
  `Offer`.
- Slot state `retired`: a grid regeneration retires a displaced open slot whose
  bookings are all history (cancelled / completed / no-show) — history kept, out
  of `bookable()` and `upcoming()`, position reused — instead of leaving it
  bookable off-pattern. A partly-full slot with a live claim survives untouched
  and keeps its position. `Slot::retired()` scope; `ReleaseSlot` treats retired
  as terminal.
- `DeleteAvailability` locks the availability row and its slots before deciding,
  and both it and `UpdateAvailabilityGeometry` issue every slot statement on the
  transaction's connection even when handed a model pinned to another one.

### Fixed

- A geometry edit racing a booking could retire a slot that had just been
  claimed and lay a duplicate open slot on its position; regeneration now locks
  the availability's slots before changing any of them.

### Changed

- Regeneration only retires slots the new grid actually displaces: an open slot
  that exactly matches a generated position keeps its row, id and status, so
  resubmitting an unchanged geometry is a no-op.

- `PublishAvailability`, `CloseAvailability`, `UpdateAvailabilityGeometry`,
  `CancelBooking`, `CompleteBooking` and `MarkNoShow` return the freshly
  locked model instance rather than mutating the one passed in.
- A capacity-N slot with `guardHostOverlap: true` seats a second party under
  the same host instead of throwing `HostOverlap`.
