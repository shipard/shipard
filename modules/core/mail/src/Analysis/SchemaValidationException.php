<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Výstup modelu nejde přečíst jako JSON nebo neodpovídá `output_schema`
 * profilu. Zpráva výjimky má stejný textový tvar jako v démonu
 * (`ai_analyzer/schema.py`, jsonschema) — z něj
 * {@see \Shipard\Module\Core\Mail\AnalysisErrorPresenter} skládá hlášku pro
 * uživatele (cesta v dokumentu + důvod). Selhání bez opakování: model
 * stejný výstup nevylepší jen tím, že se zeptáme znovu.
 */
final class SchemaValidationException extends \RuntimeException
{
}
