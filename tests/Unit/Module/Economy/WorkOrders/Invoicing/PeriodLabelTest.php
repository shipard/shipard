<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Invoicing;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Module\Economy\WorkOrders\Invoicing\DocTextTemplate;
use Shipard\Module\Economy\WorkOrders\Invoicing\Period;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodLabel;

/**
 * `{období}` (D12, Q6) ve čtyřech jazycích dokumentů podle periodicity
 * a text dokladu z předpisu.
 */
class PeriodLabelTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../../modules/economy/workOrders';

    /** Katalog periodTexts zkompilovaný v jazyce (jako compiled.<lang>.json). */
    private function texts(string $language): array
    {
        return ConfigLocalizer::localize(JsoncParser::parseFile(self::MODULE . '/config/periodTexts.jsonc'), $language);
    }

    public function testMonthQuarterHalfYearAndYearInFourLanguages(): void
    {
        $month = new Period('2026-10-01', '2026-10-31');
        $quarter = new Period('2026-07-01', '2026-09-30');
        $half = new Period('2026-07-01', '2026-12-31');
        $year = new Period('2026-01-01', '2026-12-31');

        $expected = [
            'cs' => ['říjen 2026', '3. čtvrtletí 2026', '2. pololetí 2026', '2026'],
            'en' => ['October 2026', 'Q3 2026', 'H2 2026', '2026'],
            'sk' => ['október 2026', '3. štvrťrok 2026', '2. polrok 2026', '2026'],
            'de' => ['Oktober 2026', '3. Quartal 2026', '2. Halbjahr 2026', '2026'],
        ];
        foreach ($expected as $language => [$m, $q, $h, $y]) {
            $texts = $this->texts($language);
            $this->assertSame($m, PeriodLabel::format($month, 'month', $language, $texts), $language);
            $this->assertSame($q, PeriodLabel::format($quarter, 'quarter', $language, $texts), $language);
            $this->assertSame($h, PeriodLabel::format($half, 'halfyear', $language, $texts), $language);
            $this->assertSame($y, PeriodLabel::format($year, 'year', $language, $texts), $language);
        }
    }

    public function testWithoutCatalogFallsBackToEnglishTexts(): void
    {
        $this->assertSame('Q1 2027', PeriodLabel::format(new Period('2027-01-01', '2027-03-31'), 'quarter', 'cs', null));
        $this->assertSame('H1 2027', PeriodLabel::format(new Period('2027-01-01', '2027-06-30'), 'halfyear', 'de', []));
        // Měsíc jde přes intl i bez katalogu; neznámý jazyk = angličtina.
        $this->assertSame('March 2027', PeriodLabel::format(new Period('2027-03-01', '2027-03-31'), 'month', 'xx', null));
    }

    public function testDocTextTemplateReplacesPlaceholderOrBuildsDefault(): void
    {
        $this->assertSame('Nájem kanceláře — říjen 2026', DocTextTemplate::render('Nájem kanceláře — {období}', 'Smlouva 12', 'říjen 2026'));
        $this->assertSame('Rent for October 2026', DocTextTemplate::render('Rent for {period}', 'x', 'October 2026'));
        $this->assertSame('Smlouva 12 říjen 2026', DocTextTemplate::render('  ', 'Smlouva 12', 'říjen 2026'));
        $this->assertSame('Smlouva 12 říjen 2026', DocTextTemplate::render(null, 'Smlouva 12', 'říjen 2026'));
        $this->assertSame(200, mb_strlen(DocTextTemplate::render(str_repeat('ř', 250), 'x', 'y')));
    }
}
