<?php

namespace App\Exceptions;

class OfferUnavailableException extends ConflictException
{
    public static function expired(): self
    {
        return new self('The offer has expired.');
    }

    public static function soldOut(): self
    {
        return new self('The offer is sold out.');
    }
}
