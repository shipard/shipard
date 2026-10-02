<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

/**
 * Strukturovaný kód selhání akce předzpracování
 * (tasks/mail-preprocess-error-messages.md D1). Nese ho ActionResult::$code
 * a runner ho ukládá do `preprocess_log.results[].code` vedle volné
 * poznámky `note`. Lidskou hlášku pak PreprocessErrorPresenter vybírá
 * podle kódu, žádný rozbor textu poznámky. Klíče odpovídají kategoriím
 * katalogu `core.mail.preprocessErrorKinds`.
 */
final class PreprocessFailureCode
{
    /** V těle zprávy není odkaz odpovídající pravidlu. */
    public const LINK_NOT_FOUND = 'linkNotFound';
    /** Server odesílatele dokument nevydal — 4xx mimo 408/429 (vypršelý odkaz, přihlášení). */
    public const LINK_EXPIRED = 'linkExpired';
    /** Transportní chyba, timeout, 5xx, 408, 429 — dočasná nedostupnost. */
    public const REMOTE_UNAVAILABLE = 'remoteUnavailable';
    /** Odkaz vede na nepoužitelný obsah: jiný formát, prázdné / příliš velké tělo, vadné přesměrování. */
    public const UNEXPECTED_CONTENT = 'unexpectedContent';
    /** Převod HTML těla zprávy do PDF neproběhl. */
    public const BODY_RENDER = 'bodyRender';
    /** Chyba v nastavení pravidla: parametry, neznámá akce, finální URL mimo allowlist / regex. */
    public const RULE_CONFIG = 'ruleConfig';
    /** Interní chyba Shipardu: výjimka, uložení přílohy, render klient, vzdaný sweep. */
    public const INTERNAL = 'internal';

    /**
     * Pořadí od nejkonkrétnějšího pro uživatele (D2, U5): sejde-li se víc
     * kódů (kandidátní odkazy jedné akce, víc selhaných akcí jedné zprávy),
     * hláška se vybírá podle tohoto žebříčku.
     *
     * @var list<string>
     */
    public const PRIORITY = [
        self::LINK_EXPIRED,
        self::RULE_CONFIG,
        self::UNEXPECTED_CONTENT,
        self::BODY_RENDER,
        self::REMOTE_UNAVAILABLE,
        self::INTERNAL,
        self::LINK_NOT_FOUND,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::PRIORITY, true);
    }

    /**
     * Nejkonkrétnější z daných kódů podle PRIORITY. Neznámé a prázdné
     * hodnoty se ignorují; bez použitelného kódu null.
     *
     * @param iterable<mixed> $codes
     */
    public static function mostSpecific(iterable $codes): ?string
    {
        $best = null;
        foreach ($codes as $code) {
            if (!is_string($code)) {
                continue;
            }
            $rank = array_search($code, self::PRIORITY, true);
            if ($rank === false) {
                continue;
            }
            if ($best === null || $rank < $best) {
                $best = $rank;
            }
        }
        return $best === null ? null : self::PRIORITY[$best];
    }
}
