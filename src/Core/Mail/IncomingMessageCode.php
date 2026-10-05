<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

/**
 * Kód došlé zprávy (`core_mail_incoming_messages.message_id`) v zobrazovacím
 * tvaru. Uložený formát `MSG-YYYYMMDD-NNNN` se nemění
 * (tasks/mail-source-message-link.md D5); UI ukazuje krátký tvar
 * `YYMMDD-NNNN`, který je jednoznačný napříč roky a je podřetězcem plného
 * kódu, takže ho najde fulltext Došlé pošty (D8). Plný kód zůstává
 * v tooltipu, v Technických údajích detailu zprávy a v `displayPattern`.
 *
 * Žije v `src/Core`, ne v modulu: používá ho `core.mail` i `docs.core`,
 * který na `core.mail` nezávisí.
 */
final class IncomingMessageCode
{
    /**
     * `MSG-20260905-0012` → `260905-0012`; kód, který vzoru neodpovídá
     * (seed `TEST-MSG-0001`, fallback exportu `MSG-17`, prázdný řetězec),
     * se vrací beze změny (D8). `\d{4,}`: sekvence se doplňuje na 4 číslice,
     * nad 9 999 zpráv za den by byla delší.
     */
    public static function short(string $code): string
    {
        return preg_match('/^MSG-\d{2}(\d{6}-\d{4,})$/', $code, $m) === 1
            ? $m[1]
            : $code;
    }
}
