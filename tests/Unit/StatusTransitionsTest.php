<?php

declare(strict_types=1);

use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\OfferStatus;

it('encodes the availability status machine', function (): void {
    expect(AvailabilityStatus::Draft->canTransitionTo(AvailabilityStatus::Published))->toBeTrue()
        ->and(AvailabilityStatus::Published->canTransitionTo(AvailabilityStatus::Closed))->toBeTrue()
        ->and(AvailabilityStatus::Closed->canTransitionTo(AvailabilityStatus::Published))->toBeTrue()
        ->and(AvailabilityStatus::Draft->canTransitionTo(AvailabilityStatus::Closed))->toBeFalse()
        ->and(AvailabilityStatus::Published->canTransitionTo(AvailabilityStatus::Draft))->toBeFalse()
        ->and(AvailabilityStatus::Closed->canTransitionTo(AvailabilityStatus::Draft))->toBeFalse()
        ->and(AvailabilityStatus::Published->canTransitionTo(AvailabilityStatus::Published))->toBeFalse();
});

it('encodes the booking status machine', function (): void {
    expect(BookingStatus::Booked->canTransitionTo(BookingStatus::Completed))->toBeTrue()
        ->and(BookingStatus::Booked->canTransitionTo(BookingStatus::Cancelled))->toBeTrue()
        ->and(BookingStatus::Booked->canTransitionTo(BookingStatus::NoShow))->toBeTrue()
        ->and(BookingStatus::Completed->canTransitionTo(BookingStatus::NoShow))->toBeTrue()
        ->and(BookingStatus::NoShow->canTransitionTo(BookingStatus::Completed))->toBeTrue()
        ->and(BookingStatus::Completed->canTransitionTo(BookingStatus::Cancelled))->toBeFalse()
        ->and(BookingStatus::Completed->canTransitionTo(BookingStatus::Booked))->toBeFalse()
        ->and(BookingStatus::Cancelled->canTransitionTo(BookingStatus::Booked))->toBeFalse()
        ->and(BookingStatus::Cancelled->canTransitionTo(BookingStatus::Completed))->toBeFalse()
        ->and(BookingStatus::Cancelled->canTransitionTo(BookingStatus::NoShow))->toBeFalse();
});

it('encodes the offer status machine', function (): void {
    expect(OfferStatus::Pending->canTransitionTo(OfferStatus::Accepted))->toBeTrue()
        ->and(OfferStatus::Pending->canTransitionTo(OfferStatus::Expired))->toBeTrue()
        ->and(OfferStatus::Pending->canTransitionTo(OfferStatus::Withdrawn))->toBeTrue()
        ->and(OfferStatus::Accepted->canTransitionTo(OfferStatus::Withdrawn))->toBeFalse()
        ->and(OfferStatus::Expired->canTransitionTo(OfferStatus::Pending))->toBeFalse()
        ->and(OfferStatus::Withdrawn->canTransitionTo(OfferStatus::Accepted))->toBeFalse();
});
