<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\CatalogText;

/**
 * Zkušební účtenka profilu tiskárny (#177 task 4a): ukáže, jestli profil
 * tiskárně sedí — parametry profilu, pravítko na šířku řádku v obou písmech,
 * diakritika velkými i malými, tučné a dvojité písmo, čas a ořez.
 *
 * Skládá se stejnou cestou jako každá jiná účtenka (značky → sazba → kodér),
 * takže prověří i renderer. Tiskové úlohy ji posílají jako druh `test`.
 */
final class PrinterTestReceipt
{
    public const CFG_ITEM = 'core.prints.printerTestTexts';

    /** Věta se všemi českými písmeny s diakritikou. */
    public const PANGRAM = 'Příliš žluťoučký kůň úpěl ďábelské ódy';

    private const RULER = '1234567890';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param mixed $texts katalog popisků (`core.prints.printerTestTexts`);
     *        bez něj anglicky
     * @param (\Closure(): \DateTimeImmutable)|null $clock čas pro testy a vzory
     */
    public function __construct(
        private readonly EscPosDrivers $drivers,
        private readonly mixed $texts = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public static function fromConfig(?ConfigRuntime $config, ?\Closure $clock = null): self
    {
        return new self(EscPosDrivers::fromConfig($config), $config?->cfgItem(self::CFG_ITEM), $clock);
    }

    /** @throws EscPosConfigException Ovladač nebo kódová stránka profilu chybí. */
    public function render(PrinterProfile $profile): EscPosOutput
    {
        return (new EscPosRenderer($this->drivers))->render($this->markup($profile), $profile);
    }

    /** Značky zkušební účtenky — vstup `EscPosRenderer`. */
    public function markup(PrinterProfile $profile): string
    {
        $pangram  = self::xml(self::PANGRAM);
        $capitals = self::xml(mb_strtoupper(self::PANGRAM, 'UTF-8'));
        $features = $profile->features === []
            ? $this->text('noFeatures', 'none')
            : implode(', ', $profile->features);

        $rows = [
            [$this->text('profile', 'Profile'), $profile->id],
            [$this->text('columns', 'Characters per line'), "{$profile->columns} / {$profile->columnsSmall}"],
            [$this->text('dots', 'Dots per line'), (string) $profile->dotsPerLine],
            [$this->text('codePage', 'Code page'), $profile->codePage],
            [$this->text('features', 'Features'), $features],
        ];

        $lines   = ['<receipt>'];
        $lines[] = '<text align="center" bold="1" size="2x2">' . self::xml($this->text('title', 'TEST RECEIPT')) . '</text>';
        $lines[] = '<text align="center">' . self::xml($profile->name) . '</text>';
        $lines[] = '<line/>';
        foreach ($rows as [$label, $value]) {
            $lines[] = '<row><l>' . self::xml($label) . '</l><r>' . self::xml($value) . '</r></row>';
        }
        $lines[] = '<line/>';
        // Pravítko: poslední číslice musí stát na pravém okraji papíru.
        $lines[] = '<text>' . self::ruler($profile->columns) . '</text>';
        $lines[] = '<text font="small">' . self::ruler($profile->columnsSmall) . '</text>';
        $lines[] = '<line/>';
        $lines[] = "<text>{$pangram}</text>";
        $lines[] = "<text>{$capitals}</text>";
        $lines[] = "<text font=\"small\">{$pangram}</text>";
        $lines[] = "<text font=\"small\">{$capitals}</text>";
        $lines[] = "<text bold=\"1\">{$pangram}</text>";
        $lines[] = "<text size=\"2x2\">{$pangram}</text>";
        $lines[] = '<line/>';
        $lines[] = '<text align="center">' . ($this->clock)()->format('Y-m-d H:i:s') . '</text>';
        $lines[] = '<feed lines="2"/>';
        $lines[] = '<cut/>';
        $lines[] = '</receipt>';

        return implode("\n", $lines) . "\n";
    }

    private static function ruler(int $width): string
    {
        return substr(str_repeat(self::RULER, intdiv($width, strlen(self::RULER)) + 1), 0, $width);
    }

    private function text(string $key, string $fallback): string
    {
        return CatalogText::resolve($this->texts, $key, $fallback);
    }

    private static function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
