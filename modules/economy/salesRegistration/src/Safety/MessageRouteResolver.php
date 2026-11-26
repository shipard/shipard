<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Safety;

use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;

/**
 * Jediné místo, které rozhoduje, kam zpráva jde (#171 D9, D10):
 *
 * | Pojistka     | Režim registrace | Prostředí  | Identita              | Ověření       |
 * |--------------|------------------|------------|-----------------------|---------------|
 * | `off`        | `production`     | produkce   | certifikát registrace | podle zprávy  |
 * | `off`        | `verification`   | produkce   | certifikát registrace | vždy          |
 * | `off`        | `playground`     | playground | testovací             | podle zprávy  |
 * | `playground` | jakýkoli         | playground | testovací             | podle režimu  |
 * | `drop`       | jakýkoli         | nic        | —                     | —             |
 *
 * Ověření má jedno pravidlo: zpráva ho žádá, nebo je registrace v ověřovacím
 * režimu. Pojistka má vždy přednost před režimem registrace — s pojistkou
 * `playground` nikdy nejde certifikát zdroje dat ven.
 */
final class MessageRouteResolver
{
    public static function resolve(
        SalesRegistrationServerConfig $safety,
        string $registrationMode,
        bool $verificationRequested = false,
    ): MessageRoute {
        if ($safety->isDrop()) {
            return MessageRoute::drop(match ($safety->source) {
                SalesRegistrationServerConfig::SOURCE_DEFAULT => MessageRoute::DROP_DEFAULT,
                SalesRegistrationServerConfig::SOURCE_INVALID => MessageRoute::DROP_INVALID,
                default                                       => MessageRoute::DROP_CONFIGURED,
            }, $safety->problem);
        }
        if (!in_array($registrationMode, RegistrationDocument::MODES, true)) {
            return MessageRoute::drop(MessageRoute::DROP_UNKNOWN_MODE);
        }

        $verification = $verificationRequested || $registrationMode === RegistrationDocument::MODE_VERIFICATION;

        if ($safety->mode === SalesRegistrationServerConfig::MODE_PLAYGROUND
            || $registrationMode === RegistrationDocument::MODE_PLAYGROUND
        ) {
            return MessageRoute::to(SalesRegistrationType::ENV_PLAYGROUND, MessageRoute::IDENTITY_TEST, $verification);
        }

        return MessageRoute::to(SalesRegistrationType::ENV_PRODUCTION, MessageRoute::IDENTITY_REGISTRATION, $verification);
    }
}
