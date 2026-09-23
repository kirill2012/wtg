<?php

namespace App\Exceptions;

/**
 * The same client_reference on another offer is a different request, not a resend.
 */
class ClientReferenceTakenException extends ConflictException
{
    public function __construct()
    {
        parent::__construct('This client_reference already belongs to a reservation of another offer.');
    }
}
