<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\AvailabilityGeometry;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\AvailabilityPublished;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Support\SlotGrid;

/**
 * Publish a draft (or reopen a closed) availability, materialising its slot
 * grid the first time round (§5.1). Re-publishing never duplicates slots.
 */
final class PublishAvailability
{
    public function __invoke(Availability $availability): Availability
    {
        return DB::transaction(function () use ($availability): Availability {
            // The caller's copy may predate someone else's transition.
            $availability->refresh();

            $from = $availability->status;

            if (! $from->canTransitionTo(AvailabilityStatus::Published)) {
                throw InvalidTransition::for($availability, $from, AvailabilityStatus::Published);
            }

            $availability->status = AvailabilityStatus::Published;
            $availability->save();

            if ($availability->slots()->count() === 0) {
                $this->materialise($availability);
            }

            $availability->load(['slots', 'hosts']);

            DB::afterCommit(fn () => event(new AvailabilityPublished($availability)));

            return $availability;
        });
    }

    private function materialise(Availability $availability): void
    {
        $positions = SlotGrid::positions(new AvailabilityGeometry(
            $availability->starts_at,
            $availability->ends_at,
            $availability->slot_duration_minutes,
            $availability->slot_padding_minutes,
        ));

        foreach ($positions as $position) {
            // No location: the availability's applies unless a slot overrides it.
            $availability->slots()->create([
                'starts_at' => $position['starts_at'],
                'ends_at' => $position['ends_at'],
                'location' => null,
                'capacity' => 1,
                'status' => SlotStatus::Open,
            ]);
        }
    }
}
