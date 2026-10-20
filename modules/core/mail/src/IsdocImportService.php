<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Isdoc\IsdocReader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\Isdoc\IsdocDocumentCollector;

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
 * Čistou část (kandidáti → canonical, validace schématu, dedup identitou,
 * embedded ISDOC v PDF přes `pdfdetach`) dělá {@see IsdocDocumentCollector}
 * — sdílí ji evaluace nanečisto (#103 D76), která zprávu s ISDOC
 * zpracuje touž cestou bez AI extrakce.
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

    private readonly IsdocDocumentCollector $collector;

    /**
     * @param IsdocDocumentCollector|null $collector sběr ISDOC → canonical; výchozí nad
     *        `$schemaValidator`, `$dsPath` a `$reader` (testy podstrkují vlastní)
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        SchemaValidator $schemaValidator,
        private readonly ?RowEnrichmentPipeline $enricher,
        string $dsPath,
        ?IsdocReader $reader = null,
        private readonly ?MessagePartnerWriter $partnerWriter = null,
        private readonly ?MessageTitleComposer $titleComposer = null,
        ?IsdocDocumentCollector $collector = null,
    ) {
        $this->collector = $collector ?? new IsdocDocumentCollector($schemaValidator, $dsPath, $reader);
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
     * identitou ({@see IsdocDocumentCollector::collect()}) — přesně to, co
     * import zopakuje v runneru. True = zpráva nese právě jeden platný ISDOC
     * doklad a má se odložit do runneru předzpracování; false = žádný /
     * vadný ISDOC, více identit nebo výjimka (polyká se jako v tryImport)
     * → zpráva jde do AI fronty.
     *
     * @param list<array<string, mixed>> $uploadedFiles Jako u tryImport.
     */
    public function detect(int $messageNdx, array $uploadedFiles): bool
    {
        try {
            return $this->collector->collect($messageNdx, $uploadedFiles) !== null;
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
        $document = $this->collector->collect($messageNdx, $uploadedFiles);
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
     * @param array<string, mixed> $uploadedFile
     */
    private static function extensionOf(array $uploadedFile): string
    {
        return strtolower(pathinfo((string) ($uploadedFile['name'] ?? ''), PATHINFO_EXTENSION));
    }
}
