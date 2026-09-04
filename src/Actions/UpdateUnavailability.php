<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\UnavailabilitySpec;
use RobinsonRyan\Dibs\Models\Unavailability;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Change an away: its shape, its span or windows, its clock, its label.
 *
 * The row is re-read `FOR UPDATE` first, like every other state change in this
 * package, so two people editing one away queue up rather than both writing
 * over a copy they read before the other's edit. The windows are replaced
 * rather than diffed, so narrowing, widening and changing shape are one path.
 */
final class UpdateUnavailability
{
    public function __invoke(Unavailability $away, UnavailabilitySpec $spec): Unavailability
    {
        $spec->ensureValid();

        return DB::transaction(function () use ($away, $spec): Unavailability {
            $locked = Dibs::lock($away) ?? $away;

            $locked->fill([
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
            ])->save();

            SyncAwayWindows::windows($locked, $spec);

            $locked->load('windows');

            return $locked;
        });
    }
}
