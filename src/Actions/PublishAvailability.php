<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\AvailabilityGeometry;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\AvailabilityPublished;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SlotGrid;

/**
 * Publish a draft (or reopen a closed) availability, materialising its slot
 * grid the first time round (§5.1). Re-publishing never duplicates slots.
 *
 * **Publishing never revives a retired slot.** The grid is generated only when
 * the availability has no slots at all, and a retired row is still a row, so an
 * availability whose times were retired — by a geometry edit (R41) or by being
 * released from a series when its rule moved — comes back published and offering
 * nothing. That is the rule the released path depends on: a released day can be
 * reopened for the sake of its history without the old grid reappearing beside
 * the remade day's.
 */
final class PublishAvailability
{
    public function __invoke(Availability $availability): Availability
    {
        return DB::transaction(function () use ($availability): Availability {
            // Decided from the locked row, never from the caller's copy: a rival
            // transition queues behind this one instead of being overwritten.
            $locked = Dibs::lock($availability);

            if (! $locked instanceof Availability) {
                throw (new ModelNotFoundException)->setModel($availability::class, [$availability->getKey()]);
            }

            $from = $locked->status;

            if (! $from->canTransitionTo(AvailabilityStatus::Published)) {
                throw InvalidTransition::for($locked, $from, AvailabilityStatus::Published);
            }

            $locked->status = AvailabilityStatus::Published;
            $locked->save();

            if ($locked->slots()->count() === 0) {
                $this->materialise($locked);
            }

            $locked->load(['slots', 'hosts']);

            DB::afterCommit(fn () => event(new AvailabilityPublished($locked)));

            return $locked;
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
