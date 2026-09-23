<?php

namespace App\Events\Drives;

/** Integration boundary for the future ingestion worker. No processor is attached. */
final readonly class IntakeSubmitted
{
    public function __construct(public string $sessionUuid) {}
}
