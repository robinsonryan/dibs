# Dibs — headless booking engine (package spec, v1)

**Package:** `robinsonryan/dibs` · **Namespace:** `RobinsonRyan\Dibs` · **Status:** v1 released as 0.1.0 / 0.1.1 / 0.1.2 / 0.2.0 (2026-09-01); work continues on `develop`
**First consumer:** ccstake (bishopric interviews, tithing settlement, calling-extension meetings). The
ccstake integration gets its **own spec in the ccstake repo**; §10 here is informative only.

## 1. Purpose & positioning

A headless Laravel package for slot-based scheduling: someone publishes bookable time, someone claims a
slot. The domain has a crisp waistline — everything **below** it (slots, holds, claims, releases,
assignment) is identical in every project and lives here; everything **above** it (who may publish, who
may see, what a meeting is *for*, how people are notified) belongs to the consumer app.

The package therefore ships: migrations, models, actions, events, exceptions, query scopes. It ships
**no** UI, **no** routes, **no** authorization, **no** notifications, **no** timezone display logic
(§8 non-goals). It fires events; consumers decide.

Same silhouette as `robinsonryan/taxon` (the reference package): headless library, Testbench + Pest on
real PostgreSQL, PHPStan level 8 zero-ignore, `composer quality` gate.

## 2. Lexicon (canonical — signed off 2026-08-31)

| Concept | Name | States / notes |
|---|---|---|
| Published window of bookable time | **Availability** | `draft`, `published`, `closed` |
| One bookable time | **Slot** | `open`, `held`, `booked`, `retired` (history only: displaced by a grid regeneration after its booking was cancelled; never bookable or upcoming) |
| Slot provenance | **origin** (derived, not stored) | `availability` (has `availability_id`), `adhoc` (null) |
| Who fulfills a booking | **Host** (polymorphic; pool attaches to an Availability, assignment to a Booking, each with a `role`) | — |
| The claim on a slot | **Booking** | `booked`, `completed`, `cancelled`, `no_show` |
| Tokenized multi-slot invitation | **Offer** | `pending`, `accepted`, `expired`, `withdrawn` |
| Booking subject / submitter | **bookedFor** / **bookedBy** (polymorphic; equal unless booked on someone's behalf) | — |

Naming rationale, preserved so nobody re-litigates: "Schedule" collides with
`Illuminate\Console\Scheduling\Schedule`; "Session" with HTTP sessions; "bookee"/"attendee" rejected
for the `bookedFor`/`bookedBy` relationship pair.

Added 2026-09-03 with D16: a **Series** is a repeating rule; a **Window** is one stretch
of hours on one weekday within it; an **occurrence** is an `Availability` a series made;
**materialising** is turning the rule into occurrences; a **detached** occurrence is one
somebody edited by hand, which the rule no longer manages; **releasing** an occurrence is
cutting it loose from its series when history stops it being deleted.

## 3. Decisions (agreed in design discussion)

| ID | Decision |
|---|---|
| D1 | Buffers are **Availability parameters**, not slot concepts: slot padding (gap between consecutive slots), minimum booking notice, maximum booking horizon. Slots stay bare start/end rows. |
| D2 | No `expired` state on Slot — a past open slot is derivable from the clock; scopes handle it. States never duplicate what a timestamp already says. |
| D3 | **Origin rule.** A slot released from a hold (offer not chosen, or booking cancelled) reverts to `open` if availability-born; an adhoc slot with **zero** bookings is deleted. A slot with any booking row (even cancelled) is never deleted — bookings are history. |
| D4 | Direct appointments are **not a second code path**: "create appointment" = create an adhoc slot and book it in one transaction. |
| D5 | ~~No recurrence. "Every Tuesday" is a **duplicate-availability** action, not a rule engine.~~ **Reversed 2026-09-03 (D16).** Recurrence is in, as a *materialised* series: a `Series` is the rule and it lays down ordinary availabilities. `DuplicateAvailability` stands as the one-off copy it always was. |
| D6 | Editing a published Availability's geometry regenerates **open** slots only; `booked`/`held` slots are never touched, even if they fall outside the new window. Deleting an Availability is **refused** while any slot is held or has bookings. Silent bulk-cancellation does not exist. |
| D7 | Host assignment is many-to-many with a `role` string (`interviewer`, `room`, `driver`, …) — records multi-resource combos (doctor + room) without a breaking migration later. |
| D8 | **Joint-availability computation is a non-goal.** The publisher declares the resource combination; the package never solves for one. |
| D9 | Auto-assign: at booking time, for each role in the availability's pool, a pool of exactly one host is assigned automatically. Larger pools stay unassigned until a consumer assigns. |
| D10 | The package is **timezone-agnostic**: all times are UTC instants (`timestamptz`). Notice/horizon are duration comparisons against `now()`. Wall-clock parsing/display is the consumer's job at the boundary. **One exception, added 2026-09-03 with D16**: a series' windows are wall clock (minutes from local midnight) and only the series' own `timezone` can say which instant "6 pm" is on a given date — a fixed offset cannot keep 6 pm at 6 pm across a daylight-saving change. That conversion lives in `Support\SeriesClock` and nowhere else; `MaterialiseSeries` uses it to place occurrences and `FindSeriesConflicts` to ask the same question backwards. Everything either writes or compares is still a UTC instant. |
| D11 | An outstanding Offer is a promise: accepting it works even if the Availability has since been closed, and notice/horizon checks do **not** apply to offer acceptance (the person was invited explicitly). |
| D12 | Offers hold capacity-1 slots only in v1 (holding one unit of a capacity-N slot is deferred). |
| D13 | Booking `type` is a consumer-defined string, denormalized onto the booking at creation (default: the availability's `type`) so bookings survive availability edits. |
| D14 | **Host assignment is mutable after booking, one host per role.** Auto-assign (D9) is a convenience, not a commitment: a booking's host for a given role can be set, replaced or cleared afterwards by `AssignBookingHost` / `UnassignBookingHost`, so a pool member can take an unassigned booking and an administrator can reassign one. Assigning is a **replace**, never an add — a role holds at most one host on a booking (the many-to-many of D7 is across roles, not within one). Consumers never write `dibs_booking_hosts` rows themselves. A cancelled booking is frozen; a completed or no-show one may still have its record corrected (added 2026-09-01). |
| D15 | **Host availability is a query, never a solver** (D8 stands). The package answers three questions about a host's time and no others: is this host busy in a window, which of an availability's pool is free during a slot, and which slots have nobody free. All three read the same overlap predicate as the R18 booking-time guard — half-open `[starts_at, ends_at)`, so a booking that ends exactly when another starts does not conflict — and all three ignore bookings **on the slot being asked about**, because one host seating two attendees in a shared capacity-N slot is not double-booked with themselves (the R19 rule) — unless `exclusive_hosts` is on, when they count (D18). The free-host filter on `Slot::bookable()` is **opt-in and role-agnostic**: a slot is excluded only when its availability has a host pool and no member of that pool is free; an availability with no pool is never excluded, because there is nobody to be busy (added 2026-09-01). |
| D16 | **Recurrence is a materialised series** (2026-09-03, reversing D5/N1). A `Series` holds the rule — weekday windows as wall-clock minutes, a cadence (`weekly` / `fortnightly` / `monthly-ordinal` / `once`) with Sunday-based week indices counted from the week containing `starts_on`, a date range, geometry, place, and a pool — and `MaterialiseSeries` lays it down as ordinary `Availability` rows, one per window per matching date. Nothing else in the package learns what a series is: booking, offers, the overlap guard, geometry edits and deletion all keep working on rows. An occurrence is keyed `(series_id, occurs_on, window_index)`, which is what makes materialisation idempotent without a diff. Edits bump `rule_version` and regenerate rather than diffing, and regeneration refuses three things: the past, a **detached** occurrence (`detached_at`, hand-edited on purpose), and one carrying a **live booking** (the consumer settles those first, via `FindSeriesConflicts` and then cancellation or `ReparentSlotAsAdhoc`). An occurrence whose bookings are all *spent* cannot be deleted (D3) and is **released** instead — closed and cut loose from the series (`series_id` nulled) — so its history stands and its date is free. |
| D17 | **A pool entry stands for people, at a moment** (2026-09-03). `Contracts\HostResolver` turns one pool entry into the models it represents at a stated instant, in a stated context: none (vacant), one, or several (a position with more than one seat). The context is the availability's own (`resolve(Model $host, CarbonInterface $at, ?Model $context = null)`, added 0.3.1) because a position is often a catalog row several tenants share, and who holds it cannot be answered without knowing which tenant is asking. It exists so a consumer can pool a *position* — a calling, a rota, a desk — and have a set of times opened months ahead survive the holder changing. The default binding is identity, so a consumer that pools people sees no change. It is consulted by `HostAvailability::freeHosts`/`freeHolders`, `Slot::bookable(requireFreeHost:)` and `Slot::capacityFor()`; it is **not** consulted by `busyBookings`/`isFree`, which ask about a host that is already concrete, nor by assignment, which stays the consumer's (D8/D9). A pool that resolves to nobody is vacant: zero capacity, and no bookable slot. |
| D18 | **A pooled slot's capacity is who is free** (2026-09-03). `BookSlot` gates a slot whose availability has a pool on the people that pool resolves to (D17) with nothing booked across it elsewhere — `Slot::capacityFor()` — and not on the `capacity` column, which from here on decides only slots with no pool behind them. Three free interviewers at six o'clock are three appointments at six o'clock however the column reads; a pool that resolves to nobody, or whose every member is booked across the slot, refuses the first claim. The gate asks the question with `exclusive_hosts` off whatever the config says, because it already subtracts the slot's own claims by counting them. **`exclusive_hosts`** (`config/dibs.php`, default `false`) is the second half: with it on, a live booking on the very slot being asked about *does* make its host busy for that slot, so a host with one claim on it stops counting towards its capacity, drops out of `freeHolders` and `bookable(requireFreeHost:)`, and is refused by `AssignBookingHost(guardHostOverlap: true)`. Off is the R19 rule the package always had (one host, several attendees, one session); on is what a one-to-one appointment needs — an interview cannot be shared. The flag lives in one place, `Support\OverlapCheck::hostsAreExclusive()`, so every reading of "busy" honours it. |

## 4. Data model

All tables: uuid v7 primary keys **generated by the database** (`DB::raw('uuidv7()')` — requires
PostgreSQL 18, per fleet data doctrine), `timestampTz` for all instants, `jsonb` meta with `'{}'::jsonb`
default. Table prefix `dibs_`. Models `final` (extension via config class-map, §7). Polymorphic columns
respect the consumer's morph map; the package never assumes FQCNs.

### `dibs_availabilities`
| Column | Type | Notes |
|---|---|---|
| `id` | uuid pk | uuidv7() |
| `context_type` / `context_id` | nullableMorphs | the owning scope (ccstake: a ward Organization); nullable for single-tenant consumers |
| `type` | string nullable, indexed | consumer vocabulary (`temple-recommend`, …) |
| `name` | string nullable | label ("Tithing Settlement — Dec 6") |
| `location` | string nullable | free text |
| `starts_at` / `ends_at` | timestampTz | the window, UTC instants |
| `slot_duration_minutes` | unsigned smallint | required, ≥ 1 |
| `slot_padding_minutes` | unsigned smallint | default 0 |
| `min_notice_minutes` | unsigned integer nullable | null = no minimum |
| `max_horizon_days` | unsigned smallint nullable | null = no horizon |
| `status` | string | `draft` / `published` / `closed` |
| `meta` | jsonb | consumer payload |
| `series_id` | fk nullable → series, **nullOnDelete** | set by materialisation; nulled rather than cascaded, because an occurrence that carried bookings is history and outlives its rule (D16) |
| `occurs_on` | date nullable | the local date the rule opened |
| `window_index` | smallint nullable | which block of that weekday, 0-based by clock order |
| `rule_version` | unsigned int nullable | the version of the rule this occurrence was made from |
| `detached_at` | timestamptz nullable | hand-edited: regeneration and materialisation leave it alone |
| timestamps | | |

Partial unique index `(series_id, occurs_on, window_index) where series_id is not null` —
the key that makes materialisation idempotent. Index `(series_id, occurs_on)`.

### `dibs_slots`
| Column | Type | Notes |
|---|---|---|
| `id` | uuid pk | |
| `availability_id` | fk nullable → availabilities, cascadeOnDelete | null = adhoc |
| `starts_at` / `ends_at` | timestampTz | |
| `location` | string nullable | overrides availability's; required source of truth for adhoc slots |
| `capacity` | unsigned smallint | default 1; decides slots with no host pool (D18) |
| `status` | string | `open` / `held` / `booked` (`booked` = full) / `retired` |
| timestamps | | |

Indexes: `(availability_id, status)`, `(starts_at)`.

### `dibs_availability_hosts` (the pool)
`id`, `availability_id` fk cascadeOnDelete, `host_type`/`host_id` morphs, `role` string default
`host`, timestamps. Unique `(availability_id, host_type, host_id, role)`.

### `dibs_series` (D16)
| Column | Type | Notes |
|---|---|---|
| `id` | uuid pk | |
| `context_type` / `context_id` | nullable morph | the owning scope, as on availabilities |
| `title` | string | unique per context, case-insensitively (expression index on `lower(title)`) |
| `timezone` | string | IANA zone; the clock the windows are written in (D10's exception) |
| `cadence` | string | `weekly` / `fortnightly` / `monthly-ordinal` / `once` |
| `ordinals` | jsonb | `int[]` ⊂ {1..5, -1}; `[]` unless the cadence is monthly-ordinal |
| `starts_on` / `ends_on` | date / date nullable | local dates; null end = no end |
| `slot_duration_minutes`, `slot_padding_minutes`, `min_notice_minutes`, `max_horizon_days`, `location` | as on availabilities | copied onto each occurrence |
| `status` | string | `active` / `paused` / `ended` |
| `rule_version` | unsigned int | bumped by any edit that changes which times exist; the reason regeneration never diffs |
| `meta` | jsonb | consumer payload, copied onto each occurrence |
| timestamps | | |

### `dibs_series_windows`
`id`, `series_id` fk cascadeOnDelete, `weekday` smallint (0 = Sunday … 6), `starts_at_minutes`
/ `ends_at_minutes` smallint (minutes from local midnight), timestamps. Check constraint
`ends_at_minutes > starts_at_minutes`; index `(series_id, weekday)`. Several rows on one
weekday are several blocks that day; the rule that they must leave room for one whole
appointment between them lives in `SeriesSpec`, not the schema.

### `dibs_series_hosts`
`id`, `series_id` fk cascadeOnDelete, `host_type`/`host_id` morphs, `role` string default
`host`, timestamps. Unique `(series_id, host_type, host_id, role)`. Copied onto each
occurrence at materialisation, so a pool change is a rule change and a booked occurrence
keeps the pool it was booked under.

### `dibs_bookings`
| Column | Type | Notes |
|---|---|---|
| `id` | uuid pk | |
| `slot_id` | fk → slots, **restrictOnDelete** | the FK is what enforces D3's "never delete a slot with bookings" |
| `context_type` / `context_id` | nullableMorphs, indexed | the owning scope, **copied from the availability at creation** (or supplied for a direct booking) — D13 applied to tenancy, so every booking answers "whose is this?" without a join (ruled 2026-09-01) |
| `booked_for_type` / `booked_for_id` | morphs | the subject |
| `booked_by_type` / `booked_by_id` | morphs | the submitter |
| `type` | string nullable, indexed | D13 |
| `status` | string | `booked` / `completed` / `cancelled` / `no_show` |
| `cancelled_at` | timestampTz nullable | |
| `cancelled_by_type` / `cancelled_by_id` | nullableMorphs | |
| `meta` | jsonb | |
| timestamps | | |

Partial unique index: `(slot_id, booked_for_type, booked_for_id) WHERE status = 'booked'` — the same
person cannot hold two live claims on one slot.

### `dibs_booking_hosts` (assignment)
`id`, `booking_id` fk cascadeOnDelete, `host_type`/`host_id` morphs, `role` string, timestamps.
Unique `(booking_id, host_type, host_id, role)`.

### `dibs_offers`
| Column | Type | Notes |
|---|---|---|
| `id` | uuid pk | |
| `token` | string unique | ≥ 40 chars, `Str::random`; the only lookup key a link carries |
| `context_type` / `context_id` | nullableMorphs, indexed | the owning scope, supplied at creation (an all-adhoc offer has no availability to inherit one from) |
| `offered_to_type` / `offered_to_id` | morphs | only this party may accept |
| `created_by_type` / `created_by_id` | nullableMorphs | |
| `expires_at` | timestampTz nullable | |
| `status` | string | `pending` / `accepted` / `expired` / `withdrawn` |
| `accepted_booking_id` | fk nullable → bookings, nullOnDelete | |
| `message` | text nullable | free text the creator writes to the invitee |
| `meta` | jsonb | |
| timestamps | | |

### `dibs_offer_slots`
`id`, `offer_id` fk cascadeOnDelete, `slot_id` fk cascadeOnDelete, timestamps. Unique
`(offer_id, slot_id)`.

## 5. Behaviors

All verbs are `final` invokable action classes under `RobinsonRyan\Dibs\Actions`, each transactional,
each firing its event **after commit** (`DB::afterCommit`). Invalid state transitions throw
`InvalidTransition`; all package exceptions extend a common `DibsException`.

### 5.1 Availability lifecycle
- **PublishAvailability**: `draft → published`; materializes slots. Generation: from `starts_at`, place
  a slot of `slot_duration_minutes`, advance by duration + padding, while slot end ≤ `ends_at`. Partial
  trailing time is unused. Idempotent (re-publish regenerates nothing if slots exist).
- **Geometry edit after publish** (window/duration/padding change): delete every `open` slot with no
  booking rows; an `open` slot that carries a (cancelled) booking cannot be deleted (D3) and becomes
  `retired` — history only, out of every listing, its grid position free; regenerate the grid, skipping
  any position that overlaps a surviving `booked`/`held` slot (D6). Ruled 2026-09-01.
- **CloseAvailability**: `published → closed`. Open slots remain rows but leave the `bookable` scope.
  Reopening (`closed → published`) is allowed.
- **DuplicateAvailability**: copy geometry, type, name, location, pool — into a new `draft` at a
  caller-supplied window.
- **Deletion**: refused (`DeletionRefused`) while any slot is `held` or has bookings (D6). Otherwise
  slots cascade.

### 5.2 Booking
- **BookSlot(slot, bookedFor, bookedBy, options)** — the concurrency-critical path:
  1. `SELECT … FOR UPDATE` on the slot row inside the transaction.
  2. Validate: slot `open` (or `held` when reached via AcceptOffer), availability `published`, slot
     start in the future, `min_notice`/`max_horizon` satisfied (skipped on the offer path, D11),
     remaining capacity > 0 — where the capacity of a slot whose availability has a pool is the
     number of people that pool resolves to who are free across it (D18), and the `capacity`
     column only for a slot with no pool.
  3. Create the booking (status `booked`, `type` per D13); set slot `status = booked` when active
     bookings reach that same capacity.
  4. Auto-assign per D9.
  5. Optional overlap guard (`guardHostOverlap: true`): if any pooled/assigned host has an overlapping
     active booking, throw `HostOverlap` (it is a query, not a solver — D8). The check helper
     (`OverlapCheck::for($host, $start, $end)`) is public API regardless.
- **CreateDirectBooking(bookedFor, bookedBy, spec, hosts, options{type, context, …})**: adhoc slot
  (created directly as `booked`) + booking + assignments, one transaction (D4).
- **CancelBooking(booking, cancelledBy?, )**: `booked → cancelled`, stamp `cancelled_at`/`cancelled_by`;
  release the slot per D3 (availability-born future slot → `open` if not full; adhoc → survives as an
  unlistable open row because it has a booking).
- **CompleteBooking** / **MarkNoShow**: from `booked` only; `completed ↔ no_show` reclassification is
  allowed (both are post-hoc judgments); `cancelled` is terminal.
- **AssignBookingHost(booking, host, role = 'host', guardHostOverlap = false)** (D14): `SELECT … FOR
  UPDATE` on the booking row, then **replace** the role's assignment with this one host. Refused on a
  cancelled booking (`InvalidTransition`). Assigning the host that already holds the role is a no-op:
  nothing written, no event. With `guardHostOverlap: true` the same `OverlapCheck` the booking path uses
  runs first, and an overlapping active booking throws `HostOverlap` before anything is written. Fires
  `BookingHostAssigned` (carrying the host displaced, if any) after commit.
- **UnassignBookingHost(booking, role = 'host')** (D14): row-locked; deletes the role's assignment.
  Refused on a cancelled booking; allowed on a completed or no-show one, whose record may still be
  corrected. No rows for the role is a no-op, with no event. Fires `BookingHostUnassigned` per removed
  host after commit.

### 5.3 Offers
- **CreateOffer(offeredTo, slots, expiresAt?, createdBy?, message?, meta?, context?)**: `slots` is a mix of existing
  `open` capacity-1 slots (→ `held`) and adhoc slot specs (created as `held`). Generates the token.
- **AcceptOffer(offer, chosenSlot, bookedBy?)**: refuse unless `pending`, unexpired (checked at call
  time even if no sweep ran), and the slot belongs to the offer. Book the chosen slot via the BookSlot
  internals (offer path: D11 relaxations), release every other slot per D3, set `accepted` +
  `accepted_booking_id`.
- **WithdrawOffer**: `pending → withdrawn`, release all slots per D3.
- **ExpireOffers**: sweep for the consumer's scheduler — expire every `pending` offer past `expires_at`,
  release slots, fire one event per offer. Idempotent, safe under `withoutOverlapping()`.
- Enforcement of "only the invitee sees it" is the consumer's (token lookup + their auth); the package
  guarantees only that `held` slots never appear in `bookable` scopes.

### 5.4 Query scopes (public API)
`Availability::published()`, `Slot::bookable()` (open + published availability + future + notice/horizon
satisfied), `Slot::upcoming()` (live, never retired), `Slot::retired()`, `Booking::active()` (status
`booked`), `Booking::upcoming()`, `Offer::pending()` (status pending AND unexpired),
`Offer::pendingFor($party)` (pending and unexpired, offered to that party), `Offer::createdBy($party)`,
and `forContext($model)` on `Availability`, `Booking` and `Offer`.

`Slot::bookable($now, requireFreeHost: true)` adds the D15 filter: a slot whose availability has a host
pool and nobody that pool *resolves to* (D17) is free during it drops out. Since SQL cannot call
PHP, the pools of the availabilities the query can reach are resolved first and handed to the busy
check as a values list, so it is no longer a single statement — but the number of queries is fixed
and does not grow with the number of slots. It is off by default, so `bookable()` on its own means
exactly what it meant before. `Slot::capacityFor($now)` is the same question asked of one slot as a
number: the people its pool resolves to with nothing else booked across it, falling back to the
slot's own `capacity` column when there is no pool at all. It is also what `BookSlot` gates a
pooled slot on (D18).

### 5.5 Host availability (D15)

`Support\HostAvailability` is the read side of the R18 guard:

- `busyBookings($host, $start, $end, $except = null)` — active bookings (status `booked`) with this host
  assigned in **any** role whose slot overlaps `[$start, $end)`, ordered by slot start. `$except` drops
  one booking from the answer, which is how a caller asks "would this host be free if we ignored the
  booking they are about to change?".
- `isFree($host, $start, $end, $except = null)` — the same question as a boolean.
- `freeHosts($availability, $slot, $role = 'host')` — the people the availability's pool for that role
  stands for who are free during the slot, returned as the **host models** in pool order. Every entry
  goes through the bound `HostResolver` at the slot's start (D17), and two entries resolving to the
  same person yield that person once. It never picks one: choosing is the consumer's (D8).
- `freeHolders($availability, $slot, $at = null, $exclusiveHosts = null)` — the same across the whole
  pool whatever role each entry fills, which is the role-agnostic reading `bookable(requireFreeHost:)`
  and `capacityFor()` take. `$at` names the moment the pool is resolved at, defaulting to the slot's
  start. `$exclusiveHosts` overrides `config('dibs.exclusive_hosts')` for one question and is how the
  booking gate asks it with the flag off (D18).

`busyBookings` and `isFree` resolve nothing: they ask about a host that is already concrete.

### 5.6 Series (D16)

- **CreateSeries(SeriesSpec)** — records the rule at `rule_version` 1 and materialises
  nothing; how far ahead to open times is a separate decision, made by the caller.
  `SeriesSpec::ensureValid()` enforces only what is true of any consumer's series (≥ 1
  window, ≥ 1 host, an end after the start, ordinals on the monthly cadence and nowhere
  else, windows inside their day and non-overlapping, and windows sharing a weekday
  separated by at least `duration + padding` — room for one whole appointment) and throws
  `InvalidSeries` carrying a machine `reason`. Domain rules (business hours, rounding, a
  unique title in a leader's words) stay with the consumer, which is the only place that
  can phrase the refusal for a person.
- **MaterialiseSeries(Series, `$through`)** — for each local date the cadence admits
  between the series' today and `$through`, one published `Availability` per window,
  ordered by clock, with its own copy of the pool and the series' geometry, place, notice,
  horizon and meta. Idempotent: a `(series_id, occurs_on, window_index)` key that already
  has a row is skipped whatever state that row is in. Never reaches before today. Creates
  nothing for a paused or ended series. Fires `SeriesMaterialised` with the rows created.
- **UpdateSeries(Series, SeriesSpec)** — an edit that moves the rule (windows, cadence,
  ordinals, dates, duration, padding, place, pool, timezone) bumps `rule_version` and
  regenerates; an edit that only changes what a day *carries* (title, meta, notice,
  horizon) is copied onto future following occurrences, with no bump and no slot touched.
- **RegenerateSeries(Series)** — remakes every future, following occurrence still on an
  older version, then materialises out to `max_horizon_days` (90 days when null). It will
  not touch the past, a detached occurrence, or one carrying a live booking. One whose
  bookings are all spent is *released* (D16) rather than deleted.
- **FindSeriesConflicts(Series, SeriesSpec)** — pure read: the live future bookings a
  proposed rule would strand, so the consumer can ask a person rather than cancel on
  their behalf. A shorter horizon is not a conflict.
- **ReparentSlotAsAdhoc(Slot)** — makes a booked slot adhoc, keeping the booking, its
  hosts, its time and its place (copied from the availability when the slot had none), so
  the day can be remade around it. This is how "keep it" is answered.
- **PauseSeries / ResumeSeries(Series, `$through`)** — pause retires every unclaimed
  future slot on the series' occurrences, detached ones included, and leaves booked ones;
  resume **reopens those same rows** (never remakes the days, so nothing can be
  duplicated) and then materialises the dates that came due meanwhile.
- **DetachOccurrence / FollowSeries(Availability)** — take one day out of the rule's
  hands and put it back. Following marks the day stale and lets `RegenerateSeries` do the
  work, so exactly one code path remakes a day.
- **DeleteSeries(Series)** — refused (`DeletionRefused`) if any occurrence ever carried a
  booking, cancelled ones included; otherwise the rule, its windows, its pool and its
  occurrences go, each occurrence through `DeleteAvailability` so a held slot refuses as
  it always has.
- **SweepSeries(`$now`)** — the sweep a consumer's scheduler runs: every active series
  rolled forward to its horizon, past unclaimed slots retired, and a series whose
  `ends_on` has passed **on its own calendar** set to `ended`. Per-series transaction; one
  failure does not stop the sweep and is rethrown at the end.

## 6. Events

`RobinsonRyan\Dibs\Events`: `AvailabilityPublished`, `AvailabilityClosed`, `BookingCreated`,
`BookingCancelled`, `BookingCompleted`, `BookingMarkedNoShow`, `BookingHostAssigned`,
`BookingHostUnassigned`, `OfferCreated`, `OfferAccepted`, `OfferWithdrawn`, `OfferExpired`,
`SeriesMaterialised` (with the occurrences that run created), `SeriesPaused`, `SeriesResumed`,
`SeriesDeleted` (carrying the series as it was — the row is gone by the time a listener runs). Each
carries the affected model(s), fully loaded. Consumers hang
notifications, reminders, and workflow side effects (e.g. ccstake's calling follow-up stamp) on these.

## 7. Configuration & extension (`config/dibs.php`)

- `models` class-map: consumers may substitute extended models (must extend the package's) —
  `Series`, `SeriesWindow` and `SeriesHost` included.
- `token_length` (default 48).
- `exclusive_hosts` (default `false`): whether a live booking on a slot makes its host busy for
  that same slot (D18). Read in one place, `Support\OverlapCheck::hostsAreExclusive()`.
- `Contracts\HostResolver` is bound in the service provider to `Support\IdentityHostResolver`
  with `bind()`, so a consumer replaces it with one line of its own (D17). It is a container
  binding rather than a config key on purpose: it is behaviour, not a value.
- Nothing else. No timezone config (D10), no permission config, no notification config — their absence
  is the design.

## 8. Non-functional requirements

- PHP ^8.2, `illuminate/*` ^12|^13 (ruled 2026-09-01: ^11 dropped — the dev constraints never resolve to it, so it was an untested promise); PostgreSQL 18+ (uuidv7 defaults) —
  documented in README; per fleet doctrine consumers are Postgres anyway.
- `declare(strict_types=1)`, `final` classes, full types; Pint preset per `pint.json`; PHPStan level 8,
  `phpVersion: 80200`, zero `ignoreErrors`; Rector clean.
- Tests: Pest + Testbench on **real PostgreSQL** (DDEV `db` service, `testing` database) — never
  SQLite; the taxon `TestCase` pattern (env-overridable connection, migrator-path registration).
- The gate is `ddev composer quality`; pre-commit hook enforces it; `harness package-check` before any
  consumer re-resolves.

## 9. Requirements ledger

Statuses: `Not started` → `In progress` → `Done`. Build sessions keep this current; completion audit
per the `verification` skill before any "done" claim.

| ID | Requirement | Implementation | Tests | Status |
|----|-------------|----------------|-------|--------|
| R1 | Migrations create the seven `dibs_*` tables exactly as §4 (columns, FK actions, defaults) | `database/migrations/2024_01_01_00000{1..7}_*` | `tests/Feature/Foundation/SchemaTest.php` | Done |
| R2 | All PKs are db-generated uuid v7; models use a package-local uuid-PK concern | `Concerns\HasUuidPrimaryKey` (incrementing=true, keyType=string) | `SchemaTest` "generates uuid v7 primary keys" | Done |
| R3 | Partial unique index blocks a second live booking by the same `booked_for` on one slot | partial unique index in `..._000004_create_dibs_bookings_table.php` | `SchemaTest` "blocks a second live booking" / "rebook once cancelled" | Done |
| R4 | `slot_id` FK restrictOnDelete makes deleting a slot with any booking impossible at the DB layer | `slot_id` FK `restrictOnDelete` in bookings migration | `SchemaTest` "refuses to delete a slot that has any booking row" | Done |
| R5 | Availability status machine: draft→published, published→closed, closed→published; all other transitions throw `InvalidTransition` | `Actions\PublishAvailability`, `Actions\CloseAvailability` via `AvailabilityStatus::canTransitionTo` | `tests/Feature/Availability/AvailabilityStatusMachineTest.php` | Done |
| R6 | Publishing materializes slots per the §5.1 grid algorithm (duration + padding, trailing remainder unused) | `Support\SlotGrid::positions`, `PublishAvailability` | `tests/Unit/SlotGridTest.php`, `PublishAvailabilityTest` | Done |
| R7 | Publish is idempotent — re-invoking generates no duplicate slots | `PublishAvailability` (generates only when no slots exist) | `PublishAvailabilityTest` publish→close→reopen | Done |
| R8 | Geometry edit regenerates open slots only; booked/held slots survive untouched, including outside the new window | `Actions\UpdateAvailabilityGeometry` (deletes open slots with zero bookings only — B15) | `UpdateAvailabilityGeometryTest` | Done |
| R9 | Regeneration skips grid positions overlapping surviving booked/held slots | `UpdateAvailabilityGeometry` overlap skip | `UpdateAvailabilityGeometryTest` | Done |
| R10 | CloseAvailability removes its open slots from `bookable` scope without deleting rows or touching bookings | `Actions\CloseAvailability` + `Slot::bookable()` | `CloseAvailabilityTest` | Done |
| R11 | DuplicateAvailability copies geometry, type, name, location, and pool into a new draft at a supplied window | `Actions\DuplicateAvailability` (also context/notice/horizon/meta — B16) | `DuplicateAvailabilityTest` | Done |
| R12 | Deleting an availability with held or booked-upon slots throws `DeletionRefused`; a clean one cascades its slots | `Actions\DeleteAvailability` | `DeleteAvailabilityTest` | Done |
| R13 | BookSlot locks the slot row (`FOR UPDATE`); under two concurrent attempts on the last capacity, exactly one succeeds and the loser gets `SlotUnavailable` (test with two connections) | `Actions\BookSlot` (`lockForUpdate` re-fetch; count under lock) | `tests/Concurrency/BookSlotConcurrencyTest.php` (two connections) | Done |
| R14 | BookSlot enforces: open status, published availability, future start, min-notice, max-horizon, remaining capacity | `Actions\BookSlot` validations | `tests/Feature/Booking/BookSlotTest.php` | Done |
| R15 | Slot flips to `booked` only when active bookings reach capacity; capacity-N slots accept N bookings | `BookSlot` settle step (booked iff active ≥ capacity) | `BookSlotTest` capacity-N | Done |
| R16 | Auto-assign: pool of exactly one host for a role → assigned on booking; larger pools left unassigned | `BookSlot` auto-assign (pool grouped by role, exactly one host) | `BookSlotTest` auto-assign | Done |
| R17 | Host pools and assignments accept multiple hosts with distinct roles (thing + person combos) | `AvailabilityHost`/`BookingHost` role rows | `BookSlotTest`, `CreateDirectBookingTest`, `HostAssignmentTest` | Done |
| R18 | `guardHostOverlap` option throws `HostOverlap` when a pooled/assigned host has an overlapping active booking; off by default | `BookingOptions::guardHostOverlap` → `HostOverlap` (hosts being assigned — B17) | `BookSlotTest` guard on/off | Done |
| R19 | `OverlapCheck::for(host, start, end)` returns overlapping active bookings as public API | `Support\OverlapCheck::for` | `tests/Feature/Booking/OverlapCheckTest.php` | Done |
| R20 | CreateDirectBooking creates an adhoc slot + booking + assignments in one transaction | `Actions\CreateDirectBooking` (adhoc slot + BookSlot internals + `HostAssignment`s; no `context` — B18) | `CreateDirectBookingTest` | Done |
| R21 | Booking `type` defaults from the availability at creation and survives later availability edits (D13) | `BookSlot` denormalises `type` at creation | `BookSlotTest` type survives availability edit | Done |
| R22 | CancelBooking stamps `cancelled_at`/`cancelled_by`; future availability-born slot reverts toward `open`; adhoc slot survives (has a booking) but never appears bookable | `Actions\CancelBooking` + `Support\ReleaseSlot` (D3) | `CancelBookingTest`, `ReleaseSlotTest` | Done |
| R23 | Booking status machine: booked→completed/cancelled/no_show; completed↔no_show allowed; cancelled terminal; others throw | `Actions\CompleteBooking`, `Actions\MarkNoShow` via `BookingStatus::canTransitionTo` | `tests/Feature/Booking/BookingOutcomeTest.php` | Done |
| R24 | CreateOffer holds existing open capacity-1 slots and creates adhoc specs as held; mixing both in one offer works | `Actions\CreateOffer` (existing slots locked → held; adhoc specs created held) | `tests/Feature/Offer/CreateOfferTest.php`, mixed-offer cases in `AcceptOfferTest` | Done |
| R25 | CreateOffer refuses capacity>1 slots (D12) | `CreateOffer` → `SlotNotOfferable` for capacity > 1 | `CreateOfferTest` | Done |
| R26 | Offer token is unique, ≥ 40 chars, and the only handle needed to fetch a pending offer | `CreateOffer` token `Str::random(max(40, token_length))`, unique column | `CreateOfferTest` token cases | Done |
| R27 | AcceptOffer books the chosen slot, releases losers per D3 (availability-born → open, adhoc unbooked → deleted), sets accepted + accepted_booking_id | `Actions\AcceptOffer` (BookSlot viaOffer + `ReleaseSlot` losers) | `AcceptOfferTest` | Done |
| R28 | AcceptOffer works on a closed availability and ignores notice/horizon (D11), but refuses expired/withdrawn/accepted offers and slots outside the offer | `AcceptOffer` refusals; `BookingOptions::viaOffer` relaxations in `BookSlot` | `AcceptOfferTest` | Done |
| R29 | AcceptOffer refuses a pending offer past `expires_at` even if no sweep has run | `AcceptOffer` checks `Offer::isExpired()` under the lock | `AcceptOfferTest` clock-expiry case | Done |
| R30 | WithdrawOffer releases all slots per D3 | `Actions\WithdrawOffer` | `tests/Feature/Offer/WithdrawOfferTest.php` | Done |
| R31 | ExpireOffers sweeps all overdue pending offers, releases slots, fires one `OfferExpired` each; idempotent | `Actions\ExpireOffers` (per-offer transaction, status re-checked under lock) | `tests/Feature/Offer/ExpireOffersTest.php` | Done |
| R32 | Held slots never appear in `bookable` scope | `Slot::scopeBookable` (status = open) | `tests/Feature/Foundation/ScopesTest.php` "excludes held and booked" | Done |
| R33 | The ten §6 events fire after commit, each carrying its loaded model(s) | every action: `DB::afterCommit(fn () => event(...))` inside `DB::transaction`, models loaded first | `tests/Feature/{Availability,Booking,Offer}/*EventsTest.php` / per-action event tests (transactionLevel === 1 + relationLoaded) | Done |
| R34 | All §5.4 scopes behave as specified (incl. notice/horizon math inside `bookable`) | scopes on `Availability`, `Slot`, `Booking`, `Offer` | `ScopesTest` (11 cases incl. notice/horizon math) | Done |
| R35 | Config `models` map substitutes extended models throughout the package's own queries | `Support\Dibs::model/make/query`; relationships + factory `modelName()` resolve through it | `tests/Feature/Foundation/ModelResolverTest.php` | Done |
| R36 | Package stores/compares UTC instants only; no timezone conversion anywhere in package code (D10) | `CarbonImmutable::now('UTC')` / `Slot::instant()` everywhere; `->utc()` normalisation before persistence only | grep audit 2026-09-01: no `timezone`/`setTimezone`/`tz(`/`parse` in `src/`; `SlotGridTest` same-instant-across-offsets case | Done |
| R37 | Test suite runs on real PostgreSQL via Testbench (taxon TestCase pattern); no SQLite anywhere | `tests/TestCase.php` (pgsql, per-worktree `testing_wt_<slug>`), `tests/Pest.php` | whole suite; `SchemaTest` asserts timestamptz/jsonb | Done |
| R38 | `ddev composer quality` passes: Pint, PHPStan L8 zero-ignore, Rector check, full Pest suite | `composer quality` (`.githooks/pre-commit` runs it on every commit) | 0.1.2 run on `feature/v1` (0ecd949): 256 passed / 724 assertions; 0.2.0 run on `develop` 2026-09-01: Pint 122 files, PHPStan 0 errors, Rector clean, **298 passed / 812 assertions**; 0.3.0 run on `feature/series` 2026-09-03: Pint 165 files, PHPStan 0 errors, Rector clean, **378 passed / 1002 assertions**; `harness package-check` @ 0.1.2: gate green, floor green @ Laravel 12.61.1 | Done |
| R39 | Factories exist for all models; states for each status | `database/factories/*Factory.php` (incl. `Series`, `SeriesWindow`, `SeriesHost`) | `tests/Feature/Foundation/FactoriesTest.php` | Done |
| R40 | Bookings and offers carry `context`; `BookSlot` copies the availability's, direct bookings/offers take it as an argument; `forContext()` scopes on Availability/Booking/Offer | `BookSlot` stamps `context` (option ?? availability); `CreateOffer(..., ?Model $context)`; `AcceptOffer` propagates; `scopeForContext` on Availability/Booking/Offer | `tests/Feature/Foundation/ContextTest.php`; context cases in `BookSlotTest`, `CreateDirectBookingTest`, `CreateOfferTest`, `AcceptOfferTest` | Done |
| R41 | Regeneration retires (never deletes) an open slot that has booking rows; retired slots leave `bookable()` and `upcoming()`; their position is reused | `UpdateAvailabilityGeometry` retires open slots with spent history (`whereDoesntHave('activeBookings')`); `SlotStatus::Retired`; `Slot::retired()`; `upcoming()` excludes; `ReleaseSlot` treats retired as terminal | `UpdateAvailabilityGeometryTest` retirement cases (incl. partly-full slot survives), `ScopesTest`, `ReleaseSlotTest`, `DeleteAvailabilityTest` | Done |
| R42 | `DeleteAvailability` / `UpdateAvailabilityGeometry` query slots through `Dibs::query()` so lock, check and delete run on the transaction's connection | `DeleteAvailability`, `UpdateAvailabilityGeometry` via `Dibs::lock()` + `Dibs::query(Slot::class)` | `tests/Concurrency/AvailabilityConcurrencyTest.php` pinned-model cases (delete case red before the fix) | Done |
| R43 | `AssignBookingHost(booking, host, role, guardHostOverlap)` replaces the role's assignment from the booking's locked row: one row per role afterwards, same host + role is a no-op with no event, cancelled booking throws, the optional overlap guard throws `HostOverlap` before any write, roles are independent; fires `BookingHostAssigned` with the displaced host after commit (D14) | `Actions\AssignBookingHost`, `Events\BookingHostAssigned`, reuses `Support\OverlapCheck::forSlot` | `tests/Feature/Booking/AssignBookingHostTest.php`; `tests/Concurrency/BookingHostConcurrencyTest.php` (lock mutated out → red) | Done |
| R44 | `UnassignBookingHost(booking, role)` deletes the role's assignment from the booking's locked row; no rows is a no-op with no event; cancelled booking throws, completed/no-show allowed; fires `BookingHostUnassigned` per removed host after commit (D14) | `Actions\UnassignBookingHost`, `Events\BookingHostUnassigned` | `tests/Feature/Booking/UnassignBookingHostTest.php` | Done |
| R45 | `HostAvailability::busyBookings($host, $start, $end, $except = null)` returns the host's active bookings in any role whose slot overlaps `[$start, $end)` ordered by slot start, `$except` excluded; `isFree()` is its boolean (D15) | `Support\HostAvailability`, reusing `OverlapCheck::overlappingSlots()` as the one overlap predicate | `tests/Feature/Booking/HostAvailabilityTest.php` | Done |
| R46 | `HostAvailability::freeHosts($availability, $slot, $role)` returns the pool members free during the slot as host models in pool order, in a fixed number of queries; a host busy only on that same slot still counts as free (D15) | `Support\HostAvailability::freeHosts` (one busy-assignment query + one morph load) | `HostAvailabilityTest` pool-of-three cases | Done |
| R47 | `Slot::bookable($now, requireFreeHost: true)` also excludes a slot whose availability has a pool with nobody free; an availability with no pool is never excluded; the default `false` leaves `bookable()` byte-identical (D15) | `Slot::scopeBookable` — nested `whereExists`/`whereNotExists` over `fromSub` derived tables, no raw SQL, no N+1 | `tests/Feature/Foundation/BookableFreeHostTest.php`, `ScopesTest` | Done |
| R48 | `Offer::pendingFor($party)` (pending, unexpired, offered to that party) and `Offer::createdBy($party)` scopes | `Offer::scopePendingFor`, `Offer::scopeCreatedBy` | `tests/Feature/Offer/OfferScopesTest.php` | Done |
| R49 | `dibs_series`, `dibs_series_windows`, `dibs_series_hosts` exist per §4, with the case-insensitive title uniqueness per context, the window bounds check, the pool uniqueness, and cascading deletes | `database/migrations/2024_01_01_00000{8,9,10}_*` | `tests/Feature/Series/SeriesSchemaTest.php` | Done |
| R50 | `dibs_availabilities` gains `series_id` (nullOnDelete), `occurs_on`, `window_index`, `rule_version`, `detached_at`, with the partial unique occurrence key and the `(series_id, occurs_on)` index | `..._000011_add_series_to_dibs_availabilities_table.php`; `Availability::series()`, `detached()`, `isDetached()` | `SeriesSchemaTest` (unique key, two blocks a day, occurrences survive the series, detached scope) | Done |
| R51 | `Cadence` semantics per D16: Sunday-based week index from the week containing `starts_on`; weekly every week; fortnightly even indices; monthly-ordinal per weekday with `-1` = last and a missing fifth yielding nothing; once = week 0; only weekdays that have windows; bounded by `starts_on`/`ends_on` | `Enums\Cadence`, `Series::occursOn()`, `Series::occurrenceDates()` | `tests/Feature/Series/SeriesCadenceTest.php` (10 cases) | Done |
| R52 | `SeriesSpec::ensureValid()` refuses windows overlapping, touching or closer than one appointment on a weekday, windows outside their day, an end on or before the start, ordinals mismatched to the cadence, no windows and no hosts — each with a machine `reason` on `InvalidSeries` | `Data\SeriesSpec`, `Data\WindowSpec`, `Exceptions\InvalidSeries` | `tests/Feature/Series/SeriesSpecTest.php` (12 cases) | Done |
| R53 | `MaterialiseSeries` is idempotent and lays one published occurrence per window per matching date, with pool, geometry, place, notice, horizon and meta copied, published through `PublishAvailability`; it decides from the series' locked row, so a rival run queues behind it rather than colliding with the occurrence key | `Actions\MaterialiseSeries`, `Actions\CreateSeries` | `tests/Feature/Series/MaterialiseSeriesTest.php` (13 cases; the skip and the conversion both mutated to red before being trusted), `tests/Concurrency/SeriesConcurrencyTest.php` (two connections; red with the lock mutated out) | Done |
| R54 | A series' wall-clock window lands on the same local time on every date, across both daylight-saving changes; everything stored is a UTC instant (D10's one exception) | `Support\SeriesClock::instantOn()` | `MaterialiseSeriesTest` "keeps the same local start on every date, across both daylight-saving changes" (pins 2026-03-08 and 2026-11-01 to the instant) | Done |
| R55 | Materialisation never touches a past, booked or detached occurrence, and never reaches before the series' own today | `MaterialiseSeries` (occurrence key skip; dates start at `SeriesClock::today`) | `MaterialiseSeriesTest` past / booked / detached / cancelled-booking cases | Done |
| R56 | `UpdateSeries` bumps `rule_version` and regenerates on a rule change; a title / meta / notice / horizon edit restamps future following occurrences without a bump | `Actions\UpdateSeries`, `Actions\SyncSeriesRule` | `tests/Feature/Series/UpdateSeriesTest.php` first two cases | Done |
| R57 | `RegenerateSeries` remakes stale future following occurrences, leaves one with a live booking standing, and releases one whose bookings are all spent | `Actions\RegenerateSeries` | `UpdateSeriesTest` live-booking and released-day cases | Done |
| R58 | `FindSeriesConflicts` names the live future bookings a proposed rule would strand and nothing else; a shorter horizon is not a conflict; past and detached days are ignored | `Actions\FindSeriesConflicts` | `UpdateSeriesTest` conflict cases | Done |
| R59 | `ReparentSlotAsAdhoc` keeps the booking, its hosts, its time, its capacity and its place | `Actions\ReparentSlotAsAdhoc` | `UpdateSeriesTest` "keeps a booking, its host and its place when its slot is cut loose" | Done |
| R60 | Pause retires unclaimed future slots (detached days included) and keeps booked ones; resume reopens the same rows without duplicating, then fills the dates that came due | `Actions\PauseSeries`, `Actions\ResumeSeries` | `tests/Feature/Series/SeriesLifecycleTest.php` pause / resume cases | Done |
| R61 | `DetachOccurrence` takes a day out of the rule's hands and refuses a non-occurrence; `FollowSeries` puts it back through `RegenerateSeries` | `Actions\DetachOccurrence`, `Actions\FollowSeries` | `SeriesLifecycleTest` detach / follow cases | Done |
| R62 | `DeleteSeries` is refused if any occurrence ever carried a booking; otherwise rule, windows, pool and occurrences all go | `Actions\DeleteSeries` | `SeriesLifecycleTest` delete cases | Done |
| R63 | `SweepSeries` rolls every active series to its horizon, retires past unclaimed slots, and ends a series whose `ends_on` has passed on the series' own calendar | `Actions\SweepSeries` | `SeriesLifecycleTest` sweep cases (incl. the ward-clock end date and the paused series) | Done |
| R64 | `Contracts\HostResolver` returns a collection, defaults to identity through `Support\IdentityHostResolver`, and is bound with `bind()` so a consumer overrides it | `Contracts\HostResolver`, `Support\IdentityHostResolver`, `DibsServiceProvider::register()` | `tests/Feature/Series/HostResolverTest.php` identity case | Done |
| R66 | `HostResolver::resolve()` is told the availability's context, so one catalog position resolves to different people for two contexts, in capacity, `freeHolders` and the `bookable(requireFreeHost:)` filter alike | `Contracts\HostResolver`, `Support\IdentityHostResolver`, `Support\HostAvailability::resolvePool()`, `Slot::resolvedPool()` | `HostResolverTest` "resolves the same pool entry to different people for two contexts" | Done |
| R65 | `freeHosts`/`freeHolders`, `bookable(requireFreeHost:)` and `capacityFor()` read resolved holders: one entry standing for two people gives capacity 2, one standing for nobody gives 0 and an unbookable slot, a resolved person booked across the slot is not free, and two entries for one person count once | `Support\HostAvailability`, `Slot::capacityFor()`, `Slot::scopeBookable()` | `HostResolverTest` (10 cases), `tests/Feature/Foundation/BookableFreeHostTest.php` | Done |
| R67 | `BookSlot` gates a pooled slot on who is free, not on the `capacity` column: a capacity-1 slot whose pool resolves to three free people takes three claims and refuses the fourth, flipping to `booked` only on the third; a pool that resolves to nobody, or whose only holder is booked across the slot, refuses the first; a slot with no pool still gates on the column. Two connections contending for a pooled slot with two free people both win; with one free person exactly one does | `Actions\BookSlot::capacityOf()` (used by the capacity check and the settle step), `Slot::capacityFor()` | `tests/Feature/Booking/PooledCapacityTest.php`, `tests/Concurrency/BookSlotConcurrencyTest.php` pooled cases | Done |
| R68 | `config('dibs.exclusive_hosts')` (default `false`) makes a booking on the slot being asked about count against its own host, in one definition (`Support\OverlapCheck::hostsAreExclusive()`) that `freeHolders`, `capacityFor()`, `bookable(requireFreeHost:)` and `AssignBookingHost(guardHostOverlap: true)` all read. Default off leaves the R19 behaviour exactly as it was | `Support\OverlapCheck`, `Support\HostAvailability::busyAssignments()`, `Slot::scopeBookable()`, `config/dibs.php` | `PooledCapacityTest.php` exclusive-host cases | Done |

## 9a. Non-goals (explicit exclusions)

| ID | Non-goal | Status |
|----|----------|--------|
| N1 | ~~Recurrence rules (RRULE, series editing, exceptions) — duplicate-availability instead (D5)~~ | **No longer excluded** (2026-09-03, D16): recurrence ships as a materialised series. RRULE strings specifically remain out — the cadence is an enum plus ordinals, not a parser. |
| N2 | Joint-availability computation / constraint solving across resources (D8) | Excluded |
| N3 | Cross-availability conflict *enforcement* (beyond the opt-in overlap guard R18; D15's queries and the `requireFreeHost` filter report conflicts, they do not enforce anything) | Excluded |
| N4 | Notifications, reminders, mail, SMS — consumers listen to events | Excluded |
| N5 | UI, routes, controllers, HTTP anything | Excluded |
| N6 | Authorization / visibility policy (who may publish, see, book) | Excluded |
| N7 | Timezone parsing/display; wall-clock storage conventions (D10) | Excluded |
| N8 | Payments, deposits, pricing | Excluded |
| N9 | Waitlists | Excluded |
| N10 | Partial holds on capacity-N slots (D12) | Excluded |
| N11 | iCal/.ics export | Excluded |
| N12 | Bookings spanning multiple slots | Excluded |

## 10. First consumer sketch (informative — normative spec lives in ccstake)

ccstake maps: context = ward `Organization`; hosts = `User` with the single role `interviewer`;
`type` vocabulary `temple-recommend` / `tithing-settlement` / `calling-meeting`; pool- and
availability-management rights derived from the existing "bishopric-level for a ward" definition
(`CallingsVisibility`); ward-scoped member visibility built app-side on `Slot::bookable()`; a
`CandidateInvited` listener creates an Offer and an `OfferAccepted` listener stamps the candidate's
follow-up date; reminder sweeps follow the `routes/console.php` named + `withoutOverlapping()`
convention; consent via hey-you contact settings. Member UI under the dashboard, admin UI in VueAdmin,
Nuxt UI components — all per the ccstake spec to come, including its lexicon table and interaction
design blocks.
