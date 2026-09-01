# Review — Follow-up: tenancy context, retired state, connection pinning (2e50625..1062efc)

Reviewer ran: 253/253 in the worktree, PHPStan clean, 9 mutations, one two-session race probe.

## Invariant traces
- Context precedence holds: `BookSlot::write` stamps `options ?? availability`; `AcceptOffer` passes the offer's
  context; `CreateOffer` stores supplied only. Mutations dropping stamping in each producer caught (5/1/2 red).
- Retirement never touches a live claim — holds single-session (gate + survivor mutations caught), **fails under
  concurrency** (finding 1).
- Same-connection atomicity — code correct; delete test does not prove the slot-query half (finding 2).

## Findings
1. **BLOCKER** `UpdateAvailabilityGeometry.php:75-78` — retire `UPDATE … WHERE status='open' AND NOT EXISTS(active)`
   evaluates the subquery on the statement snapshot; a booking committed while the update waits on the row lock is
   invisible → slot retired with a live claim + duplicate open slot laid on its position (reproduced). Same race in
   the pre-existing DELETE (fails loudly via FK 23503). Direction: `FOR UPDATE` all the availability's slot rows at
   the top of `regenerate()`.
2. **MAJOR** `tests/Concurrency/AvailabilityConcurrencyTest.php:196-225` — reverting `DeleteAvailability`'s slot
   queries to `$availability->slots()` leaves the test green (55P03 arrives from the cascade). Assert no statements
   on `testing_b` and a `dibs_slots … for update` before the delete on the default connection.
3. MINOR — R42's `UpdateAvailabilityGeometry` half was never a defect (it already worked off the locked
   default-connection copy); ledger/changelog should say "hardened".
4. MINOR `AcceptOffer.php:44,66` — offer context re-hydrated as a model; if the tenant row is gone the booking
   silently inherits another/no context. Direction: carry the stored pair. **Disposition: queued (QUEUE.md).**
5. MINOR — Rule of Three: `context()`/`scopeForContext()` identical in three models → `Concerns/HasContext`.
6. MINOR `CreateOffer.php:34` — seventh positional parameter. **Disposition: skipped; named arguments are the
   documented style.**
- Observation: retirement not conditioned on displacement — identical geometry re-submitted retires a spent-history
  slot and duplicates it. **Ruled (B31): retire only when no identical position exists in the new grid.**

## Clean
DeleteAvailability semantics unchanged; UTC-only; morph aliases; deterministic test ordering; no skips; lexicon.
