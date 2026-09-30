<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Depreciation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\DepreciationSettings;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;

/** Vstupní hodnotové objekty enginu a texty hlášení. */
class DepreciationInputsTest extends TestCase
{
    private const MESSAGES = __DIR__ . '/../../../../../../modules/economy/assets/config/planMessages.jsonc';

    public function testAssetEventFromTableRow(): void
    {
        $event = AssetEvent::fromArray([
            'id' => '15',
            'event_kind' => 'opening',
            'scope' => 'tax',
            'event_date' => '2024-01-01 00:00:00',
            'amount' => '3000000.00',
            'accumulated' => '960000.00',
            'units_done' => '10',
            'price_increased' => '0',
            'original_date' => '2014-06-01',
            'period_begin' => null,
            'period_end' => '',
            'origin' => 'import',
        ]);

        $this->assertSame(15, $event->id);
        $this->assertSame(AssetEvent::KIND_OPENING, $event->kind);
        $this->assertSame('2024-01-01', $event->date);
        $this->assertSame(3000000.0, $event->amount);
        $this->assertSame(960000.0, $event->accumulated);
        $this->assertSame(10, $event->unitsDone);
        $this->assertFalse($event->priceIncreased);
        $this->assertSame('2014-06-01', $event->originalDate);
        $this->assertNull($event->periodBegin);
        $this->assertNull($event->periodEnd);
        $this->assertTrue($event->confirmed);
        $this->assertTrue($event->isStart());
        $this->assertTrue($event->inCircuit('tax'));
        $this->assertFalse($event->inCircuit('acc'));
    }

    public function testBothScopeBelongsToBothCircuits(): void
    {
        $event = new AssetEvent(AssetEvent::KIND_IMPROVEMENT, AssetEvent::SCOPE_BOTH, '2023-05-10', 20000.0);

        $this->assertTrue($event->inCircuit('tax'));
        $this->assertTrue($event->inCircuit('acc'));
        $this->assertFalse($event->isStart());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidEvents(): array
    {
        return [
            'neznámý druh' => [['event_kind' => 'sale', 'event_date' => '2023-01-01']],
            'neznámý okruh' => [['event_kind' => 'activation', 'scope' => 'ifrs', 'event_date' => '2023-01-01']],
            'chybí datum' => [['event_kind' => 'activation']],
            'neplatné období' => [['event_kind' => 'depreciation', 'event_date' => '2023-12-31', 'period_end' => '2023']],
            'záporná částka' => [['event_kind' => 'activation', 'event_date' => '2023-01-01', 'amount' => -1]],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidEvents')]
    public function testAssetEventRejectsInvalidData(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssetEvent::fromArray($data);
    }

    public function testSettingsFromCardColumns(): void
    {
        $settings = DepreciationSettings::fromArray([
            'tax_method' => 'straight',
            'tax_rule' => 'cz-2',
            'acc_method' => 'time',
            'acc_months' => '60',
            'intangible' => 0,
        ]);
        $this->assertSame('straight', $settings->taxMethod);
        $this->assertSame('cz-2', $settings->taxRule);
        $this->assertSame(DepreciationSettings::ACC_TIME, $settings->accMethod);
        $this->assertSame(60, $settings->accMonths);
        $this->assertFalse($settings->intangible);

        // Neodepisovaný druh má všechna pole prázdná.
        $empty = DepreciationSettings::fromArray(['tax_method' => '', 'tax_rule' => null, 'acc_method' => '']);
        $this->assertNull($empty->taxMethod);
        $this->assertNull($empty->taxRule);
        $this->assertNull($empty->accMethod);
        $this->assertNull($empty->accMonths);
    }

    public function testSettingsRejectUnknownAccountingMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DepreciationSettings::fromArray(['acc_method' => 'units']);
    }

    public function testAccountingAmountsRoundUpWithoutFloatNoise(): void
    {
        $this->assertSame(2575.0, Amounts::ceil(50000 * 5.15 / 100.0));
        $this->assertSame(1267.0, Amounts::ceil(57000 / 45));
        $this->assertTrue(Amounts::isWhole(1267.0));
        $this->assertFalse(Amounts::isWhole(1266.67));
        $this->assertSame('1 266,67', Amounts::money(1266.666));
    }

    // --- texty hlášení ------------------------------------------------------

    /** @param array<string, mixed>|null $texts */
    private function texts(?array $texts): PlanMessageTexts
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === PlanMessageTexts::CFG_ITEM ? $texts : null,
        );
        return new PlanMessageTexts($config);
    }

    public function testMessageTextFillsFormattedParameters(): void
    {
        $texts = $this->texts([
            'mismatch' => ['text' => 'Potvrzený odpis {confirmed} se liší od vypočteného {computed}.'],
            'ruleNotValid' => ['text' => 'Pravidlo {rule} neplatí pro majetek zařazený {date}.'],
            'openingMismatch' => ['text' => 'Po {units} obdobích mají být oprávky {expected}.'],
        ]);

        $this->assertSame(
            'Potvrzený odpis 10 000,00 se liší od vypočteného 11 000,00.',
            $texts->text(PlanMessage::warning(PlanMessage::MISMATCH, ['confirmed' => 10000.0, 'computed' => 11000.0])),
        );
        $this->assertSame(
            'Pravidlo cz-6 neplatí pro majetek zařazený 15. 3. 2003.',
            $texts->text(PlanMessage::error(PlanMessage::RULE_NOT_VALID, ['rule' => 'cz-6', 'date' => '2003-03-15'])),
        );
        $this->assertSame(
            'Po 10 obdobích mají být oprávky 960 000,00.',
            $texts->text(PlanMessage::warning(PlanMessage::OPENING_MISMATCH, ['units' => 10, 'expected' => 960000.0])),
        );
    }

    public function testSettingsInvalidPrefersTextOfItsReason(): void
    {
        $texts = $this->texts([
            'settingsInvalid' => ['text' => 'Nastavení nejde spočítat.'],
            'settingsInvalid.accMonthsMissing' => ['text' => 'Chybí délka v měsících.'],
        ]);

        $this->assertSame(
            'Chybí délka v měsících.',
            $texts->text(PlanMessage::error(PlanMessage::SETTINGS_INVALID, ['reason' => 'accMonthsMissing'])),
        );
        $this->assertSame(
            'Nastavení nejde spočítat.',
            $texts->text(PlanMessage::error(PlanMessage::SETTINGS_INVALID, ['reason' => 'somethingNew'])),
        );
    }

    public function testMissingConfigurationDegradesToMessageCode(): void
    {
        $message = PlanMessage::error(PlanMessage::MISSING_PERIOD);

        $this->assertSame('missingPeriod', $this->texts(null)->text($message));
        $this->assertSame('missingPeriod', (new PlanMessageTexts(null))->text($message));
    }

    public function testConfigHasTextForEveryMessageCodeAndReason(): void
    {
        $cfg = JsoncParser::parseFile(self::MESSAGES);

        $codes = (new \ReflectionClass(PlanMessage::class))->getConstants();
        unset($codes['SEVERITY_ERROR'], $codes['SEVERITY_WARNING']);
        $reasons = ['accMonthsMissing', 'asTaxWithoutFormula', 'accountingWithoutAccMethod', 'unknownTaxMethod'];
        $keys = [...array_values($codes), ...array_map(static fn(string $r): string => "settingsInvalid.{$r}", $reasons)];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $cfg, "Chybí text hlášení '{$key}'");
            foreach (['text', 'text:cs', 'text:en'] as $variant) {
                $this->assertNotEmpty($cfg[$key][$variant] ?? '', "Hlášení '{$key}' nemá '{$variant}'");
            }
        }
        $this->assertCount(count($keys), $cfg, 'Konfigurace obsahuje text neznámého hlášení');
    }
}
