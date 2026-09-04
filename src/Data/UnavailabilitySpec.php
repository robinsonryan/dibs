<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Data;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Enums\UnavailabilityKind;
use RobinsonRyan\Dibs\Exceptions\InvalidUnavailability;

/**
 * A whole away, as the caller means it: whose time it is, which shape it takes,
 * when, and on which clock. `CreateUnavailability` and `UpdateUnavailability`
 * take nothing else.
 *
 * `ensureValid()` enforces only what is true of *any* consumer's away — the
 * shape has to be coherent and it has to say when. Rules that belong to a
 * domain (church hours are 6 am to 10 pm, a one-off may not start in the past,
 * a label is 40 characters) stay with the consumer, which is also the only
 * place that can phrase the refusal for a person to read.
 */
final readonly class UnavailabilitySpec
{
    /**
     * @param  list<WindowSpec>  $windows  weekday windows; a standing away only
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public Model $scope,
        public UnavailabilityKind $kind,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public string $timezone,
        public ?CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public array $windows,
        public ?string $label = null,
        public array $meta = [],
    ) {}

    /**
     * @throws InvalidUnavailability
     */
    public function ensureValid(): void
    {
        $this->ensureTimezoneExists();

        if ($this->kind === UnavailabilityKind::Once) {
            $this->ensureSpanIsCoherent();

            return;
        }

        $this->ensureStandingRuleIsCoherent();
    }

    /**
     * A one-off is its span and nothing else: both instants, in order, and no
     * weekday windows behind them — a row carrying both would read one way and
     * behave another.
     *
     * @throws InvalidUnavailability
     */
    private function ensureSpanIsCoherent(): void
    {
        if (! $this->startsAt instanceof CarbonImmutable || ! $this->endsAt instanceof CarbonImmutable) {
            throw InvalidUnavailability::because(InvalidUnavailability::SPAN_REQUIRED, 'A one-off away must say when it starts and when it ends.');
        }

        if ($this->endsAt->lessThanOrEqualTo($this->startsAt)) {
            throw InvalidUnavailability::because(InvalidUnavailability::SPAN_INVERTED, 'A one-off away must end after it starts.');
        }

        if ($this->windows !== []) {
            throw InvalidUnavailability::because(InvalidUnavailability::WINDOWS_FORBIDDEN, 'Only a standing away may carry weekday windows.');
        }
    }

    /**
     * A standing away is its windows and the dates it runs between: at least
     * one window, each inside its own day, a date to start on, and an end that
     * is not before it. Two windows on one weekday are allowed and simply mean
     * two stretches of that day; they do not have to leave room for anything
     * between them, because an away opens no times.
     *
     * @throws InvalidUnavailability
     */
    private function ensureStandingRuleIsCoherent(): void
    {
        if ($this->windows === []) {
            throw InvalidUnavailability::because(InvalidUnavailability::WINDOWS_REQUIRED, 'A standing away needs at least one window.');
        }

        if ($this->startsAt instanceof CarbonImmutable || $this->endsAt instanceof CarbonImmutable) {
            throw InvalidUnavailability::because(InvalidUnavailability::SPAN_FORBIDDEN, 'Only a one-off away may carry a span.');
        }

        if (! $this->startsOn instanceof CarbonImmutable) {
            throw InvalidUnavailability::because(InvalidUnavailability::STARTS_ON_REQUIRED, 'A standing away must say which day it starts on.');
        }

        if ($this->endsOn instanceof CarbonImmutable && $this->endsOn->lessThan($this->startsOn)) {
            throw InvalidUnavailability::because(InvalidUnavailability::ENDS_BEFORE_STARTS, 'A standing away must not end before the day it starts.');
        }

        foreach ($this->windows as $window) {
            $this->ensureWindowIsInsideItsDay($window);
        }
    }

    /**
     * @throws InvalidUnavailability
     */
    private function ensureWindowIsInsideItsDay(WindowSpec $window): void
    {
        if ($window->weekday < 0 || $window->weekday > 6) {
            throw InvalidUnavailability::because(InvalidUnavailability::WINDOWS_BOUNDS, 'A window must fall on a weekday from 0 (Sunday) to 6.');
        }

        if ($window->startsAtMinutes < 0 || $window->endsAtMinutes > 1440) {
            throw InvalidUnavailability::because(InvalidUnavailability::WINDOWS_BOUNDS, 'A window must fall inside its own day.');
        }

        if ($window->endsAtMinutes <= $window->startsAtMinutes) {
            throw InvalidUnavailability::because(InvalidUnavailability::WINDOWS_BOUNDS, 'A window must end after it starts.');
        }
    }

    /**
     * A timezone the runtime actually knows — refused here, before anything is
     * written, rather than surfacing much later as Carbon's own exception from
     * inside a read (the same trap `SeriesSpec` closes).
     *
     * A one-off away is checked too: it is stored with a clock so that an edit
     * can turn it into a standing one without inventing a zone.
     *
     * @throws InvalidUnavailability
     */
    private function ensureTimezoneExists(): void
    {
        if (! in_array($this->timezone, timezone_identifiers_list(), true)) {
            throw InvalidUnavailability::because(InvalidUnavailability::TIMEZONE_INVALID, 'An away must be kept on a timezone the system knows.');
        }
    }
}
