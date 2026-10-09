<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Claim runneru během běhu zanikl (lease nešla prodloužit — uvolnil ho
 * reaper nebo jiná cesta). Runner končí bez zápisu; zprávu už má zpět
 * ve frontě ten, kdo claim uvolnil.
 */
final class AnalysisClaimLostException extends \RuntimeException
{
}
