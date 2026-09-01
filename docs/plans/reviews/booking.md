# Review — Module B: Booking core (commit a264669)

Reviewer ran: 63/63 module tests, PHPStan clean, 103/103 with Concurrency before Feature; mutation
tests on lock/count/settle/release (restored).

## Invariant trace
- Lock `src/Actions/BookSlot.php:53` (`Dibs::query(...)->lockForUpdate()`); removed → concurrency test 1 red.
- Count `BookSlot.php:125` (`activeBookings()->count() >= capacity` on the locked copy); removed → **all 63
  tests still green** (every "full" refusal comes from the cached status check at `:99`) — finding 2.
- Settle `BookSlot.php:234-244` recount after insert; neutered → 8 red.
- Release `src/Support/ReleaseSlot.php:24-31`; delete branch removed → 1 red.

## Findings
1. **MAJOR** `BookSlot.php:195-201` — overlap guard counts the host's booking on the slot being claimed as a
   conflict. Scenario: pool of one interviewer, slot capacity 2, `guardHostOverlap: true` → second attendee
   gets `HostOverlap`. Direction: the guard (not public `OverlapCheck::for`) excludes bookings whose `slot_id`
   is the locked slot; add a capacity-N-with-guard test.
2. **MAJOR** `tests/Feature/Booking/BookSlotTest.php` — B13 count untested. Scenario: capacity lowered 3→1 on
   a slot holding one booking (status `open`) → second booking accepted. Add: open capacity-1 slot + factory
   active booking row → `SlotUnavailable` (verified red under mutation).
3. MINOR `CancelBooking.php:26`, `CompleteBooking.php:22`, `MarkNoShow.php:22` — transition check is a plain
   `refresh()`; verified two-session race → two `BookingCancelled` events / cancelled_at stamped on a
   completed booking. Direction: `lockForUpdate()` re-read through `Dibs::query`, as BookSlot does.
4. MINOR `BookSlot.php:74-81` — duplicate `HostAssignment`s surface as `UniqueConstraintViolationException`,
   not a `DibsException`. Dedupe by (morph class, key, role).
5. MINOR `BookSlot.php:223` — the 23505→`SlotUnavailable` conversion leaves the enclosing transaction
   aborted (25P02 on the caller's next statement). Wrap the insert in a nested `DB::transaction` savepoint,
   or document it.
6. MINOR `BookSlotTest.php:226` — test named "books a slot whose in-memory copy is stale" asserts a refusal.

## Clean
Only the locked copy is read; `viaOffer` relaxes exactly held/closed/notice/horizon; D13, D9, D3 correct;
UTC-only; simplification/abstraction/reuse clean; R14–R23, R33 behavioural coverage; lexicon clean.
Noted (reasoned, benign): availability status read unlocked — a CloseAvailability racing a booking may land
one on a closed availability; close never cancels.
