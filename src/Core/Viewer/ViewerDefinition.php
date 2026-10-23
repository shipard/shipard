<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

/**
 * Registrace vieweru z `module.jsonc` `viewers[]`. `table` smí chybět
 * (null): viewer bez tabulky čte data z konfigurace (přehled modelů
 * `core.ai.modelCatalog`), přepisuje `TableViewer::loadRecord()`, nemá
 * formulář, zámky ani tisky a do hlavní navigace nejde — patří do
 * `settingsItems`.
 *
 * `detailHeader` (#136 D19) je výchozí hlavička detailu: `{title,
 * subtitle}` se vzory `{sloupec}` jako `displayPattern` tabulky; bez
 * deklarace je titulkem `displayPattern`. `detailAttachments` (D21) říká,
 * kde builder detailu kreslí přílohy záznamu: `overview` (konec Přehledu,
 * výchozí), `tab` (vlastní tab Přílohy), `content` (viewer si je kreslí
 * ve svém obsahu sám — builder je nenačítá).
 */
class ViewerDefinition
{
    public const ATTACHMENTS_OVERVIEW = 'overview';
    public const ATTACHMENTS_TAB = 'tab';
    public const ATTACHMENTS_CONTENT = 'content';

    public const ATTACHMENT_PLACEMENTS = [
        self::ATTACHMENTS_OVERVIEW,
        self::ATTACHMENTS_TAB,
        self::ATTACHMENTS_CONTENT,
    ];

    /**
     * @param array{title?: string, subtitle?: string}|null $detailHeader
     * @param 'overview'|'tab'|'content' $detailAttachments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $table,
        public readonly ?string $class,
        public readonly string $moduleId,
        public readonly ?string $icon = null,
        public readonly ?array $detailHeader = null,
        public readonly string $detailAttachments = self::ATTACHMENTS_OVERVIEW,
    ) {
        if (!in_array($detailAttachments, self::ATTACHMENT_PLACEMENTS, true)) {
            throw new \InvalidArgumentException(
                "Viewer '{$id}': detailAttachments must be one of "
                . implode(', ', self::ATTACHMENT_PLACEMENTS) . ", got '{$detailAttachments}'",
            );
        }
        foreach (['title', 'subtitle'] as $key) {
            $value = $detailHeader[$key] ?? null;
            if ($value !== null && (!is_string($value) || $value === '')) {
                throw new \InvalidArgumentException("Viewer '{$id}': detailHeader.{$key} must be a non-empty string");
            }
        }
    }
}
