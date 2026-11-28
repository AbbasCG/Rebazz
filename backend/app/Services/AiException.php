<?php

namespace App\Services;

use RuntimeException;

/** Een AI-aanvraag die niet (meer) kan: niet ingesteld, limiet bereikt, mislukt of geweigerd. De boodschap is bedoeld voor de medewerker. */
class AiException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }
}
