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
 * jazykem tisku (#90 D13) — `PrintData` nese čísla v plné přesnosti a data
 * v ISO, podobu jim dává až šablona.
 *
 *  - `money` — 2 desetinná místa, oddělovač tisíců; volitelně kód měny
 *  - `qty`   — množství bez zbytečných nul (nejvýš 4 desetinná místa)
 *  - `pct`   — procenta bez zbytečných nul
 *  - `date`  — ISO datum → `2. 10. 2026` (cs) / `10/2/2026` (en);
 *              přepisuje vestavěný Twig filtr stejného jména
 *  - `t(klíč, {parametry})` — překlad z katalogu tisku
 *  - `qr_svg(payment.qr)` — inline SVG QR kódu, pro null prázdný řetězec
 *
 * Mezery uvnitř čísel a dat jsou nezlomitelné — hodnota se nesmí zalomit.
 */
final class PrintTwigExtension extends AbstractExtension
{
    private const NBSP = "\u{00A0}";

    public function __construct(
        private readonly PrintTranslator $translator,
    ) {}

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
        $text = $this->number((float) $value, 0, 2);
        return $this->isCzech() ? $text . self::NBSP . '%' : $text . '%';
    }

    public function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return '';
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return $this->isCzech()
            ? $day . '.' . self::NBSP . $month . '.' . self::NBSP . $year
            : $month . '/' . $day . '/' . $year;
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
        $rounded = round($value, $maxDecimals);
        if ($rounded == 0.0) {
            $rounded = 0.0; // žádné „-0,00"
        }

        [$decimalPoint, $thousands] = $this->isCzech() ? [',', self::NBSP] : ['.', ','];
        $text = number_format($rounded, $maxDecimals, $decimalPoint, $thousands);

        if ($maxDecimals > $minDecimals) {
            $text = rtrim($text, '0');
            $text = str_pad(
                $text,
                strrpos($text, $decimalPoint) + 1 + $minDecimals,
                '0',
            );
            if ($minDecimals === 0) {
                $text = rtrim($text, $decimalPoint);
            }
        }
        return $text;
    }

    private function isCzech(): bool
    {
        return $this->translator->language === 'cs';
    }
}
