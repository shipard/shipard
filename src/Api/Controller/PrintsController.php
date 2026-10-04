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
use Shipard\Module\Core\Mail\Sent\RecordSendException;
use Shipard\Module\Core\Mail\Sent\RecordSendService;
use Shipard\Module\Core\Mail\Sent\SendRequest;

/**
 * Endpointy:
 *   GET  /_prints/{printId}/{recordId}?format=pdf|json[&language=cs]
 *   GET  /_prints/{printId}/{recordId}/send-draft[?language=cs]
 *   POST /_prints/{printId}/{recordId}/send
 *
 * Tisk nad jedním záznamem (#90 D20). Tabulku určuje deklarace tisku.
 * Práva (D21): tisk = čtení záznamu — kdo smí detail tabulky, smí tisk.
 * `format=json` (`PrintData`) je jen pro administrátora a ladění.
 *
 * `pdf` (default) vrací soubor mimo JSON obálku s `Content-Disposition:
 * inline` — náhled v dialogu i uložení pod názvem z `meta.fileName`.
 * `Content-Language` nese jazyk, ve kterém tisk vznikl — i když ho klient
 * nevyžádal a zvolil ho partner dokladu (#90 D33). Měkká hlášení builderu
 * (QR nevznikl) nese hlavička `X-Print-Messages`.
 * Chyby jdou jako JSON: 404 neznámý tisk / záznam, 409 tisk pro záznam
 * není dostupný (stav, typ), záznamu chybí data nebo zdroj dat nemá
 * konfiguraci v jazyce tisku, 400 špatný parametr, 503 / 500 render služba
 * (druh selhání v `details`).
 */
class PrintsController
{
    /** Hlavička PDF odpovědi s `PrintData.messages` (viz `run()`). */
    public const MESSAGES_HEADER = 'X-Print-Messages';

    /** @var ?\Closure(): RecordSendService */
    private readonly ?\Closure $sendService;

    /**
     * @param ?\Closure(): RecordSendService $sendService Služba odeslání —
     *        líně, tisk samotný ji nepotřebuje. Null = zdroj dat odesílat
     *        neumí (bez modulu pošty).
     * @param list<array{id: string, label: string}> $languages Jazyky tisku
     *        pro přepínač v dialogu odeslání.
     */
    public function __construct(
        private readonly PrintRegistry $registry,
        private readonly PrintRunner $runner,
        ?\Closure $sendService = null,
        private readonly array $languages = [],
    ) {
        $this->sendService = $sendService;
    }

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
            ->withHeader('Content-Language', $output->printData->language)
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

    /**
     * GET /_prints/{printId}/{recordId}/send-draft[?language=cs] — návrh
     * odeslání (#90 D38): příjemci s důvody, povolení odesílatelé a výchozí,
     * předmět, tělo, jazyk, přílohy a hlášení. Nic nevytváří; chybějící
     * příjemce nebo odesílatel je chyba v `messages` (`canSend: false`), ne
     * chybová odpověď — dialog ji ukáže a uživatel adresu doplní.
     *
     * @param array<string, mixed> $rawParams
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function sendDraft(string $printId, int $recordId, array $rawParams, AuthContext $auth, array $tables): Response
    {
        $service = $this->sendServiceFor($printId, $auth, $tables);
        if ($service instanceof Response) {
            return $service;
        }

        $language = $rawParams['language'] ?? null;
        if ($language !== null && !is_string($language)) {
            return Response::error('BAD_REQUEST', "Parameter 'language' must be a string", 400);
        }

        try {
            $draft = $service->prepare(new SendRequest(
                printId: $printId,
                recordId: $recordId,
                language: $language === '' ? null : $language,
                userId: $auth->isAuthenticated ? $auth->userId : null,
            ));
        } catch (\Throwable $e) {
            return $this->sendError($e);
        }

        return Response::success($draft->toArray() + ['languages' => $this->languages]);
    }

    /**
     * POST /_prints/{printId}/{recordId}/send — odešle záznam (#90 D42):
     * vždy nová zpráva v Odeslané poště. Tělo `{from, to[], cc[], subject,
     * body, language, attachmentIds[]}`; co chybí, platí z návrhu. Odpověď
     * `{sentMessageId, transportState, messages}` — `queued` znamená, že
     * okamžitý pokus neprošel a zprávu převzala fronta.
     *
     * Práva (D38): `guardTable` tabulky tisku a zápis přes `ReadOnlyPolicy`.
     *
     * @param ?array<string, mixed> $body
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function send(string $printId, int $recordId, ?array $body, AuthContext $auth, array $tables): Response
    {
        $service = $this->sendServiceFor($printId, $auth, $tables);
        if ($service instanceof Response) {
            return $service;
        }

        $body ??= [];
        foreach (['from', 'subject', 'body', 'language'] as $key) {
            if (isset($body[$key]) && !is_string($body[$key])) {
                return Response::error('BAD_REQUEST', "Field '{$key}' must be a string", 400);
            }
        }
        foreach (['to', 'cc'] as $key) {
            if (isset($body[$key]) && (!is_array($body[$key]) || $body[$key] !== array_filter($body[$key], 'is_string'))) {
                return Response::error('BAD_REQUEST', "Field '{$key}' must be a list of e-mail addresses", 400);
            }
        }
        $attachmentIds = $body['attachmentIds'] ?? null;
        if ($attachmentIds !== null
            && (!is_array($attachmentIds) || $attachmentIds !== array_filter($attachmentIds, 'is_int'))
        ) {
            return Response::error('BAD_REQUEST', "Field 'attachmentIds' must be a list of attachment ids", 400);
        }

        try {
            $result = $service->send(new SendRequest(
                printId: $printId,
                recordId: $recordId,
                language: ($body['language'] ?? '') === '' ? null : $body['language'],
                from: ($body['from'] ?? '') === '' ? null : $body['from'],
                to: isset($body['to']) ? array_values($body['to']) : null,
                cc: isset($body['cc']) ? array_values($body['cc']) : null,
                subject: $body['subject'] ?? null,
                body: $body['body'] ?? null,
                attachmentIds: $attachmentIds === null ? null : array_values($attachmentIds),
                userId: $auth->isAuthenticated ? $auth->userId : null,
                trigger: SendRequest::TRIGGER_MANUAL,
            ));
        } catch (\Throwable $e) {
            return $this->sendError($e);
        }

        return Response::success($result->toArray());
    }

    /**
     * Společný vstup obou endpointů odeslání: tisk existuje, uživatel smí
     * jeho tabulku a zdroj dat odesílat umí.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    private function sendServiceFor(string $printId, AuthContext $auth, array $tables): RecordSendService|Response
    {
        $definition = $this->registry->get($printId);
        if ($definition === null) {
            return Response::error('PRINT_NOT_FOUND', "Unknown print '{$printId}'", 404);
        }

        $guardErr = TableAccessGuard::guardTable($definition->table, $auth, $tables[$definition->table] ?? null);
        if ($guardErr !== null) {
            return $guardErr;
        }

        if ($this->sendService === null) {
            return Response::error('PRINT_NOT_SENDABLE', 'This data source cannot send records by e-mail', 409);
        }
        return ($this->sendService)();
    }

    /** Chyby návrhu a odeslání → kód a HTTP stav (tisk samotný viz `run()`). */
    private function sendError(\Throwable $e): Response
    {
        return match (true) {
            $e instanceof RecordSendException => Response::error(
                $e->errorCode,
                $e->getMessage(),
                $e->errorCode === RecordSendException::PRINT_NOT_SENDABLE ? 409 : 422,
            ),
            $e instanceof PrintNotFoundException       => Response::error('PRINT_NOT_FOUND', $e->getMessage(), 404),
            $e instanceof PrintRecordNotFoundException => Response::error('RECORD_NOT_FOUND', $e->getMessage(), 404),
            $e instanceof PrintNotAvailableException   => Response::error('PRINT_NOT_AVAILABLE', $e->getMessage(), 409),
            $e instanceof PrintBuildException          => Response::error('PRINT_DATA_MISSING', $e->getMessage(), 409),
            $e instanceof PrintLanguageNotCompiledException
                => Response::error('PRINT_LANGUAGE_NOT_COMPILED', $e->getMessage(), 409),
            $e instanceof PrintRenderException => $e->isServiceUnavailable()
                ? Response::error('RENDER_UNAVAILABLE', 'Print service is not available', 503, [
                    ['field' => '_render', 'code' => $e->errorKind->value, 'message' => $e->getMessage()],
                ])
                : Response::error('RENDER_FAILED', 'Print rendering failed', 500, [
                    ['field' => '_render', 'code' => $e->errorKind->value, 'message' => $e->getMessage()],
                ]),
            $e instanceof \InvalidArgumentException => Response::error('BAD_REQUEST', $e->getMessage(), 400),
            default => throw $e,
        };
    }
}
