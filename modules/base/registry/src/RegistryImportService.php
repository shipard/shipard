<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;

/**
 * Import dokumentu Spisovny ze starého Shipardu (`wkf.docs`) — servisní
 * jádro endpointu `POST /_registry/import` (design `docs/registry-mvp.md`
 * §10, task `tasks/registry-import-endpoint.md`).
 *
 * - **Historické `created`** z payloadu se zachovává —
 *   `RegistryDocumentDocument::beforeSave` audit pole doplňuje jen když
 *   je prázdné.
 * - **Cílový `docState`** (10/40/70/80) se zapisuje přímo při insertu —
 *   stejně jako {@see RegistryApplier} (hooky přechody na insertu
 *   nevalidují); `docStateMain` odvodí centrálně TableGateway, fallback
 *   mapa kryje běh bez compiled configu.
 * - **Idempotence**: dedupe podle `metadata.legacyNdx` +
 *   `source_kind='import'` mimo Koš — opakovaný běh runneru vrátí
 *   existující id bez zápisu. Objemy ≤ tisíce řádků, JSON scan bez
 *   indexu stačí.
 * - **Šanon** se resolvuje case-insensitive na živé šanony (utf8mb4_czech_ci,
 *   vzor {@see RegistryApplier::suggestBinder}); nenalezený → NULL +
 *   warning `BINDER_NOT_FOUND`. Šanony endpoint nezakládá — to je práce
 *   runneru před dokumenty.
 *
 * Návratový tvar sleduje {@see FileFromMessageService}: `['ok' => bool, …]`,
 * u chyb `errorCode`/`errorMessage`/`statusCode` (+ `details` pro 422),
 * u úspěchu `id`/`existed`/`statusCode` (+ `warning`).
 */
class RegistryImportService
{
    private const REGISTRY_TABLE = 'base_registry_documents';
    private const BINDERS_TABLE = 'base_registry_binders';

    private const SCHEMA_ID = 'shpd.registry.document.v1';

    /** Povolené cílové stavy importu (archivační sada bez Koše a Vyřazeno v Konceptu). */
    private const ALLOWED_DOC_STATES = [10, 40, 70, 80];
    private const DEFAULT_DOC_STATE = 40;

    /** Fallback mainState mapa `core.system.docStatesArchive` (vzor RegistryApplier). */
    private const MAIN_STATE_FALLBACK = [10 => 1, 80 => 2, 40 => 3, 70 => 4, 90 => 5];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DocumentRegistry $documentRegistry,
        private readonly ?TableDefinition $tableDef = null,
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * @param array<string, mixed> $body dekódované JSON tělo requestu
     * @return array<string, mixed>
     */
    public function import(array $body): array
    {
        // schema — měkce: chybějící projde, cizí hodnota je chyba volajícího
        $schema = trim((string) ($body['schema'] ?? ''));
        if ($schema !== '' && $schema !== self::SCHEMA_ID) {
            return self::validationError('schema', 'unsupported_schema', 'Unsupported schema: ' . $schema);
        }

        $docKind = trim((string) ($body['docKind'] ?? ''));
        if ($docKind === '') {
            return self::validationError('docKind', 'required', 'docKind is required');
        }
        if ($this->config !== null) {
            $kinds = $this->config->cfgItem('base.registry.docKinds');
            if (is_array($kinds) && !isset($kinds[$docKind])) {
                return self::validationError('docKind', 'unknown_kind', "Unknown docKind: {$docKind}");
            }
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return self::validationError('title', 'required', 'title is required');
        }

        $docState = $body['docState'] ?? self::DEFAULT_DOC_STATE;
        if (!is_numeric($docState) || !in_array((int) $docState, self::ALLOWED_DOC_STATES, true)) {
            return self::validationError('docState', 'invalid_state', 'docState must be one of 10, 40, 70, 80');
        }
        $docState = (int) $docState;

        $createdRaw = trim((string) ($body['created'] ?? ''));
        if ($createdRaw === '') {
            return self::validationError('created', 'required', 'created is required');
        }
        $createdTs = strtotime($createdRaw);
        if ($createdTs === false) {
            return self::validationError('created', 'invalid_datetime', 'created must be an ISO 8601 datetime');
        }

        $legacy = $body['legacy'] ?? null;
        if (!is_array($legacy)) {
            return self::validationError('legacy', 'required', 'legacy block is required');
        }
        $legacyNdx = $legacy['ndx'] ?? null;
        if (!is_numeric($legacyNdx) || (int) $legacyNdx <= 0) {
            return self::validationError('legacy.ndx', 'required', 'legacy.ndx must be a positive integer');
        }
        $legacyNdx = (int) $legacyNdx;

        $validFrom = self::normalizeDate($body['validFrom'] ?? null);
        if ($validFrom === false) {
            return self::validationError('validFrom', 'invalid_date', 'validFrom must be an ISO 8601 date');
        }
        $validTo = self::normalizeDate($body['validTo'] ?? null);
        if ($validTo === false) {
            return self::validationError('validTo', 'invalid_date', 'validTo must be an ISO 8601 date');
        }

        // Idempotence: opakovaný běh runneru se stejnou legacy identitou
        $existing = $this->db->fetchRow(
            'SELECT `id` FROM %n'
            . ' WHERE `source_kind` = %s AND `docState` <> 90'
            . ' AND JSON_UNQUOTE(JSON_EXTRACT(`metadata`, %s)) = %s'
            . ' LIMIT 1',
            self::REGISTRY_TABLE, 'import', '$.legacyNdx', (string) $legacyNdx,
        );
        if ($existing !== null) {
            return ['ok' => true, 'id' => (int) $existing['id'], 'existed' => true, 'statusCode' => 200];
        }

        $warning = null;
        $binderId = null;
        $binderName = trim((string) ($body['binder'] ?? ''));
        if ($binderName !== '') {
            $binderId = $this->resolveBinder($binderName);
            if ($binderId === null) {
                $warning = 'BINDER_NOT_FOUND';
            }
        }

        $metadata = ['legacyNdx' => $legacyNdx];
        foreach (['id' => 'legacyId', 'kind' => 'legacyKind', 'author' => 'legacyAuthor', 'folder' => 'legacyFolder'] as $src => $dst) {
            $value = trim((string) ($legacy[$src] ?? ''));
            if ($value !== '') {
                $metadata[$dst] = $value;
            }
        }

        $data = [
            'title'          => $title,
            'doc_kind'       => $docKind,
            'binder'         => $binderId,
            'notice'         => self::nullIfEmpty($body['notice'] ?? null),
            'valid_from'     => $validFrom,
            'valid_to'       => $validTo,
            'metadata'       => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_kind'    => 'import',
            'source_message' => null,
            'created'        => date('Y-m-d H:i:s', $createdTs),
            'created_by'     => null,
            'docState'       => $docState,
            'docStateMain'   => self::MAIN_STATE_FALLBACK[$docState],
        ];

        $result = $this->buildGateway()->saveDocument($data);
        if (!$result->isSuccess()) {
            $validation = $result->getValidation();
            if ($validation !== null) {
                $first = $validation->getErrors()[0];
                return self::validationError($first->column, $first->code !== '' ? $first->code : 'invalid', $first->message);
            }
            return [
                'ok' => false,
                'errorCode' => 'INTERNAL_ERROR',
                'errorMessage' => $result->getErrorMessage() ?? 'Import failed',
                'statusCode' => 500,
            ];
        }

        $saved = ['ok' => true, 'id' => (int) ($result->getData()['id'] ?? 0), 'existed' => false, 'statusCode' => 201];
        if ($warning !== null) {
            $saved['warning'] = $warning;
        }
        return $saved;
    }

    /** CI match jména na živé šanony (kolace utf8mb4_czech_ci je case-insensitive). */
    private function resolveBinder(string $name): ?int
    {
        $row = $this->db->fetchRow(
            'SELECT `id` FROM %n'
            . ' WHERE `name` = %s AND `docState` IN (10, 40, 80)'
            . ' ORDER BY `docStateMain` ASC, `order_pos` ASC, `id` ASC'
            . ' LIMIT 1',
            self::BINDERS_TABLE, $name,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    private function buildGateway(): TableGateway
    {
        return new TableGateway(
            self::REGISTRY_TABLE,
            $this->db->getDibiConnection(),
            $this->documentRegistry,
            $this->tableDef?->childTables,
            $this->config,
            null,
            null,
            $this->tableDef?->docStates,
            $this->tableDef,
        );
    }

    /** @return array<string, mixed> */
    private static function validationError(string $field, string $code, string $message): array
    {
        return [
            'ok' => false,
            'errorCode' => 'VALIDATION_ERROR',
            'errorMessage' => $message,
            'statusCode' => 422,
            'details' => [['field' => $field, 'code' => $code]],
        ];
    }

    /** null = nevyplněno, false = neparsovatelné, jinak 'Y-m-d'. */
    private static function normalizeDate(mixed $value): string|false|null
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts === false ? false : date('Y-m-d', $ts);
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $str = trim((string) ($value ?? ''));
        return $str === '' ? null : $str;
    }
}
