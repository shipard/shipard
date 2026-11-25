<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Akce záznamu s obsluhou v modulu (`recordActions` v `module.jsonc`,
 * #172 D12–D15) — tlačítko v detailu záznamu tabulky, kterou modul vlastní
 * i nevlastní. Třída říká, jestli je akce pro záznam dostupná, jak vypadá
 * (popisek, potvrzení, nepovinný dialog) a co udělá; jádro dodává jeden
 * endpoint (`POST /_ui/record-action/{table}/{id}/{akce}`), jednu obsluhu
 * ve frontendu a jeden obecný dialog.
 *
 * Třída vzniká bez argumentů konstruktoru. Záznam je **holý řádek tabulky**
 * (`SELECT *` podle id, včetně sloupců z rozšíření) — stejný v detailu
 * i při provedení, bez JOINů vieweru.
 *
 * Výjimka z `available()` / `describe()` při vykreslení detailu znamená
 * zalogování a vynechání akce — detail se vykreslí bez ní (D19).
 */
interface RecordAction
{
    /**
     * Je akce pro záznam dostupná? Volá se při každém vykreslení detailu,
     * má být levná, a znovu těsně před provedením (záznam se mezitím mohl
     * změnit — pak endpoint vrací 409). Zámek ani stav záznamu endpoint
     * sám nehlídá: jestli akce smí běžet nad zamčeným nebo potvrzeným
     * záznamem, rozhoduje tady akce.
     *
     * @param array<string, mixed> $record
     */
    public function available(string $table, array $record, RecordActionContext $context): bool;

    /**
     * Popis akce pro detail: popisek, varianta, potvrzení, dialog. Texty
     * z katalogu modulu (`CatalogText`) v jazyce `$context->language`.
     *
     * @param array<string, mixed> $record
     */
    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor;

    /**
     * Provedení. Vstup je zvalidovaný proti polím dialogu z `describe()`;
     * chybu, kterou pozná až akce (špatné heslo k certifikátu), hlásí
     * {@see RecordActionValidationException} s chybami polí. Jiná výjimka
     * je chyba serveru (500, detail jen do logu).
     *
     * @param array<string, mixed> $record
     */
    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult;
}
