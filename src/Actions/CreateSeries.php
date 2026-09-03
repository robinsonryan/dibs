<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Record a repeating rule: the series, its windows and its pool, at version 1.
 *
 * It materialises nothing. Laying the occurrences down is `MaterialiseSeries`,
 * which the consumer calls next with the horizon it wants — one verb each, so
 * "save the rule" and "how far ahead do we open times" stay separate decisions
 * and a rule can be recorded before anybody is allowed to book against it.
 */
final class CreateSeries
{
    public function __invoke(SeriesSpec $spec): Series
    {
        $spec->ensureValid();

        return DB::transaction(function () use ($spec): Series {
            $series = Dibs::query(Series::class)->create([
                'context_type' => $spec->context->getMorphClass(),
                'context_id' => (string) $spec->context->getKey(),
                'title' => $spec->title,
                'timezone' => $spec->timezone,
                'cadence' => $spec->cadence,
                'ordinals' => $spec->ordinals(),
                'starts_on' => $spec->startsOn,
                'ends_on' => $spec->endsOn,
                'slot_duration_minutes' => $spec->slotDurationMinutes,
                'slot_padding_minutes' => $spec->slotPaddingMinutes,
                'min_notice_minutes' => $spec->minNoticeMinutes,
                'max_horizon_days' => $spec->maxHorizonDays,
                'location' => $spec->location,
                'status' => SeriesStatus::Active,
                'rule_version' => 1,
                'meta' => $spec->meta,
            ]);

            SyncSeriesRule::windows($series, $spec);
            SyncSeriesRule::hosts($series, $spec);

            $series->load(['windows', 'hosts']);

            return $series;
        });
    }
}
