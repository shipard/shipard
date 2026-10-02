<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

/**
 * Jedna akce předzpracování (klíč viz cfgItem `core.mail.preprocessActions`).
 * Implementace musí být idempotentní vůči opakovanému běhu nad stejnou
 * zprávou (provenance metadata příloh, D5) a nesmí házet výjimky za
 * provozní selhání — ta vrací jako ActionResult::failure() s kódem
 * {@see PreprocessFailureCode} (D1 — hláška pro uživatele se vybírá
 * podle kódu, ne podle textu poznámky).
 */
interface PreprocessAction
{
    /**
     * @param array<string, mixed> $message Řádek `core_mail_incoming_messages`.
     * @param string $ruleId `rule_id` pravidla, ze kterého akce pochází (provenance).
     * @param array<string, mixed> $params Parametry akce z plánu (vč. klíče `action`).
     */
    public function execute(array $message, string $ruleId, array $params): ActionResult;
}
