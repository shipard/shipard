<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer\Detail;

/**
 * Hlavička detailu záznamu, kterou viewer dodá builderu
 * (`TableViewer::detailHeader()`, #136 D18–D19): titulek je to, podle
 * čeho uživatel záznam pozná (název, protistrana), kód nebo číslo jde do
 * podtitulku, ikona je klíč z `icons.js`. Štítky sem záměrně nepatří —
 * štítek stavu přidává builder a nejde ho obejít (D20).
 */
final class DetailHeader
{
    public readonly string $title;
    public readonly ?string $subtitle;
    public readonly ?string $icon;

    public function __construct(string $title, ?string $subtitle = null, ?string $icon = null)
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Detail header title must not be empty');
        }
        $subtitle = $subtitle !== null ? trim($subtitle) : null;

        $this->title = $title;
        $this->subtitle = $subtitle === '' ? null : $subtitle;
        $this->icon = $icon === '' ? null : $icon;
    }
}
