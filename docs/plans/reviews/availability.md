# Review — Module A: Availability lifecycle (commit 1f58392)

Reviewer ran: module suite (61 passed), PHPStan clean, two throwaway two-session race
tests (removed), one mutation test (restored).

## Invariant trace
- Regeneration: `UpdateAvailabilityGeometry.php:56-59` deletes `status=open AND whereDoesntHave('bookings')`;
  dropping either predicate fails `UpdateAvailabilityGeometryTest.php:116` / `:145`. Overlap skip `:64-66`,
  half-open predicate `:84-85`, proven by `:161`, `:187`. Postgres re-checks the DELETE predicate after a lock
  wait, so race-safe (reasoned).
- Delete: `DeleteAvailability.php:21` (held), `:25` (`has('bookings')`), proven by `DeleteAvailabilityTest.php:59/69/82`.
  Holds sequentially only — finding 1.

## Findings
1. **MAJOR** `src/Actions/DeleteAvailability.php:21-28` — held/booking checks are plain reads, not serialised
   against a concurrent hold. Reproduced: rival `UPDATE dibs_slots SET status='held'` uncommitted → delete sees
   0 held, blocks on cascade, then deletes availability + now-held slot silently (offer_slots cascades).
   Direction: `$availability->slots()->lockForUpdate()->get()` first; evaluate held/bookings on locked rows.
2. **MAJOR** `PublishAvailability.php:25`, `CloseAvailability.php:23`, `UpdateAvailabilityGeometry.php:29` —
   `refresh()` is a snapshot, not a lock. Reproduced: rival `UPDATE ... status='published'` uncommitted →
   Publish on a draft passes the guard, blocks, overwrites, returns published; a second `AvailabilityPublished`
   fires. Publish racing UpdateGeometry materialises the grid from stale in-memory geometry (`:51-55`).
   Direction: `Dibs::query(Availability::class)->whereKey($id)->lockForUpdate()->firstOrFail()` inside the
   transaction; three call sites → one shared helper.
3. MINOR (test integrity) `UpdateAvailabilityGeometryTest.php:139,142`, `CloseAvailabilityTest.php:55,62` —
   `updated_at`-unchanged assertions are vacuous under `setTestNow` (mutation: touching survivors still passes).
   Advance `setTestNow` before the action, or drop the lines.
4. MINOR (reuse) `DeleteAvailability.php:21`, `UpdateAvailabilityGeometry.php:57` — hand-rolled
   `where('status', ...)` where `Slot::held()` / `Slot::open()` exist; `count() > 0` → `exists()`.
5. MINOR (extensibility) `DuplicateAvailability.php:23-37` — explicit column list on an `@extensible` model;
   a subclass's extra columns are silently dropped. `replicate(except: [...])` then override window/status.
6. MINOR (convention) commit touched no CHANGELOG.md — orchestrator owns it this build; recorded in d4b3770.

## Clean
Grid boundaries, half-open overlap, validation-before-write, publish rollback on bad geometry; UTC-only;
events after commit with loaded relations; SlotGrid earns its class; R5/R7/R8/R9/R10/R12 coverage; lexicon.
