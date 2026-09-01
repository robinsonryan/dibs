<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;

/**
 * Copy an availability's geometry, labels, context and host pool into a new
 * draft at a caller-supplied window — the package's answer to recurrence (D5).
 */
final class DuplicateAvailability
{
    public function __invoke(Availability $availability, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Availability
    {
        return DB::transaction(function () use ($availability, $startsAt, $endsAt): Availability {
            // Replicated rather than column-listed, so a consumer subclass's own
            // columns travel with the copy (the model is @extensible).
            $duplicate = $availability->replicate(except: [
                $availability->getKeyName(),
                'status',
                'starts_at',
                'ends_at',
                'created_at',
                'updated_at',
            ]);

            // replicate() carries the source's loaded relations across; the copy
            // owns none of them.
            $duplicate->unsetRelations();

            $duplicate->status = AvailabilityStatus::Draft;
            $duplicate->starts_at = $startsAt->utc();
            $duplicate->ends_at = $endsAt->utc();
            $duplicate->save();

            $availability->hosts()->get()->each(function (AvailabilityHost $host) use ($duplicate): void {
                $duplicate->hosts()->create([
                    'host_type' => $host->host_type,
                    'host_id' => $host->host_id,
                    'role' => $host->role,
                ]);
            });

            $duplicate->load('hosts');

            return $duplicate;
        });
    }
}
