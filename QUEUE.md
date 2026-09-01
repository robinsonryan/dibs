# Implementation Queue

> Deferred work, captured mid-session, picked up deliberately.
> Anything the gate surfaced and you deliberately did not fix belongs here,
> with a reason — not in a PHPStan baseline or an ignore rule.

## Queued

- **Relation queries pin to the parent model's connection.** `DeleteAvailability` and
  `UpdateAvailabilityGeometry` reach slots through `$availability->slots()`, which
  runs on whatever connection hydrated the availability, while the action's
  `DB::transaction()` opens on the default connection. Identical in every
  single-connection app (all current consumers); diverges only if a consumer hands
  in a model from a non-default connection. Proposed fix: route those two queries
  through `Dibs::query(Slot::class)->where('availability_id', …)` so lock, check and
  delete share the transaction's connection. Found by the remediation pass
  (2026-09-01); out of the reviewed findings' scope.
