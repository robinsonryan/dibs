<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;

/**
 * Which live appointments a proposed rule would strand — asked before the rule
 * is saved, so the consumer can put the question to a person rather than
 * cancelling on their behalf. A pure read: it writes nothing and locks nothing.
 *
 * A booking is stranded when the proposed rule would not open its day at all,
 * or would open it but no longer across the time that was booked. A shorter
 * horizon is deliberately not a conflict: the horizon says how far ahead times
 * are *offered*, and an appointment somebody already has is not withdrawn
 * because the window for making new ones narrowed.
 *
 * Past bookings and days somebody detached are ignored for the same reasons
 * regeneration leaves them alone.
 */
final class FindSeriesConflicts
{
    /**
     * @return Collection<int, Booking>
     */
    public function __invoke(Series $series, SeriesSpec $proposed): Collection
    {
        $bookings = $this->futureBookings($series);

        if ($bookings->isEmpty()) {
            return $bookings;
        }

        $probe = $this->probe($series, $proposed);

        return $bookings
            ->reject(fn (Booking $booking): bool => $this->stillFits($booking, $probe, $proposed))
            ->values();
    }

    /**
     * Live bookings on this series' own future days, detached ones excluded.
     *
     * @return Collection<int, Booking>
     */
    private function futureBookings(Series $series): Collection
    {
        $now = Slot::instant(null);

        return Dibs::query(Booking::class)
            ->active()
            ->whereHas('slot', fn (Builder $slots): Builder => $slots
                ->where('starts_at', '>', $now)
                ->whereHas('availability', fn (Builder $days): Builder => $days
                    ->where('series_id', $series->getKey())
                    ->whereNull('detached_at')))
            ->with('slot.availability')
            ->get();
    }

    /**
     * Does the proposed rule still open this booking's time?
     *
     * Asked against the day the booking was made on and in instants, not wall
     * clocks: the proposed windows are placed on that date through the same
     * conversion materialisation would use, and the slot has to sit wholly
     * inside one of them.
     */
    private function stillFits(Booking $booking, Series $probe, SeriesSpec $proposed): bool
    {
        $slot = $booking->slot;

        if (! $slot instanceof Slot) {
            return true;
        }

        $day = $slot->availability;

        if (! $day instanceof Availability || ! $day->occurs_on instanceof CarbonImmutable) {
            return true;
        }

        $date = $day->occurs_on;

        if (! $probe->occursOn($date)) {
            return false;
        }

        foreach ($proposed->windows as $window) {
            if ($window->weekday !== $date->dayOfWeek) {
                continue;
            }

            if ($this->coveredBy($slot, $date, $window, $proposed->timezone)) {
                return true;
            }
        }

        return false;
    }

    private function coveredBy(Slot $slot, CarbonImmutable $date, WindowSpec $window, string $timezone): bool
    {
        $opens = SeriesClock::instantOn($date, $window->startsAtMinutes, $timezone);
        $closes = SeriesClock::instantOn($date, $window->endsAtMinutes, $timezone);

        return $slot->starts_at->greaterThanOrEqualTo($opens)
            && $slot->ends_at->lessThanOrEqualTo($closes);
    }

    /**
     * The proposed rule as a Series that was never saved, so the calendar it
     * describes is read by exactly the code that reads a real one.
     */
    private function probe(Series $series, SeriesSpec $proposed): Series
    {
        $probe = Dibs::make(Series::class);
        $probe->cadence = $proposed->cadence;
        $probe->ordinals = $proposed->ordinals;
        $probe->starts_on = $proposed->startsOn;
        $probe->ends_on = $proposed->endsOn;
        $probe->timezone = $proposed->timezone;

        $windows = new Collection(array_map(
            fn (WindowSpec $window) => Dibs::make(\RobinsonRyan\Dibs\Models\SeriesWindow::class)->forceFill([
                'series_id' => $series->getKey(),
                'weekday' => $window->weekday,
                'starts_at_minutes' => $window->startsAtMinutes,
                'ends_at_minutes' => $window->endsAtMinutes,
            ]),
            $proposed->windows,
        ));

        $probe->setRelation('windows', $windows);

        return $probe;
    }
}
