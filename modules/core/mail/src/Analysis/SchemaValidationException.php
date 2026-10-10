<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Výstup modelu nejde přečíst jako JSON nebo neodpovídá `output_schema`
 * profilu. Zpráva výjimky drží textový tvar Python knihovny jsonschema
 * (původní validátor; hlášky starších běhů v DB mají stejný tvar) — z něj
 * {@see \Shipard\Module\Core\Mail\AnalysisErrorPresenter} skládá hlášku pro
 * uživatele (cesta v dokumentu + důvod). Selhání bez opakování: model
 * stejný výstup nevylepší jen tím, že se zeptáme znovu.
 */
final class SchemaValidationException extends \RuntimeException
{
}
