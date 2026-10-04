<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Attachments;

/**
 * Ochrana příloh záznamu před změnou (#55 X16). Modul, jehož záznamy si
 * přílohy generují samy nebo je vážou na nevratný stav, si tu řekne, kdy
 * se s nimi nesmí hýbat.
 *
 * Registruje se v `module.jsonc`:
 *
 * ```jsonc
 * "attachmentGuards": [
 *     { "table": "economy_vat_filings", "class": "Shipard\\Module\\…\\FilingAttachmentGuard" }
 * ]
 * ```
 *
 * Guard je **doplněk oprávnění, ne jejich náhrada**: běží v `AttachmentService`
 * u operací, které mění existující přílohu (smazání, přejmenování,
 * pořadí, příznak odeslání), a u nahrání nové. Nahrání většina guardů
 * pouští — k podanému tvrzení může být potřeba doložit potvrzení o přijetí;
 * odmítá ho jen záznam s pevným obsahem (odeslaná zpráva).
 *
 * Implementace dostane připojení ke zdroji dat a musí být levná: volá se
 * při každé změně přílohy.
 */
interface AttachmentGuard
{
    /** Operace nad existující přílohou. */
    public const OPERATION_DELETE  = 'delete';
    public const OPERATION_RENAME  = 'rename';
    public const OPERATION_REORDER = 'reorder';
    /** Změna příznaku „odeslat se záznamem“ (`send_with_record`, #94 D6). */
    public const OPERATION_SEND_FLAG = 'sendFlag';
    /**
     * Nahrání (nebo zkopírování) nové přílohy k záznamu. `$attachment` je
     * v tu chvíli jen `table_id`, `record_id` a `name` — řádek ještě není.
     */
    public const OPERATION_UPLOAD = 'upload';

    /**
     * Důvod odmítnutí (hláška pro uživatele), nebo `null` když je operace
     * v pořádku.
     *
     * @param array<string, mixed> $attachment řádek `core_attachments_files`
     * @param string $operation jedna z `OPERATION_*`
     */
    public function refuse(array $attachment, string $operation): ?string;
}
