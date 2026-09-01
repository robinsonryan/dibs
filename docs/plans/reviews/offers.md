# Review — Module C: Offers (commit 13437cb)

Reviewer ran: 40/40 module tests, PHPStan clean, 204/204 full; 10 probe tests (4 two-session races) and
4 mutations, all removed/restored.

## Invariant trace
- Offer-row lock: `AcceptOffer.php:31`, `WithdrawOffer.php:27`, `ExpireOffers.php:55`; slot lock
  `CreateOffer.php:81`. Two-session probes: B blocks at its first statement with zero writes; after A commits
  the sweep expires exactly 1; two CreateOffers on one slot → second `SlotNotOfferable`.
- Status re-check after lock: `AcceptOffer.php:39` (mutation → 2 red), `ExpireOffers.php:60` (mutation → 0 red).
- **Removing every `lockForUpdate()` leaves all 40 shipped tests green** — finding 1. Under that mutation B
  inserted a booking from a stale read before blocking on the offer row.
- `bookedFor` is always the locked offer's `offeredTo`; accepted slot never released; `expires_at == now` refused.

## Findings
1. **MAJOR** (test integrity) no offer concurrency test; none of the four locks nor the sweep's post-lock
   re-check fails a test when removed. Direction: `tests/Concurrency/OfferConcurrencyTest.php` mirroring
   `BookSlotConcurrencyTest` (A holds the offer row; B runs sweep/accept/withdraw → 55P03 and no writes before
   the block), plus a single-session test mutating the offer in an `Offer::retrieved` hook during the sweep.
2. **MAJOR** `src/Actions/CreateOffer.php:112-127` — adhoc specs unvalidated: past `startsAt` or
   `endsAt <= startsAt` is written as a held slot and the offer succeeds (inverted spec was created **and
   booked**); past `expiresAt` accepted (`:47`). `hold()` already refuses a past existing slot, so B23's
   "one gate" is already divergent. Direction: reject in CreateOffer (and CreateDirectBooking, same hole).
3. MINOR `ExpireOffers.php:18-22,40-44` — any exception from one offer aborts the loop; docblock claims
   otherwise. Catch per offer and continue; `orderBy('expires_at')` for determinism.
4. MINOR (Rule of Three) six identical `Dibs::query(X)->whereKey($k)->lockForUpdate()->first()` sites;
   three `foreach (slots) ReleaseSlot` loops. Direction: `Dibs::lock(Model): ?Model` helper (availability.md
   finding 2 asks for the same).
5. MINOR `CreateOffer.php:57-62` — `[$slot, $slot]` fails with "its status is held" (misleading); dedupe by
   key; sort keys before locking to avoid `[A,B]`/`[B,A]` deadlocks (40P01).
6. MINOR (convention) no CHANGELOG in commit — orchestrator owns it.

## Clean
Invitee always bookedFor; token clamp; membership by key; viaOffer relaxations exact; booking.md finding 5
does not bite (savepoint); all-or-nothing rollback; events at depth 1; UTC-only; reuse clean; R24–R35
single-session coverage behavioural; lexicon clean.
