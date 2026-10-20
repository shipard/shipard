<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Mail\Analysis\AnalysisComputing;
use Shipard\Module\Core\Mail\Analysis\AnalysisOutcome;
use Shipard\Module\Core\Mail\Analysis\AnalysisRequest;
use Shipard\Module\Core\Mail\Analysis\AttachmentPreparer;
use Shipard\Module\Core\Mail\Analysis\ValidatedCanonical;
use Shipard\Module\Core\Mail\Isdoc\IsdocDocumentCollector;

/**
 * Směrování výpočtu evaluace podle produkce (#103 D76, D82): zpráva, u které
 * by předzpracování ISDOC rozpoznalo ({@see IsdocDocumentCollector::collect()}
 * — totéž, co `IsdocImportService::detect()`), jde cestou `isdoc`:
 * deterministický import ISDOC → canonical a obohacení
 * ({@see RowEnrichmentPipeline::enrichAtResult()} — Vrstva 0, pravidlo
 * IČO → štítek, jinak LLM klasifikace štítků) **bez AI extrakce**;
 * ostatní zprávy jdou do {@see AnalysisComputing} (cesta `ai`). Nic se
 * nezapisuje. Pipeline je táž instance jako u cesty AI — sdílí přepsání
 * štítků varianty a `takeTagUsage()`, takže volání cesty ISDOC nese jen
 * cenu štítků (extrakce 0 tokenů, 0 USD, model `isdoc`).
 *
 * Přílohy se čtou jako v `PreprocessRunner::runIsdocImport()`: bez
 * `raw_source_attachment`. Výjimka sběru (I/O, nástroj) = jako v produkci
 * zpráva do AI fronty — tady cesta `ai`, s varováním v logu. Selhání
 * obohacení není selhání volání (import pokračuje neobohaceně jako
 * v `IsdocImportService`).
 */
final class EvalComputationRouter implements AnalysisComputing, PathReporting
{
    private string $lastPath = EvalCallResult::PATH_AI;

    public function __construct(
        private readonly AnalysisComputing $ai,
        private readonly IsdocDocumentCollector $collector,
        private readonly AttachmentService $attachments,
        private readonly ?RowEnrichmentPipeline $enricher,
    ) {}

    public function lastPath(): string
    {
        return $this->lastPath;
    }

    public function compute(AnalysisRequest $request): AnalysisOutcome
    {
        $startedAt = microtime(true);
        $document = $this->collect($request);
        if ($document === null) {
            $this->lastPath = EvalCallResult::PATH_AI;
            return $this->ai->compute($request);
        }
        $this->lastPath = EvalCallResult::PATH_ISDOC;

        $canonical = $document['canonical'];
        $tagsUsage = null;
        if ($this->enricher !== null) {
            try {
                $canonical = $this->enricher->enrichAtResult($canonical);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'EvalComputationRouter: row enrichment failed — ISDOC canonical stays unenriched');
            }
            $tagsUsage = $this->enricher->takeTagUsage();
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $body = [
            'message_classification' => ['primary_type' => 'invoiceReceived'],
            'document' => $canonical,
        ];
        return AnalysisOutcome::success(
            $body,
            $body,
            new ValidatedCanonical((string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true, $tagsUsage),
            0,
            0,
            $durationMs,
            0.0,
            '',
            EvalCallResult::PATH_ISDOC,
            null,
        );
    }

    /** @return array{canonical: array<string, mixed>, attachmentId: int, docType: string, embedded: bool}|null */
    private function collect(AnalysisRequest $request): ?array
    {
        $rawId = (int) ($request->message['raw_source_attachment'] ?? 0);
        $files = [];
        foreach ($this->attachments->listAttachments(AttachmentPreparer::MAIL_TABLE_ID, $request->messageId) as $file) {
            $file = (array) $file;
            if ($rawId > 0 && (int) ($file['id'] ?? 0) === $rawId) {
                continue;
            }
            $files[] = $file;
        }
        if ($files === []) {
            return null;
        }
        try {
            return $this->collector->collect($request->messageId, $files);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'EvalComputationRouter: ISDOC collect failed — message goes the AI path');
            return null;
        }
    }
}
