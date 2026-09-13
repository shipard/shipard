<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Import;

use Shipard\Module\Economy\Vat\Xml\VatXmlMapping;

/**
 * Vstup importu jednoho starého podání (`POST /_vat/filing-import`, CLI
 * `vat-filing-import`, #55 D38). Tvar se kontroluje tady, význam
 * (instance, druh, XML) až ve službě.
 */
final readonly class FilingImportRequest
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /** @param array<string, mixed> $legacy identifikace ve starém systému (`filingNdx`, `reportNdx`) */
    public function __construct(
        public int $reportPeriodId,
        public string $reportType,
        public string $filingKind,
        public ?string $name = null,
        public ?string $dateIssue = null,
        public ?string $dateFiled = null,
        public ?string $dateFound = null,
        public ?int $accDocumentId = null,
        public ?string $xml = null,
        public array $legacy = [],
    ) {}

    /**
     * @param array<string, mixed> $body
     * @throws FilingImportException `BAD_REQUEST` u špatného tvaru
     */
    public static function fromArray(array $body): self
    {
        $periodId = (int) ($body['reportPeriodId'] ?? 0);
        if ($periodId <= 0) {
            throw self::badRequest('reportPeriodId musí být kladné celé číslo');
        }
        $type = (string) ($body['reportType'] ?? '');
        if (!isset(VatXmlMapping::DOCUMENT_BY_REPORT_TYPE[$type])) {
            throw self::badRequest('reportType musí být return, cs nebo rs');
        }
        $kind = trim((string) ($body['filingKind'] ?? ''));
        if ($kind === '') {
            throw self::badRequest('filingKind je povinný');
        }

        $dates = [];
        foreach (['dateIssue', 'dateFiled', 'dateFound'] as $key) {
            $value = $body[$key] ?? null;
            if ($value === null || $value === '') {
                $dates[$key] = null;
                continue;
            }
            if (!is_string($value) || preg_match(self::DATE_PATTERN, $value) !== 1) {
                throw self::badRequest("{$key} musí být datum YYYY-MM-DD nebo null");
            }
            $dates[$key] = $value;
        }

        $accDocument = $body['accDocumentId'] ?? null;
        if ($accDocument !== null && $accDocument !== '' && (!is_numeric($accDocument) || (int) $accDocument <= 0)) {
            throw self::badRequest('accDocumentId musí být kladné celé číslo nebo null');
        }
        $xml = $body['xml'] ?? null;
        if ($xml !== null && !is_string($xml)) {
            throw self::badRequest('xml musí být řetězec nebo null');
        }
        $legacy = $body['legacy'] ?? [];
        if ($legacy !== null && !is_array($legacy)) {
            throw self::badRequest('legacy musí být objekt nebo null');
        }
        $name = $body['name'] ?? null;

        return new self(
            reportPeriodId: $periodId,
            reportType: $type,
            filingKind: $kind,
            name: is_string($name) && trim($name) !== '' ? trim($name) : null,
            dateIssue: $dates['dateIssue'],
            dateFiled: $dates['dateFiled'],
            dateFound: $dates['dateFound'],
            accDocumentId: $accDocument === null || $accDocument === '' ? null : (int) $accDocument,
            xml: $xml === null || trim($xml) === '' ? null : $xml,
            legacy: $legacy ?? [],
        );
    }

    private static function badRequest(string $message): FilingImportException
    {
        return new FilingImportException('BAD_REQUEST', $message, 400);
    }
}
