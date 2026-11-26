<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Engine;

/**
 * Identita, pod kterou se zpráva podepisuje: cesty k dočasným souborům klíče
 * a certifikátu (`KeyFiles`, #171 U1) a identifikátor poplatníka certifikátu.
 * `test` = sdílená testovací identita, ne certifikát registrace.
 */
final readonly class SenderIdentity
{
    public function __construct(
        public string $keyPath,
        public string $certificatePath,
        public string $taxpayerId,
        public bool $test = false,
    ) {
    }
}
