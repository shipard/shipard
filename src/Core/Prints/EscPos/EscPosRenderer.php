<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

use Shipard\Core\Prints\PrintMessage;

/**
 * Výstup šablony účtenky → bajty ESC/POS podle profilu tiskárny (#177 D26):
 * parser značek → sazba → kodér. Ovladač a kódovou stránku určuje profil.
 */
final class EscPosRenderer
{
    public const WARNING_QR_AS_TEXT = 'ESCPOS_QR_AS_TEXT';

    public function __construct(
        private readonly EscPosDrivers $drivers,
        private readonly ReceiptMarkupParser $parser = new ReceiptMarkupParser(),
    ) {
    }

    /**
     * @throws ReceiptMarkupException Výstup šablony není platný.
     * @throws EscPosConfigException  Ovladač nebo kódová stránka profilu chybí.
     */
    public function render(string $markup, PrinterProfile $profile): EscPosOutput
    {
        $driver   = $this->drivers->get($profile->driver);
        $codePage = CodePages::get($profile->codePage);
        $elements = $this->parser->parse($markup);

        $warnings = [];
        if (!$profile->hasFeature(PrinterProfile::FEATURE_QR)) {
            foreach ($elements as $element) {
                if ($element->type === ReceiptElement::QR) {
                    $warnings[] = PrintMessage::warning(
                        self::WARNING_QR_AS_TEXT,
                        "Printer profile '{$profile->id}' has no QR code support, the code is printed as text",
                    );
                    break;
                }
            }
        }

        $items = (new ReceiptLayout($profile, $codePage))->layout($elements);

        return new EscPosOutput(
            (new EscPosEncoder($driver, $codePage))->encode($items),
            $warnings,
            EscPosPreview::of($items),
        );
    }
}
