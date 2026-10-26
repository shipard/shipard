<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Isdoc;

use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Isdoc\IsdocParseException;
use Shipard\Module\Core\Exchange\Isdoc\IsdocReader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Exchange\Values\CanonicalValueNormalizer;
use Shipard\Module\Core\Mail\IsdocImportService;

/**
 * Čistý sběr ISDOC → canonical z příloh došlé zprávy (#103 D76,
 * tasks/testing-rates-isdoc.md § 5) — kroky 1–2 importu bez zápisu
 * a bez obohacení: kandidáti (samostatné ISDOC přílohy + embedded v PDF),
 * validace schématu, dedup identitou (D8 z mail-isdoc-import) a volba
 * zdroje (samostatná příloha > embedded, pak nejnižší attachment id).
 *
 * Sdílí ho {@see IsdocImportService} (`detect()` v requestu, `doImport()`
 * v runneru předzpracování) a evaluace nanečisto
 * (`Eval\EvalComputationRouter`): co produkce rozpozná jako ISDOC, jde
 * v evaluaci cestou `isdoc` (D82). Bez spojení do DB; logování jako
 * import. Výjimky (I/O, nástroj) propadají — polyká je volající.
 *
 * Embedded ISDOC v PDF (PDF/A-3 /EmbeddedFiles) se extrahuje přes
 * `pdfdetach` (poppler-utils) — binárka chybí → embedded detekce vypnuta
 * s jednorázovým warningem. Embedded ISDOC je transientní — canonical
 * odkazuje na nosné PDF (kind `original`, strojová forma je uvnitř).
 */
class IsdocDocumentCollector
{
    // Binary name as property — test seam for simulating a missing tool
    // (vzor TextExtractor::pdftotextBin).
    protected string $pdfdetachBin = 'pdfdetach';

    /** Jednorázový warn o chybějícím pdfdetach (per proces). */
    private static bool $warnedMissingPdfdetach = false;

    private readonly IsdocReader $reader;

    public function __construct(
        private readonly SchemaValidator $schemaValidator,
        private readonly string $dsPath,
        ?IsdocReader $reader = null,
    ) {
        $this->reader = $reader ?? new IsdocReader();
    }

    /**
     * Vrací vybraný dokument, nebo null = větev se vzdává (žádný kandidát,
     * vadný samostatný ISDOC, více odlišných identit). Dvojí volání
     * (detekce v requestu, import v runneru) je záměr (#81 D4) — výsledek
     * se přes DB nepředává, runner čte přílohy znovu.
     *
     * @param list<array<string, mixed>> $uploadedFiles Návraty
     *        AttachmentService::upload (id, name, file_name, file_path,
     *        mime_type, …); bez raw .eml souboru.
     * @return array{canonical: array<string, mixed>, attachmentId: int, docType: string, embedded: bool}|null
     */
    public function collect(int $messageNdx, array $uploadedFiles): ?array
    {
        // ── 1. Kandidáti: samostatné ISDOC přílohy + embedded v PDF ─────────
        $candidates = [];
        foreach ($uploadedFiles as $file) {
            if (!is_array($file)) {
                continue;
            }

            if (IsdocImportService::isPotentialIsdocAttachment($file)) {
                $canonical = $this->readCandidate($messageNdx, $file);
                if ($canonical === false) {
                    return null; // vadný samostatný ISDOC → celá větev končí, AI fronta
                }
                if ($canonical !== null) {
                    $canonical['source']['message'] = $messageNdx;
                    $canonical['attachments'] = [self::attachmentEntry($file)];
                    if (!$this->passesSchema($messageNdx, $file, $canonical)) {
                        return null;
                    }
                    // Tvary hodnot (#118 D2) — po schématu, před dedupem identitou.
                    $canonical = CanonicalValueNormalizer::normalize($canonical);
                    $candidates[] = [
                        'canonical' => $canonical,
                        'attachmentId' => (int) ($file['id'] ?? 0),
                        'docType' => (string) $canonical['docType'],
                        'embedded' => false,
                    ];
                    continue;
                }
                // XML příloha, která není ISDOC — propadá k PDF checku níže
                // (teoreticky nemožné, PDF nemá XML mime; jen pro úplnost).
            }

            if (IsdocImportService::isPdfAttachment($file)) {
                foreach ($this->extractEmbeddedCandidates($messageNdx, $file) as $canonical) {
                    $canonical['source']['message'] = $messageNdx;
                    // Embedded ISDOC je transientní — canonical odkazuje na
                    // nosné PDF (kind original, strojová forma je uvnitř).
                    $canonical['attachments'] = [self::carrierPdfEntry($file)];
                    if (!$this->passesSchema($messageNdx, $file, $canonical)) {
                        continue; // vadný embedded → ignor, pokračuje se zbytkem
                    }
                    $canonical = CanonicalValueNormalizer::normalize($canonical);
                    $candidates[] = [
                        'canonical' => $canonical,
                        'attachmentId' => (int) ($file['id'] ?? 0),
                        'docType' => (string) $canonical['docType'],
                        'embedded' => true,
                    ];
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        // ── 2. Dedup identitou (D8): UUID, fallback kompozit ────────────────
        // Shodná identita = jeden doklad; kandidát bez identity nikdy
        // nesplyne s jiným (klíč per index) — konzervativně do AI fronty.
        $byIdentity = [];
        foreach ($candidates as $i => $candidate) {
            $key = $this->identityKey($candidate['canonical']) ?? ('anon:' . $i);
            $byIdentity[$key][] = $candidate;
        }

        // Zpráva → nejvýše jeden návrh (D1). Více odlišných identit se
        // deterministicky rozhodnout nedá → celá větev do AI fronty (AI
        // vybere primární dokument + secondary_findings).
        if (count($byIdentity) > 1) {
            ErrorLogger::warn('ISDOC import: multiple distinct ISDOC identities in one message — message goes to AI queue', [
                'message' => $messageNdx,
                'identities' => count($byIdentity),
            ]);
            return null;
        }

        // Preference zdroje: samostatná .isdoc příloha > embedded z PDF;
        // v rámci téhož druhu deterministicky nejnižší attachment id.
        $group = reset($byIdentity);
        usort($group, static fn(array $a, array $b): int =>
            [$a['embedded'], $a['attachmentId']] <=> [$b['embedded'], $b['attachmentId']]);
        return $group[0];
    }

    /**
     * Přečte jednoho kandidáta. Návraty: canonical array = OK; null =
     * příloha není ISDOC (XML bez ISDOC přípony s cizím rootem / nevalidní
     * XML — tichý skip); false = je to ISDOC, ale nejde zpracovat (vadný
     * obsah, nepodporovaný DocumentType, vadný ZIP) → celá větev se vzdává.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>|false|null
     */
    private function readCandidate(int $messageNdx, array $file): array|false|null
    {
        $extension = self::extensionOf($file);
        $isHardCandidate = $extension === 'isdoc' || $extension === 'isdocx';
        $displayName = (string) ($file['name'] ?? '');

        try {
            return $this->reader->fromFile($this->attachmentPath($file), $displayName);
        } catch (IsdocParseException $e) {
            $softMiss = in_array($e->reason, [
                IsdocParseException::REASON_FOREIGN_ROOT,
                IsdocParseException::REASON_INVALID_XML,
            ], true);
            if (!$isHardCandidate && $softMiss) {
                return null;
            }

            ErrorLogger::warn('ISDOC import: parse failed — message stays in AI queue', [
                'message' => $messageNdx,
                'attachment' => (int) ($file['id'] ?? 0),
                'filename' => $displayName,
                'reason' => $e->reason,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Validace canonicalu proti schématu — issues jen loguje a vrací false;
     * o osudu větve (fail vs. skip) rozhoduje volající dle druhu kandidáta.
     *
     * @param array<string, mixed> $file
     * @param array<string, mixed> $canonical
     */
    private function passesSchema(int $messageNdx, array $file, array $canonical): bool
    {
        $issues = $this->schemaValidator->validate(
            $canonical,
            DocumentApplier::FORMAT_ID,
            DocumentApplier::FORMAT_VERSION,
        );
        if ($issues === []) {
            return true;
        }
        ErrorLogger::warn('ISDOC import: canonical failed schema validation', [
            'message' => $messageNdx,
            'attachment' => (int) ($file['id'] ?? 0),
            'issues' => array_slice($issues, 0, 5),
        ]);
        return false;
    }

    /**
     * Extrakce embedded ISDOC souborů z PDF (PDF/A-3 /EmbeddedFiles) přes
     * `pdfdetach -saveall`. Graceful degradation: binárka chybí → prázdný
     * seznam + jednorázový warn; poškozené PDF / PDF bez embedded souborů →
     * prázdný seznam. Vadný embedded ISDOC se ignoruje (D8 — pokračuje se
     * zbytkem), jen se loguje.
     *
     * @param array<string, mixed> $file nosné PDF (návrat AttachmentService::upload)
     * @return list<array<string, mixed>> parsované canonicaly
     */
    protected function extractEmbeddedCandidates(int $messageNdx, array $file): array
    {
        $pdfPath = $this->attachmentPath($file);
        if (!is_file($pdfPath)) {
            return [];
        }

        $tmpDir = sys_get_temp_dir() . '/shpd-isdoc-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0700, true)) {
            return [];
        }

        try {
            $cmd = sprintf(
                '%s -saveall -o %s %s',
                $this->pdfdetachBin,
                escapeshellarg($tmpDir . '/'),
                escapeshellarg($pdfPath),
            );
            exec($cmd . ' 2>/dev/null', $output, $exitCode);
            if ($exitCode !== 0) {
                if ($exitCode === 127 && !self::$warnedMissingPdfdetach) {
                    self::$warnedMissingPdfdetach = true;
                    ErrorLogger::warn(
                        "ISDOC import: tool '{$this->pdfdetachBin}' is not installed — embedded ISDOC detection disabled"
                            . ' (sudo apt install poppler-utils)',
                    );
                }
                return [];
            }

            $canonicals = [];
            foreach (glob($tmpDir . '/*') ?: [] as $path) {
                $name = basename($path);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['isdoc', 'isdocx', 'xml'], true)) {
                    continue;
                }
                try {
                    $canonicals[] = $this->reader->fromFile($path, $name);
                } catch (IsdocParseException $e) {
                    // Vadný embedded → ignor, pokračuje se zbytkem (na rozdíl
                    // od samostatné přílohy, kde vadný ISDOC shazuje větev).
                    ErrorLogger::warn('ISDOC import: embedded ISDOC ignored (parse failed)', [
                        'message' => $messageNdx,
                        'carrier' => (int) ($file['id'] ?? 0),
                        'embedded' => $name,
                        'reason' => $e->reason,
                    ]);
                }
            }
            return $canonicals;
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Identitní klíč dokladu pro dedup (D8): element UUID; fallback kompozit
     * (ID dokladu + DIČ/IČ výstavce + datum vystavení). Null = identita
     * neurčitelná — kandidát se s ničím neslučuje.
     *
     * @param array<string, mixed> $canonical
     */
    protected function identityKey(array $canonical): ?string
    {
        $uuid = trim((string) ($canonical['source']['raw']['uuid'] ?? ''));
        if ($uuid !== '') {
            return 'uuid:' . mb_strtolower($uuid);
        }

        $docId = trim((string) ($canonical['source']['raw']['id'] ?? ''));
        $supplier = is_array($canonical['supplier'] ?? null) ? $canonical['supplier'] : [];
        $party = trim((string) ($supplier['taxId'] ?? $supplier['vatId'] ?? $supplier['companyId'] ?? ''));
        $issueDate = trim((string) ($canonical['dates']['issueDate'] ?? ''));

        if ($docId === '' || ($party === '' && $issueDate === '')) {
            return null;
        }
        return 'comp:' . mb_strtolower($docId) . '|' . mb_strtolower($party) . '|' . $issueDate;
    }

    /**
     * Canonical `attachments[]` entry pro nosné PDF embedded ISDOC —
     * kind `original` (dokument sám), ne `structured` (strojová forma je
     * uvnitř PDF, samostatná příloha z ní nevzniká).
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private static function carrierPdfEntry(array $file): array
    {
        $entry = [
            'filename' => (string) ($file['name'] ?? ''),
            'kind' => 'original',
            'ref' => 'att:' . (int) ($file['id'] ?? 0),
        ];
        $mime = trim((string) ($file['mime_type'] ?? ''));
        if ($mime !== '') {
            $entry['mimeType'] = $mime;
        }
        return $entry;
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed> Canonical `attachments[]` entry.
     */
    private static function attachmentEntry(array $file): array
    {
        $entry = [
            'filename' => (string) ($file['name'] ?? ''),
            // ISDOC je strojově čitelný formát — 'structured', ne 'original'
            // (viz docs/exchange-format.md §5, enum attachments[].kind).
            'kind' => 'structured',
            'ref' => 'att:' . (int) ($file['id'] ?? 0),
        ];
        $mime = trim((string) ($file['mime_type'] ?? ''));
        if ($mime !== '') {
            $entry['mimeType'] = $mime;
        }
        return $entry;
    }

    /**
     * Cesta k uloženému souboru na disku — stejná konvence jako
     * AttachmentService::getFilePath / MailController::cleanupOrphanedFiles.
     *
     * @param array<string, mixed> $file
     */
    private function attachmentPath(array $file): string
    {
        return $this->dsPath . '/att/' . (string) ($file['file_path'] ?? '')
            . '/' . (string) ($file['file_name'] ?? '');
    }

    /** @param array<string, mixed> $uploadedFile */
    private static function extensionOf(array $uploadedFile): string
    {
        return strtolower(pathinfo((string) ($uploadedFile['name'] ?? ''), PATHINFO_EXTENSION));
    }
}
