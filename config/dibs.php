<?php

declare(strict_types=1);

use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\OfferSlot;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\SeriesHost;
use RobinsonRyan\Dibs\Models\SeriesWindow;
use RobinsonRyan\Dibs\Models\Slot;

return [

    /*
    |---------------------------------------------------------------------------
    | Table prefix
    |---------------------------------------------------------------------------
    |
    | Every table this package owns is prefixed, so two consumers cannot collide
    | and a consumer can rename them without forking the package.
    |
    */

    'table_prefix' => 'dibs_',

    /*
    |---------------------------------------------------------------------------
    | Models
    |---------------------------------------------------------------------------
    |
    | Substitute an extended model for any of the package's own. The value must
    | extend the package class it replaces; the package resolves every
    | relationship and query through this map, so the substitution is total.
    |
    */

    'models' => [
        Availability::class => Availability::class,
        AvailabilityHost::class => AvailabilityHost::class,
        Slot::class => Slot::class,
        Booking::class => Booking::class,
        BookingHost::class => BookingHost::class,
        Offer::class => Offer::class,
        OfferSlot::class => OfferSlot::class,
        Series::class => Series::class,
        SeriesWindow::class => SeriesWindow::class,
        SeriesHost::class => SeriesHost::class,
    ],

    /*
    |---------------------------------------------------------------------------
    | Offer token length
    |---------------------------------------------------------------------------
    |
    | Length of the random token an offer link carries. It is the only lookup
    | key a link needs, so it must stay long enough to be unguessable (>= 40).
    |
    */

    'token_length' => 48,

];
