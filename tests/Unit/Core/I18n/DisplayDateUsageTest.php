<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Datum na obrazovce tvoří jen `DisplayDate` (#133 D73). Test čte zdroje
 * v `src/` a `modules/<skupina>/<modul>/src/` tokenizerem (komentáře se
 * vynechají samy) a hlídá, že žádný řetězcový literál není formátovací vzor
 * data pro zobrazení — `j. n. Y`, `d.m.Y`, `d. m. Y`, `j. n.`, s časem i bez.
 *
 * Vzor `Y-m-d` test nehledá: používá se pro SQL a výměnný formát.
 *
 * Povolené soubory jsou vyjmenované s důvodem — tisk a export mají vlastní
 * formát, text uložený v datech vzniká jednou v jednom jazyce. Nový soubor
 * do seznamu patří jen ze stejných důvodů; text pro obrazovku jde přes
 * `DisplayDate` (viewer `formatDate()`, formulář `formatDate()`,
 * `SubtableCellFormatter::date()`).
 */
class DisplayDateUsageTest extends TestCase
{
    /**
     * Den a měsíc s tečkou (`j. n.`, `d.m.`), volitelně rok a čas — vzor pro
     * `date()` / `DateTimeInterface::format()` i formát buňky XLSX.
     */
    private const DISPLAY_PATTERN = '/(?<![A-Za-z])[dj]\.\s?[mn]\.(?:\s?[Yy]+)?(?![A-Za-z])/';

    /** Soubor → důvod, proč smí formátovat datum sám. */
    private const ALLOWED = [
        // Export reportu (XLSX / CSV) — mimo rozsah D73, vlastní formát souboru.
        'src/Core/Reports/Export/ReportPeriodFormatter.php' => 'export reportu',
        'src/Core/Reports/Export/ReportTabularizer.php'     => 'export reportu',
        'src/Core/Reports/Export/ReportXlsxWriter.php'      => 'export reportu — formát buňky XLSX',
        // XML podání na Finanční správu — formát předepisuje EPO.
        'modules/economy/vat/src/Xml/EpoXmlFormat.php'      => 'XML podání DPH',
        // Text uložený v datech — vzniká jednou při běhu kontroly / zaúčtování.
        'modules/base/registry/src/RegistryExpirationAlertCheck.php'  => 'text upozornění uložený při běhu kontroly',
        'modules/docs/core/src/Checks/StaleInRepairCheck.php'         => 'text upozornění uložený při běhu kontroly',
        'modules/economy/bank/src/Checks/BankAccountingErrorsCheck.php' => 'text upozornění uložený při běhu kontroly',
        'modules/economy/workOrders/src/Checks/PeriodCheckBase.php'   => 'text upozornění uložený při běhu kontroly',
        'modules/economy/assets/src/Posting/AssetPostingBuilder.php'  => 'popis účetního zápisu uložený v dokladu',
        'modules/core/exchange/src/Document/DocumentApplier.php'      => 'zpráva výsledku importu uložená u návrhu dokladu',
    ];

    private const HINT = 'datum pro obrazovku jde přes Shipard\\Core\\I18n\\DisplayDate'
        . ' (viewer / formulář formatDate(), SubtableCellFormatter::date())';

    public function testNoDisplayDatePatternOutsideDisplayDate(): void
    {
        $files = self::sources();
        $this->assertNotEmpty($files);

        $violations = [];
        $usedExceptions = [];
        foreach ($files as $file) {
            $relative = self::relative($file);
            $found = self::patternsIn((string) file_get_contents($file));
            if ($found === []) {
                continue;
            }
            if (isset(self::ALLOWED[$relative])) {
                $usedExceptions[$relative] = true;
                continue;
            }
            foreach ($found as [$line, $literal]) {
                $violations[] = sprintf('%s:%d  %s', $relative, $line, $literal);
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Formát data pro zobrazení mimo DisplayDate (' . count($violations) . ') — ' . self::HINT . ":\n" . implode("\n", $violations),
        );

        $stale = array_values(array_diff(array_keys(self::ALLOWED), array_keys($usedExceptions)));
        $this->assertSame([], $stale, 'Výjimka bez vzoru data — smaž ji ze seznamu ALLOWED');
    }

    public function testDetectsDisplayPatterns(): void
    {
        $source = <<<'PHP'
            <?php
            $a = $date->format('j. n. Y');
            $b = date('d.m.Y H:i', $ts);
            $c = $date->format("d. m. Y");
            $d = date('j. n.', $ts);
            $e = $date->format('j.n.Y');
            // $f = $date->format('j. n. Y') v komentáři nevadí
            PHP;
        $this->assertSame(
            [[2, "'j. n. Y'"], [3, "'d.m.Y H:i'"], [4, '"d. m. Y"'], [5, "'j. n.'"], [6, "'j.n.Y'"]],
            self::patternsIn($source),
        );
    }

    public function testIgnoresIsoAndOrdinaryTexts(): void
    {
        $source = <<<'PHP'
            <?php
            $sql = $date->format('Y-m-d');
            $stamp = $date->format('Y-m-d H:i:s');
            $text = 'vyd. m. j.';
            $range = 'od {date}';
            $version = 'v1.2.';
            $label = 'Period.';
            PHP;
        $this->assertSame([], self::patternsIn($source));
    }

    // ---------------------------------------------------------------------

    /** @return list<string> */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 4);
        $files = self::phpFiles($root . '/src');
        foreach (glob($root . '/modules/*/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
            array_push($files, ...self::phpFiles($dir));
        }
        sort($files);
        return $files;
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private static function relative(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__, 4)) + 1);
    }

    /**
     * Řetězcové literály se vzorem data: [řádek, literál].
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function patternsIn(string $source): array
    {
        $found = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->id !== T_CONSTANT_ENCAPSED_STRING && $token->id !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }
            if (preg_match(self::DISPLAY_PATTERN, $token->text) === 1) {
                $found[] = [$token->line, $token->text];
            }
        }
        return $found;
    }
}
