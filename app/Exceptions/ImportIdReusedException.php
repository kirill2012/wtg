<?php

namespace App\Exceptions;

/**
 * The supplier sent other content under an external_import_id it had already used.
 */
class ImportIdReusedException extends ConflictException
{
    public function __construct()
    {
        parent::__construct('This external_import_id was already used with different content.');
    }
}
