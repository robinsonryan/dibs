<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RobinsonRyan\Dibs\Data\UnavailabilitySpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\UnavailabilityKind;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Models\Unavailability;
use RobinsonRyan\Dibs\Models\UnavailabilityWindow;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\OverlapCheck;
use RobinsonRyan\Dibs\Support\SeriesClock;

/**
 * Which live appointments an away falls across — asked before it is recorded,
 * so the consumer can put the question to a person rather than cancelling on
 * their behalf. A pure read: it writes nothing and locks nothing.
 *
 * It takes the away either way round. A saved `Unavailability` is the "what
 * would this edit strand" reading; an `UnavailabilitySpec` is the same question
 * asked of an away that does not exist yet, which is the one a form asks.
 *
 * Whose appointments they are follows from what the scope is, and the answer
 * does not need to be told which: a **host** scope reports the appointments
 * that host is on, and a **context** scope reports every appointment on that
 * context's calendar — the booking's own context, or its day's. One model can
 * only ever be one of the two, so asking both questions is asking the right one.
 *
 * Forward only, from `$from` (the clock by default): an away covering an
 * evening that has already happened strands nobody.
 */
final class FindUnavailabilityConflicts
{
    /**
     * @return Collection<int, Booking>
     */
    public function __invoke(Unavailability|UnavailabilitySpec $away, ?CarbonInterface $from = null): Collection
    {
        $away = $away instanceof UnavailabilitySpec ? $this->probe($away) : $away;
        $from = Slot::instant($from);
        $until = $this->until($away);

        if ($until instanceof CarbonImmutable && $until->lessThanOrEqualTo($from)) {
            return new Collection;
        }

        return $this->claims($away, $from, $until)
            // The outer bounds are all SQL can judge: whether a standing away
            // actually falls across a given appointment is a wall-clock
            // question, and `covers()` is where it is asked.
            ->filter(fn (Booking $booking): bool => $booking->slot instanceof Slot
                && $away->covers($booking->slot->starts_at, $booking->slot->ends_at))
            ->values();
    }

    /**
     * The live claims on this scope inside the away's outer bounds.
     *
     * @return Collection<int, Booking>
     */
    private function claims(Unavailability $away, CarbonImmutable $from, ?CarbonImmutable $until): Collection
    {
        return Dibs::query(Booking::class)
            ->active()
            ->whereHas('slot', fn (Builder $slots): Builder => $until instanceof CarbonImmutable
                ? OverlapCheck::overlappingSlots($slots, $from, $until)
                : $slots->where(Dibs::make(Slot::class)->qualifyColumn('ends_at'), '>', $from))
            ->where(fn (Builder $scoped): Builder => $this->whereScopeIsInvolved($scoped, $away))
            ->with('slot')
            ->get();
    }

    /**
     * The scope is on the appointment, or the appointment is on the scope's
     * calendar — the host reading and the context reading of one morph.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    private function whereScopeIsInvolved(Builder $query, Unavailability $away): Builder
    {
        $booking = Dibs::make(Booking::class);
        $assignment = Dibs::make(BookingHost::class);
        $availability = Dibs::make(Availability::class);

        return $query
            ->whereHas('hosts', fn (Builder $hosts): Builder => $hosts
                ->where($assignment->qualifyColumn('host_type'), $away->scope_type)
                ->where($assignment->qualifyColumn('host_id'), $away->scope_id))
            ->orWhere(fn (Builder $own): Builder => $own
                ->where($booking->qualifyColumn('context_type'), $away->scope_type)
                ->where($booking->qualifyColumn('context_id'), $away->scope_id))
            ->orWhereHas('slot.availability', fn (Builder $days): Builder => $days
                ->where($availability->qualifyColumn('context_type'), $away->scope_type)
                ->where($availability->qualifyColumn('context_id'), $away->scope_id));
    }

    /**
     * The instant after which this away certainly covers nothing — the end of a
     * one-off span, or the end of the last day a standing away names. Null when
     * a standing away runs until somebody removes it.
     */
    private function until(Unavailability $away): ?CarbonImmutable
    {
        if ($away->kind === UnavailabilityKind::Once) {
            return $away->ends_at;
        }

        return $away->ends_on instanceof CarbonImmutable
            ? SeriesClock::instantOn(SeriesClock::date($away->ends_on)->addDay(), 0, $away->timezone)
            : null;
    }

    /**
     * The proposed away as an `Unavailability` that was never saved, so the
     * time it covers is read by exactly the code that reads a real one.
     */
    private function probe(UnavailabilitySpec $spec): Unavailability
    {
        $spec->ensureValid();

        $probe = Dibs::make(Unavailability::class);
        $probe->scope_type = $spec->scope->getMorphClass();
        $probe->scope_id = (string) $spec->scope->getKey();
        $probe->kind = $spec->kind;
        $probe->starts_at = $spec->startsAt;
        $probe->ends_at = $spec->endsAt;
        $probe->timezone = $spec->timezone;
        $probe->starts_on = $spec->startsOn;
        $probe->ends_on = $spec->endsOn;

        $probe->setRelation('windows', new Collection(array_map(
            fn (WindowSpec $window): UnavailabilityWindow => Dibs::make(UnavailabilityWindow::class)->forceFill([
                'weekday' => $window->weekday,
                'starts_at_minutes' => $window->startsAtMinutes,
                'ends_at_minutes' => $window->endsAtMinutes,
            ]),
            $spec->windows,
        )));

        return $probe;
    }
}
