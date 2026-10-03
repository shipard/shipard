<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Prints\PrintBuildException;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintLanguageNotCompiledException;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintNotFoundException;
use Shipard\Core\Prints\PrintRecordNotFoundException;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Prints\PrintRunner;

/**
 * Endpoint:
 *   GET /_prints/{printId}/{recordId}?format=pdf|json[&language=cs]
 *
 * Tisk nad jedním záznamem (#90 D20). Tabulku určuje deklarace tisku.
 * Práva (D21): tisk = čtení záznamu — kdo smí detail tabulky, smí tisk.
 * `format=json` (`PrintData`) je jen pro administrátora a ladění.
 *
 * `pdf` (default) vrací soubor mimo JSON obálku s `Content-Disposition:
 * inline` — náhled v dialogu i uložení pod názvem z `meta.fileName`.
 * Měkká hlášení builderu (QR nevznikl) nese hlavička `X-Print-Messages`.
 * Chyby jdou jako JSON: 404 neznámý tisk / záznam, 409 tisk pro záznam
 * není dostupný (stav, typ), záznamu chybí data nebo zdroj dat nemá
 * konfiguraci v jazyce tisku, 400 špatný parametr, 503 / 500 render služba
 * (druh selhání v `details`).
 */
class PrintsController
{
    /** Hlavička PDF odpovědi s `PrintData.messages` (viz `run()`). */
    public const MESSAGES_HEADER = 'X-Print-Messages';

    public function __construct(
        private readonly PrintRegistry $registry,
        private readonly PrintRunner $runner,
    ) {}

    /**
     * @param array<string, mixed> $rawParams Query parametry requestu.
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function run(string $printId, int $recordId, array $rawParams, AuthContext $auth, array $tables): Response
    {
        $definition = $this->registry->get($printId);
        if ($definition === null) {
            return Response::error('PRINT_NOT_FOUND', "Unknown print '{$printId}'", 404);
        }

        $guardErr = TableAccessGuard::guardTable($definition->table, $auth, $tables[$definition->table] ?? null);
        if ($guardErr !== null) {
            return $guardErr;
        }

        $formatRaw = $rawParams['format'] ?? PrintFormat::Pdf->value;
        $format    = is_string($formatRaw) ? PrintFormat::tryFrom($formatRaw) : null;
        // `html` je nástroj pro vývoj šablon v CLI — REST ho nenabízí (#90 D28).
        if ($format === null || $format === PrintFormat::Html) {
            return Response::error('BAD_REQUEST', "Parameter 'format' must be one of pdf|json", 400);
        }
        if ($format === PrintFormat::Json && !$auth->isAdmin) {
            return Response::error('FORBIDDEN_ADMIN_ONLY', 'Print data in JSON are available to administrators only', 403);
        }

        $language = $rawParams['language'] ?? null;
        if ($language !== null && !is_string($language)) {
            return Response::error('BAD_REQUEST', "Parameter 'language' must be a string", 400);
        }

        try {
            $output = $this->runner->run($printId, $recordId, $format, $language);
        } catch (PrintNotFoundException $e) {
            return Response::error('PRINT_NOT_FOUND', $e->getMessage(), 404);
        } catch (PrintRecordNotFoundException $e) {
            return Response::error('RECORD_NOT_FOUND', $e->getMessage(), 404);
        } catch (PrintNotAvailableException $e) {
            return Response::error('PRINT_NOT_AVAILABLE', $e->getMessage(), 409);
        } catch (PrintBuildException $e) {
            return Response::error('PRINT_DATA_MISSING', $e->getMessage(), 409);
        } catch (PrintLanguageNotCompiledException $e) {
            return Response::error('PRINT_LANGUAGE_NOT_COMPILED', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Response::error('BAD_REQUEST', $e->getMessage(), 400);
        } catch (PrintRenderException $e) {
            $details = [['field' => '_render', 'code' => $e->errorKind->value, 'message' => $e->getMessage()]];
            return $e->isServiceUnavailable()
                ? Response::error('RENDER_UNAVAILABLE', 'Print service is not available', 503, $details)
                : Response::error('RENDER_FAILED', 'Print rendering failed', 500, $details);
        }

        if ($format === PrintFormat::Json) {
            return Response::success($output->printData->jsonSerialize());
        }

        // Název souboru je ASCII slug — stačí prostý `filename`.
        $response = Response::binary((string) $output->pdfContent, 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $output->printData->fileName . '"')
            ->withHeader('Cache-Control', 'no-store');

        // Měkká hlášení builderu nemají v binární odpovědi jiné místo než
        // hlavičku: JSON pole, procentově kódované (hlavička smí nést jen ASCII).
        if ($output->printData->messages !== []) {
            $response = $response->withHeader(self::MESSAGES_HEADER, rawurlencode((string) json_encode(
                array_map(static fn ($message): array => $message->toArray(), $output->printData->messages),
                JSON_UNESCAPED_UNICODE,
            )));
        }

        return $response;
    }
}
