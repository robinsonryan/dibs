# Changelog

All notable changes to this package are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Behavior changes land here in the commit that makes them, not at tag time.

## [Unreleased]

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
- Slot state `retired`: an open slot carrying cancelled-booking history that a
  grid regeneration displaces is retired (history kept, out of `bookable()` and
  `upcoming()`, position reused) instead of staying bookable off-pattern.
  `Slot::retired()` scope.

### Changed

- `PublishAvailability`, `CloseAvailability`, `UpdateAvailabilityGeometry`,
  `CancelBooking`, `CompleteBooking` and `MarkNoShow` return the freshly
  locked model instance rather than mutating the one passed in.
- A capacity-N slot with `guardHostOverlap: true` seats a second party under
  the same host instead of throwing `HostOverlap`.
