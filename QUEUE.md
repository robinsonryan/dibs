# Implementation Queue

> Deferred work, captured mid-session, picked up deliberately.
> Anything the gate surfaced and you deliberately did not fix belongs here,
> with a reason — not in a PHPStan baseline or an ignore rule.

## Queued

- ~~**Relation queries pin to the parent model's connection.** `DeleteAvailability` and
  `UpdateAvailabilityGeometry` reach slots through `$availability->slots()`, which
  runs on whatever connection hydrated the availability, while the action's
  `DB::transaction()` opens on the default connection. Identical in every
  single-connection app (all current consumers); diverges only if a consumer hands
  in a model from a non-default connection. Proposed fix: route those two queries
  through `Dibs::query(Slot::class)->where('availability_id', …)` so lock, check and
  delete share the transaction's connection. Found by the remediation pass
  (2026-09-01); out of the reviewed findings' scope.~~ **Fixed in the 2026-09-01 follow-up build (R42).**
- ~~**AcceptOffer re-hydrates the offer's context model** to pass through
  `BookingOptions::context`; if the tenant row was deleted while the offer was
  pending, the booking silently inherits the availability's context (or none)
  instead of the offer's stored scope. Proposed fix: let `BookingOptions` carry
  a stored `(context_type, context_id)` pair alongside the model form, and have
  `AcceptOffer` pass the pair. Found by the follow-up review (2026-09-01);
  consumers deleting tenants with pending offers is the only trigger.~~ **Fixed in 0.1.1.**

- **`AcceptOffer` should accept a `BookingOptions`** (at least `type`/`meta`) so a consumer booking a different kind into a labelled slot need not rewrite `type` after acceptance (ccstake slice 2 BD32/BD36, 2026-09-01).
