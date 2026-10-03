<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Twig;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Shipard\Core\Prints\PrintTranslator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Filtry a funkce tiskových šablon. Formátování čísel a dat se řídí
 * jazykem tisku (#90 D13, D30) — `PrintData` nese čísla v plné přesnosti
 * a data v ISO, podobu jim dává až šablona.
 *
 *  - `money` — 2 desetinná místa, oddělovač tisíců; volitelně kód měny
 *              (za číslem, ve všech jazycích kód, ne symbol)
 *  - `qty`   — množství bez zbytečných nul (nejvýš 4 desetinná místa)
 *  - `pct`   — procenta bez zbytečných nul (nejvýš 2 desetinná místa)
 *  - `date`  — ISO datum → `2. 10. 2026` (cs, sk) / `02.10.2026` (de) /
 *              `2 Oct 2026` (en); přepisuje vestavěný Twig filtr stejného
 *              jména
 *  - `t(klíč, {parametry})` — překlad z katalogu tisku
 *  - `qr_svg(payment.qr)` — inline SVG QR kódu, pro null prázdný řetězec
 *
 * Formátuje `ext-intl`, ale vzory i symboly jsou zapsané tady — výchozí
 * data ICU se mezi verzemi mění a tisk dokladu se s nimi měnit nesmí.
 * Mezery uvnitř čísel a dat jsou nezlomitelné — hodnota se nesmí zalomit.
 */
final class PrintTwigExtension extends AbstractExtension
{
    private const NBSP = "\u{00A0}";

    /**
     * Podoba čísel a dat podle jazyka tisku. `date` je vzor ICU; angličtina
     * ho nemá — měsíc tiskne zkratkou z `EN_MONTHS`.
     */
    private const FORMATS = [
        'cs' => ['locale' => 'cs_CZ', 'decimal' => ',', 'grouping' => self::NBSP, 'pct' => self::NBSP . '%', 'date' => 'd.' . self::NBSP . 'M.' . self::NBSP . 'y'],
        'sk' => ['locale' => 'sk_SK', 'decimal' => ',', 'grouping' => self::NBSP, 'pct' => self::NBSP . '%', 'date' => 'd.' . self::NBSP . 'M.' . self::NBSP . 'y'],
        'de' => ['locale' => 'de_DE', 'decimal' => ',', 'grouping' => '.',        'pct' => self::NBSP . '%', 'date' => 'dd.MM.y'],
        'en' => ['locale' => 'en_GB', 'decimal' => '.', 'grouping' => ',',        'pct' => '%',              'date' => null],
    ];

    /** Třípísmenné zkratky — `MMM` v `en_GB` dává „Sept“. */
    private const EN_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** @var array{locale: string, decimal: string, grouping: string, pct: string, date: ?string} */
    private readonly array $format;

    /** @var array<string, \NumberFormatter> vzor → formatter */
    private array $numberFormatters = [];

    private ?\IntlDateFormatter $dateFormatter = null;

    /**
     * @throws \LogicException Jazyk bez formátu — jazyk tisku je vždy
     *         z `PrintLanguageResolver::LANGUAGES`.
     */
    public function __construct(
        private readonly PrintTranslator $translator,
    ) {
        $this->format = self::FORMATS[$translator->language]
            ?? throw new \LogicException("Print language '{$translator->language}' has no number and date format");
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('qty', $this->qty(...)),
            new TwigFilter('pct', $this->pct(...)),
            new TwigFilter('date', $this->date(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translator->t(...)),
            new TwigFunction('qr_svg', $this->qrSvg(...), ['is_safe' => ['html']]),
        ];
    }

    public function money(mixed $value, ?string $currency = null): string
    {
        if (!is_numeric($value)) {
            return '';
        }
        $text = $this->number((float) $value, 2, 2);
        return $currency === null || $currency === '' ? $text : $text . self::NBSP . $currency;
    }

    public function qty(mixed $value): string
    {
        return is_numeric($value) ? $this->number((float) $value, 0, 4) : '';
    }

    public function pct(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }
        return $this->number((float) $value, 0, 2) . $this->format['pct'];
    }

    public function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return '';
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (!checkdate($month, $day, $year)) {
            return '';
        }

        $pattern = $this->format['date'];
        if ($pattern === null) {
            return $day . self::NBSP . self::EN_MONTHS[$month - 1] . self::NBSP . $year;
        }

        $this->dateFormatter ??= new \IntlDateFormatter(
            $this->format['locale'],
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            $pattern,
        );
        return (string) $this->dateFormatter->format(
            new \DateTimeImmutable(sprintf('%04d-%02d-%02dT12:00:00', $year, $month, $day), new \DateTimeZone('UTC')),
        );
    }

    /**
     * QR kód jako inline SVG. Střední úroveň opravy chyb — platební QR se
     * čte z papíru i z displeje.
     */
    public function qrSvg(mixed $qr): string
    {
        $payload = is_array($qr) ? ($qr['payload'] ?? null) : null;
        if (!is_string($payload) || $payload === '') {
            return '';
        }

        $options = new QROptions([
            'outputInterface'  => QRMarkupSVG::class,
            'outputBase64'     => false,
            'svgAddXmlHeader'  => false,
            'eccLevel'         => EccLevel::M,
            'addQuietzone'     => true,
            'quietzoneSize'    => 2,
            'drawLightModules' => false,
            'connectPaths'     => true,
        ]);

        return (new QRCode($options))->render($payload);
    }

    /** Číslo s `$minDecimals`–`$maxDecimals` desetinnými místy v podobě jazyka tisku. */
    private function number(float $value, int $minDecimals, int $maxDecimals): string
    {
        // Zaokrouhluje PHP, ne ICU — to má výchozí bankéřské zaokrouhlení
        // (10,005 → 10,00) a zápornou nulu tiskne jako „-0,00".
        $rounded = round($value, $maxDecimals);
        if ($rounded == 0.0) {
            $rounded = 0.0;
        }

        $pattern = '#,##0';
        if ($maxDecimals > 0) {
            $pattern .= '.' . str_repeat('0', $minDecimals) . str_repeat('#', $maxDecimals - $minDecimals);
        }

        return (string) $this->numberFormatter($pattern)->format($rounded);
    }

    private function numberFormatter(string $pattern): \NumberFormatter
    {
        if (!isset($this->numberFormatters[$pattern])) {
            $formatter = new \NumberFormatter($this->format['locale'], \NumberFormatter::PATTERN_DECIMAL, $pattern);
            $formatter->setSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL, $this->format['decimal']);
            $formatter->setSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL, $this->format['grouping']);
            $formatter->setSymbol(\NumberFormatter::MINUS_SIGN_SYMBOL, '-');
            $this->numberFormatters[$pattern] = $formatter;
        }
        return $this->numberFormatters[$pattern];
    }
}
