<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\EscPos;

use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\EscPos\EscPosDrivers;
use Shipard\Core\Prints\EscPos\EscPosRenderer;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\EscPos\PrinterProfiles;
use Shipard\Core\Prints\EscPos\PrinterTestReceipt;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintRenderer;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Utils\JsoncParser;

/**
 * Společné vzory ESC/POS (#177 U33): pro každý vzor bajty (`<název>.bin`)
 * a očekávaný náhled (`<název>.preview.json` — řádky se stylem a značky).
 *
 * PHP renderer je musí vyrobit bajt po bajtu (`EscPosFixturesTest`), náhled
 * ve frontendu z týchž bajtů přečíst očekávané řádky
 * (`frontend/tests/Unit/native/escposFixtures.test.mjs`) — renderer a náhled
 * se tak nerozejdou potichu. Očekávaný náhled vzniká ze sazby, ne z bajtů.
 *
 * Tady je jediné místo, které říká, co vzory jsou a jak vznikají; používá ho
 * test i `regenerate.php`.
 */
final class EscPosFixtures
{
    public const RECEIPT_PRINT = 'docs.cashRegister.receiptPos';

    /** Účtenka prodejky: název vzoru → fixture `PrintData` v `tests/Fixtures/Prints/`. */
    public const RECEIPTS = [
        'receipt-vat-payer'     => 'receiptPosVatPayer',
        'receipt-non-vat-payer' => 'receiptPosNonVatPayer',
        'receipt-cancelled'     => 'receiptPosCancelled',
        'receipt-customer'      => 'receiptPosCustomer',
    ];

    /** Profil → přípona názvu vzoru. */
    public const PROFILES = [
        'escpos-80-cp852' => '80',
        'escpos-58-cp852' => '58',
    ];

    /** Čas na zkušební účtence. */
    public const TEST_RECEIPT_TIME = '2026-11-28 10:15:42';

    public static function directory(): string
    {
        return __DIR__;
    }

    private static function repo(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, mixed> cfgItem modulu core.prints, jak ho dá kompilace */
    private static function printsConfig(string $name): array
    {
        return ConfigLocalizer::localize(
            JsoncParser::parseFile(self::repo() . "/modules/core/prints/config/{$name}.jsonc"),
            'cs',
        );
    }

    public static function profile(string $id): PrinterProfile
    {
        $resolved = (new PrinterProfiles(self::printsConfig('printerProfiles')))->get($id);
        if ($resolved['warning'] !== null) {
            throw new \LogicException("Unknown printer profile '{$id}'");
        }
        return $resolved['profile'];
    }

    private static function drivers(): EscPosDrivers
    {
        return new EscPosDrivers(self::printsConfig('printerDrivers'));
    }

    /**
     * Všechny vzory: název → bajty a očekávaný náhled.
     *
     * @return array<string, array{bytes: string, preview: array<string, mixed>}>
     */
    public static function build(): array
    {
        $fixtures = [];
        foreach (self::PROFILES as $profileId => $suffix) {
            $profile = self::profile($profileId);

            $test = (new PrinterTestReceipt(
                self::drivers(),
                self::printsConfig('printerTestTexts'),
                static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::TEST_RECEIPT_TIME),
            ))->render($profile);
            $fixtures["test-{$suffix}"] = ['bytes' => $test->bytes, 'preview' => $test->preview];

            foreach (self::RECEIPTS as $name => $printData) {
                $receipt = (new EscPosRenderer(self::drivers()))->render(self::receiptMarkup($printData), $profile);
                $fixtures["{$name}-{$suffix}"] = ['bytes' => $receipt->bytes, 'preview' => $receipt->preview];
            }
        }
        ksort($fixtures);
        return $fixtures;
    }

    /**
     * Výstup šablony účtenky prodejky nad fixture `PrintData`.
     *
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $modify úprava obálky před renderem
     */
    public static function receiptMarkup(string $printData, ?string $language = null, ?\Closure $modify = null): string
    {
        $modules  = new ModulePathResolver([self::repo() . '/modules']);
        $paths    = new PrintTemplatePaths($modules);
        $envelope = json_decode(
            (string) file_get_contents(self::repo() . "/tests/Fixtures/Prints/{$printData}.json"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if ($modify !== null) {
            $envelope = $modify($envelope);
        }
        $data = PrintData::fromArray($envelope);
        if ($language !== null) {
            $data = $data->withLanguage($language);
        }
        $definition = self::receiptDefinition();

        return (new PrintRenderer($paths, new PrintTwigFactory($paths), new RenderClient(null)))->renderMarkup(
            $definition,
            $data,
            (new PrintCatalogLoader($paths))->translator($definition, $data->language),
        );
    }

    public static function receiptDefinition(): PrintDefinition
    {
        $file = self::repo() . '/modules/docs/cashRegister/config/prints.jsonc';
        foreach (JsoncParser::parseFile($file) as $raw) {
            if (($raw['id'] ?? null) === self::RECEIPT_PRINT) {
                return PrintDefinition::fromArray(ConfigLocalizer::localize($raw, 'cs'), 'docs.cashRegister');
            }
        }
        throw new \LogicException('Print ' . self::RECEIPT_PRINT . ' is not declared');
    }

    /** Očekávaný náhled jako obsah souboru. */
    public static function previewJson(array $preview): string
    {
        return json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
