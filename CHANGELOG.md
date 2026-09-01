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
