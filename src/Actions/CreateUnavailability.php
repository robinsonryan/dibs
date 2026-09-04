<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\UnavailabilitySpec;
use RobinsonRyan\Dibs\Models\Unavailability;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Record that a scope is away: the row and, for a standing away, its windows.
 *
 * It changes nothing else. No slot is retired, no booking is touched and no
 * availability is edited — an away is a read-time filter, and the appointments
 * that already fall inside it are the consumer's to settle first
 * (`FindUnavailabilityConflicts`).
 */
final class CreateUnavailability
{
    public function __invoke(UnavailabilitySpec $spec): Unavailability
    {
        $spec->ensureValid();

        return DB::transaction(function () use ($spec): Unavailability {
            $away = Dibs::query(Unavailability::class)->create([
                'scope_type' => $spec->scope->getMorphClass(),
                'scope_id' => (string) $spec->scope->getKey(),
                'kind' => $spec->kind,
                'starts_at' => $spec->startsAt,
                'ends_at' => $spec->endsAt,
                'timezone' => $spec->timezone,
                'starts_on' => $spec->startsOn,
                'ends_on' => $spec->endsOn,
                'label' => $spec->label,
                'meta' => $spec->meta,
            ]);

            SyncAwayWindows::windows($away, $spec);

            $away->load('windows');

            return $away;
        });
    }
}
