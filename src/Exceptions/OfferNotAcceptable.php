<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Exceptions;

use RobinsonRyan\Dibs\Models\Offer;

final class OfferNotAcceptable extends DibsException
{
    public static function for(Offer $offer, string $reason): self
    {
        return new self(sprintf('Offer %s cannot be accepted: %s', $offer->getKey(), $reason));
    }
}
