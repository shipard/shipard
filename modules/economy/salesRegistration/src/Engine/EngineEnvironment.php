<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Engine;

/** Prostředí, do kterého engine zprávu pošle: název z dat typu a adresa služby. */
final readonly class EngineEnvironment
{
    public function __construct(
        public string $name,
        public string $url,
    ) {
    }
}
