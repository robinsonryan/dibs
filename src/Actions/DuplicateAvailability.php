<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Copy an availability's geometry, labels, context and host pool into a new
 * draft at a caller-supplied window — the package's answer to recurrence (D5).
 */
final class DuplicateAvailability
{
    public function __invoke(Availability $availability, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Availability
    {
        return DB::transaction(function () use ($availability, $startsAt, $endsAt): Availability {
            $duplicate = Dibs::query(Availability::class)->create([
                'context_type' => $availability->context_type,
                'context_id' => $availability->context_id,
                'type' => $availability->type,
                'name' => $availability->name,
                'location' => $availability->location,
                'starts_at' => $startsAt->utc(),
                'ends_at' => $endsAt->utc(),
                'slot_duration_minutes' => $availability->slot_duration_minutes,
                'slot_padding_minutes' => $availability->slot_padding_minutes,
                'min_notice_minutes' => $availability->min_notice_minutes,
                'max_horizon_days' => $availability->max_horizon_days,
                'status' => AvailabilityStatus::Draft,
                'meta' => $availability->meta,
            ]);

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
