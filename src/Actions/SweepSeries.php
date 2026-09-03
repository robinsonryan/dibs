<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;
use RobinsonRyan\Dibs\Support\SlotStatusSweep;
use Throwable;

/**
 * The nightly job a consumer schedules (as with `ExpireOffers`, the package
 * ships no commands of its own — see the README).
 *
 * Three things, for every series that is open for booking: roll it forward to
 * its horizon so times keep appearing without anybody doing anything; retire
 * the unclaimed times that have been and gone; and end a series whose last date
 * has passed on **its own** calendar, which is why `ends_on` is compared
 * against the series' local today and not the server's.
 *
 * Rolling forward goes through `RegenerateSeries`, not straight to
 * materialisation, because a day the last edit could not touch — one carrying a
 * booking or a slot an offer was holding — is still standing on the old rule.
 * This is where it is caught up, the night after the claim is settled, without
 * anybody having to edit the rule a second time. A series with nothing stale
 * pays one extra query for the question.
 *
 * Each series is settled in its own transaction and one failure does not stop
 * the sweep — the rest still roll forward — with the first failure rethrown at
 * the end so the scheduler still hears that something went wrong.
 */
final class SweepSeries
{
    /**
     * @return int occurrences created across the whole sweep
     *
     * @throws Throwable the first failure, after every other series was tried
     */
    public function __invoke(?CarbonInterface $now = null): int
    {
        $moment = Slot::instant($now);

        $series = Dibs::query(Series::class)
            ->active()
            ->orderBy('id')
            ->get();

        $created = 0;
        $failure = null;

        foreach ($series as $one) {
            try {
                $created += $this->sweep($one, $moment);
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        return $created;
    }

    private function sweep(Series $series, CarbonImmutable $moment): int
    {
        $this->retireWhatHasPassed($series, $moment);

        $today = SeriesClock::today($series->timezone);

        if ($series->ends_on !== null && $series->ends_on->format('Y-m-d') < $today->format('Y-m-d')) {
            return DB::transaction(function () use ($series): int {
                $locked = Dibs::lock($series);

                if ($locked instanceof Series && $locked->status === SeriesStatus::Active) {
                    $locked->status = SeriesStatus::Ended;
                    $locked->save();
                }

                return 0;
            });
        }

        // Regeneration derives the same horizon this method would: three
        // months when the series names none — far enough that a leader sees the
        // rule working, near enough that a rule change does not rewrite a year
        // of rows.
        return (new RegenerateSeries)($series);
    }

    /**
     * Times that have been and gone and nobody claimed: they can never be
     * booked again, so they step aside rather than accumulating as open rows.
     * One that carries a live booking is left alone — it is the record of an
     * appointment that happened.
     */
    private function retireWhatHasPassed(Series $series, CarbonImmutable $moment): void
    {
        DB::transaction(function () use ($series, $moment): void {
            SlotStatusSweep::retire(
                Dibs::query(Slot::class)
                    ->whereIn('availability_id', Dibs::query(Availability::class)
                        ->where('series_id', $series->getKey())
                        ->select('id'))
                    ->where('status', SlotStatus::Open->value)
                    ->where('ends_at', '<=', $moment)
                    ->whereDoesntHave('activeBookings'),
            );
        });
    }
}
