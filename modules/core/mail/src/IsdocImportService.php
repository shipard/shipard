<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Isdoc\IsdocParseException;
use Shipard\Module\Core\Exchange\Isdoc\IsdocReader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Deterministický import ISDOC příloh došlé zprávy místo AI analýzy
 * (tasks/mail-isdoc-import.md, tasks/mail-isdoc-content-tags.md #81).
 *
 * Datový tok: MailController (intake i ruční upload) po commitu zavolá jen
 * {@see detect()} — bez zápisu — a zprávu s platným ISDOC odloží do
 * runneru předzpracování (`preprocess_state` 10, `trigger: 'isdoc'`).
 * Import sám ({@see tryImport()}) běží výhradně v detached runneru
 * (PreprocessRunner), kde smí volat LLM obsahové eskalace, aniž by
 * zpomalil HTTP request. Úspěch = zpráva přeskočí AI frontu
 * (analysis_state → 30), v tabu Analýzy vznikne záznam `model_name='isdoc'`
 * s canonical návrhem, confidence 1.0 a `content_tag` ze štítku.
 *
 * Invarianty:
 *   - nikdy nesmí shodit příjem pošty — tryImport polyká všechny výjimky,
 *     návrat false = zpráva zůstává v AI frontě,
 *   - parse + validace + enrichment (`RowEnrichmentPipeline::enrichAtResult`
 *     — Vrstva 0 + pravidlo IČO → štítek, jinak LLM) běží před otevřením
 *     zápisové tx; LLM volání nikdy uvnitř tx. Bez pipeline (DS bez AI,
 *     selhaný wiring) se importuje neobohaceně,
 *   - FOR UPDATE guard řeší závod s analyzerem (okno mezi commitem intake
 *     a začátkem importu): analysis_state mimo {0, 10} = claim vyhrál,
 *     import se vzdá,
 *   - všechny samostatné ISDOC přílohy zprávy musí projít (all-or-nothing)
 *     — jediný vadný ISDOC / nepodporovaný DocumentType pošle celou zprávu
 *     do AI; vadný **embedded** ISDOC (uvnitř PDF) se naopak jen ignoruje
 *     a pokračuje se zbytkem (D8 z mail-message-centric),
 *   - zpráva má nejvýše jeden dokumentový návrh (D1) — kandidáti se
 *     deduplikují identitou (element UUID, fallback kompozit číslo dokladu
 *     + DIČ/IČ výstavce + datum vystavení); shodná identita = jeden doklad
 *     (preference samostatná příloha > embedded), více odlišných identit →
 *     větev se celá vzdá, AI vybere primární dokument,
 *   - partner zprávy (`partner_name` / `partner_person`) a titulek
 *     `ai_title` si plní sám přes {@see MessagePartnerWriter}
 *     a {@see MessageTitleComposer} — ISDOC obchází `/result`
 *     (tasks/mail-message-title-partner.md P6); bez injektovaných
 *     závislostí se partner ani titulek nezapisují (unit testy).
 *
 * Embedded ISDOC v PDF (PDF/A-3 /EmbeddedFiles) se extrahuje přes
 * `pdfdetach` (poppler-utils) — binárka chybí → embedded detekce vypnuta
 * s jednorázovým warningem, intake nikdy neselže. Embedded ISDOC je
 * transientní — nevytváří se z něj příloha zprávy (nosné PDF na zprávě je);
 * do canonicalu jde `attachments[]` odkaz na nosné PDF (kind `original`,
 * ne `structured` — strojová forma je uvnitř).
 */
class IsdocImportService
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';

    /**
     * analysis_state hodnoty (core.mail.analysisStates), ve kterých smí
     * import proběhnout — 0 (DS bez AI) i 10 (ve frontě). Sdílí FOR UPDATE
     * guard importu a podmíněné odložení v MailController (#81 D4).
     */
    public const ANALYSIS_IMPORTABLE_STATES = [0, 10];
    private const ANALYSIS_ANALYZED = 30;

    /** docState hodnoty (core.mail.docStatesIncoming). */
    private const DOC_STATE_NEW = 10;
    private const DOC_STATE_IN_PROGRESS = 20;
    private const DOC_STATE_IN_PROGRESS_MAIN = 2;

    private const XML_MIME_TYPES = ['application/xml', 'text/xml'];

    // Binary name as property — test seam for simulating a missing tool
    // (vzor TextExtractor::pdftotextBin).
    protected string $pdfdetachBin = 'pdfdetach';

    /** Jednorázový warn o chybějícím pdfdetach (per proces). */
    private static bool $warnedMissingPdfdetach = false;

    private readonly IsdocReader $reader;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly SchemaValidator $schemaValidator,
        private readonly ?RowEnrichmentPipeline $enricher,
        private readonly string $dsPath,
        ?IsdocReader $reader = null,
        private readonly ?MessagePartnerWriter $partnerWriter = null,
        private readonly ?MessageTitleComposer $titleComposer = null,
    ) {
        $this->reader = $reader ?? new IsdocReader();
    }

    /**
     * Rychlá detekce kandidáta bez instance service — MailController podle
     * ní rozhoduje, zda vůbec service (lazy) postavit. Samostatný ISDOC
     * (přípona/XML mime) **nebo** PDF (možný nosič embedded ISDOC).
     *
     * @param array<string, mixed> $uploadedFile Návrat AttachmentService::upload.
     */
    public static function isPotentialCandidate(array $uploadedFile): bool
    {
        return self::isPotentialIsdocAttachment($uploadedFile)
            || self::isPdfAttachment($uploadedFile);
    }

    /**
     * Samostatná ISDOC příloha: přípona .isdoc/.isdocx (case-insensitive),
     * nebo XML mime (root element pak ověří až plný parse v tryImport).
     *
     * @param array<string, mixed> $uploadedFile
     */
    public static function isPotentialIsdocAttachment(array $uploadedFile): bool
    {
        $extension = self::extensionOf($uploadedFile);
        if ($extension === 'isdoc' || $extension === 'isdocx') {
            return true;
        }
        $mime = strtolower(trim((string) ($uploadedFile['mime_type'] ?? '')));
        return in_array($mime, self::XML_MIME_TYPES, true);
    }

    /**
     * PDF příloha — kandidát na nosiče embedded ISDOC (PDF/A-3).
     *
     * @param array<string, mixed> $uploadedFile
     */
    public static function isPdfAttachment(array $uploadedFile): bool
    {
        if (self::extensionOf($uploadedFile) === 'pdf') {
            return true;
        }
        return strtolower(trim((string) ($uploadedFile['mime_type'] ?? ''))) === 'application/pdf';
    }

    /**
     * Zkusí deterministický import ISDOC příloh zprávy. Vrací true, když
     * import proběhl (zpráva je Analyzována); false = žádný ISDOC, vadný
     * ISDOC nebo prohraný závod s analyzerem — zpráva zůstává, jak byla.
     *
     * @param list<array<string, mixed>> $uploadedFiles Návraty
     *        AttachmentService::upload z intake (id, name, file_name,
     *        file_path, mime_type, …); bez raw .eml souboru.
     */
    public function tryImport(int $messageNdx, array $uploadedFiles): bool
    {
        try {
            return $this->doImport($messageNdx, $uploadedFiles);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'IsdocImportService::tryImport failed — message stays in AI queue');
            return false;
        }
    }

    /**
     * Detekce bez zápisu (#81 D4): parse, validace schématu a dedup
     * identitou — přesně to, co import zopakuje v runneru. True = zpráva
     * nese právě jeden platný ISDOC doklad a má se odložit do runneru
     * předzpracování; false = žádný / vadný ISDOC, více identit nebo
     * výjimka (polyká se jako v tryImport) → zpráva jde do AI fronty.
     *
     * @param list<array<string, mixed>> $uploadedFiles Jako u tryImport.
     */
    public function detect(int $messageNdx, array $uploadedFiles): bool
    {
        try {
            return $this->collectDocument($messageNdx, $uploadedFiles) !== null;
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'IsdocImportService::detect failed — message goes to AI queue');
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $uploadedFiles
     */
    private function doImport(int $messageNdx, array $uploadedFiles): bool
    {
        $startedAt = microtime(true);

        // ── 1. Kandidáti + dedup (mimo tx, sdílené s detect()) ──────────────
        $document = $this->collectDocument($messageNdx, $uploadedFiles);
        if ($document === null) {
            return false;
        }

        if ($this->enricher !== null) {
            // Obohacení řádků: Vrstva 0 (historie partnera) + obsahová
            // eskalace (pravidlo IČO → štítek, jinak LLM) — persist jako
            // /result. Běží PŘED zápisovou tx (LLM latence nesmí držet
            // zámky); selhání není fatální, pokračuje se neobohaceně.
            try {
                $document['canonical'] = $this->enricher->enrichAtResult($document['canonical']);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'IsdocImportService row enrichment failed');
            }
        }

        $modelVersion = isset($document['canonical']['source']['raw']['version'])
            ? (string) $document['canonical']['source']['raw']['version']
            : null;

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $now = date('Y-m-d H:i:s');

        // ── 2. Zápisová tx s guardem ────────────────────────────────────────
        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $msg = $dibi->fetch(
                'SELECT analysis_state, docState, primary_type_source, created_by
                   FROM %n WHERE id = %i FOR UPDATE',
                self::MESSAGES_TABLE,
                $messageNdx,
            );
            if ($msg === null) {
                $dibi->rollback();
                return false;
            }
            if (!in_array((int) $msg['analysis_state'], self::ANALYSIS_IMPORTABLE_STATES, true)) {
                // Mezitím si zprávu stihl claimnout analyzer — nechat mu ji.
                $dibi->rollback();
                return false;
            }
            $createdBy = $msg['created_by'] !== null ? (int) $msg['created_by'] : null;

            $dibi->insert(self::ANALYSES_TABLE, [
                'message' => $messageNdx,
                'profile' => null,
                'backend' => null,
                'analyzed_at' => $now,
                'status' => 2, // success
                'model_name' => 'isdoc',
                'model_version' => $modelVersion,
                'prompt_version' => 'isdoc',
                'canonical_json' => (string) json_encode(
                    $document['canonical'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                'proposed_type' => $document['docType'],
                // Denormalizace štítku obsahové eskalace (karta Nová
                // kategorie, learning) — stejný helper jako /result.
                'content_tag' => RowEnrichmentPipeline::contentTagOf($document['canonical']),
                'confidence' => 1.0,
                'duration_ms' => $durationMs,
                'created' => $now,
                'created_by' => $createdBy,
            ])->execute();

            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => self::ANALYSIS_ANALYZED,
                'needs_reanalysis' => 0,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            // Ruční volba typu má vždy přednost (stejné pravidlo jako AI).
            if ((string) $msg['primary_type_source'] !== 'user') {
                $dibi->update(self::MESSAGES_TABLE, [
                    'primary_type' => 'invoiceReceived',
                    'primary_type_source' => 'isdoc',
                ])
                ->where('id = %i', $messageNdx)
                ->where('primary_type_source != %s', 'user')
                ->execute();
            }

            // Workflow: Nová → K řešení, jen když stav mezitím nikdo nezměnil.
            if ((int) $msg['docState'] === self::DOC_STATE_NEW) {
                $dibi->update(self::MESSAGES_TABLE, [
                    'docState' => self::DOC_STATE_IN_PROGRESS,
                    'docStateMain' => self::DOC_STATE_IN_PROGRESS_MAIN,
                ])
                ->where('id = %i', $messageNdx)
                ->where('docState = %i', self::DOC_STATE_NEW)
                ->execute();
            }

            // Partner zprávy z canonicalu (vrstva 1) — ISDOC obchází /result,
            // plní si ho sám. Best-effort: selhání import neshodí.
            if ($this->partnerWriter !== null) {
                try {
                    $this->partnerWriter->writeFromCanonical(
                        $dibi,
                        $messageNdx,
                        $document['canonical'],
                        $document['docType'],
                    );
                } catch (\Throwable $e) {
                    ErrorLogger::warn('ISDOC import: partner write failed', [
                        'message' => $messageNdx,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Titulek zprávy z canonicalu (D2) — u ISDOC je composer primární
            // zdroj (žádná AI). Best-effort jako partner.
            if ($this->titleComposer !== null) {
                try {
                    $dibi->update(self::MESSAGES_TABLE, [
                        'ai_title' => $this->titleComposer->compose($document['canonical'], $document['docType']),
                    ])->where('id = %i', $messageNdx)->execute();
                } catch (\Throwable $e) {
                    ErrorLogger::warn('ISDOC import: title write failed', [
                        'message' => $messageNdx,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            throw $e;
        }

        return true;
    }

    /**
     * Kroky 1–2 importu bez zápisu: kandidáti (samostatné ISDOC přílohy
     * + embedded v PDF), validace schématu, dedup identitou (D8) a volba
     * zdroje (samostatná příloha > embedded, pak nejnižší attachment id).
     * Vrací vybraný dokument, nebo null = větev se vzdává (žádný kandidát,
     * vadný samostatný ISDOC, více odlišných identit). Sdílí ho detect()
     * v requestu a doImport() v runneru — dvojí detekce je záměr (#81 D4),
     * výsledek se přes DB nepředává, runner čte přílohy znovu.
     *
     * @param list<array<string, mixed>> $uploadedFiles
     * @return array{canonical: array<string, mixed>, attachmentId: int, docType: string, embedded: bool}|null
     */
    private function collectDocument(int $messageNdx, array $uploadedFiles): ?array
    {
        // ── 1. Kandidáti: samostatné ISDOC přílohy + embedded v PDF ─────────
        $candidates = [];
        foreach ($uploadedFiles as $file) {
            if (!is_array($file)) {
                continue;
            }

            if (self::isPotentialIsdocAttachment($file)) {
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

            if (self::isPdfAttachment($file)) {
                foreach ($this->extractEmbeddedCandidates($messageNdx, $file) as $canonical) {
                    $canonical['source']['message'] = $messageNdx;
                    // Embedded ISDOC je transientní — canonical odkazuje na
                    // nosné PDF (kind original, strojová forma je uvnitř).
                    $canonical['attachments'] = [self::carrierPdfEntry($file)];
                    if (!$this->passesSchema($messageNdx, $file, $canonical)) {
                        continue; // vadný embedded → ignor, pokračuje se zbytkem
                    }
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

    /**
     * @param array<string, mixed> $uploadedFile
     */
    private static function extensionOf(array $uploadedFile): string
    {
        return strtolower(pathinfo((string) ($uploadedFile['name'] ?? ''), PATHINFO_EXTENSION));
    }
}
