<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Konfigurace tisku na účtenkovou tiskárnu chybí nebo nemá očekávaný tvar
 * (ovladač, profil, kódová stránka). Typicky zdroj dat bez `ds-upgrade`.
 */
final class EscPosConfigException extends \RuntimeException
{
}
