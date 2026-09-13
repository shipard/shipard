<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Economy\Vat\FilingDocument;

/**
 * Soubory podání pro daňový portál (issue #55, X6): validace → XML →
 * kontrola proti XSD → PDF → přílohy podání.
 *
 * **XML je povinné, PDF ne** (X7). Selhání validace nebo schématu je
 * výjimka a neuloží se nic; selhání renderu je warning a XML se uloží.
 *
 * Ve stavu Sestaveno lze generování opakovat — starší `epo-*` přílohy se
 * nejdřív smažou (snapshot je týž, soubory se jen přepíšou). Do stavu
 * Podáno se soubory dogenerují automaticky při přechodu, když chybí, a
 * dál už se nemění: podané přílohy jsou doklad o tom, co odešlo
 * (`FilingAttachmentGuard`).
 */
final class FilingFilesService
{
    public const KIND_XML     = 'epo-xml';
    public const KIND_PREVIEW = 'epo-preview';
    public const KIND_CONTENT = 'epo-content';

    /**
     * Původní soubor importovaného podání, který není XML (opis, obsah —
     * #55 D36). Nese prefix vlastních souborů, aby ho po podání chránil
     * `FilingAttachmentGuard` stejně jako generované.
     */
    public const KIND_IMPORTED = 'epo-imported';

    /** Prefix, podle kterého se poznají vlastní soubory podání. */
    public const KIND_PREFIX = 'epo-';

    /** `economy_vat_filings.tableId` (tables/economy_vat_filings.jsonc). */
    public const TABLE_ID = 443;

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?AttachmentService $attachments = null,
        private readonly ?FilingPdfRenderer $pdf = null,
    ) {}

    /**
     * Vyrobí soubory a uloží je jako přílohy podání.
     *
     * @param bool $xmlOnly bez PDF (rychlé generování pro E2E a diff)
     */
    public function generate(int $filingId, bool $xmlOnly = false, ?int $userId = null): FilingFilesResult
    {
        // Importované podání má původní soubory ze starého systému (#55 D34)
        // — generovat vedle nich nový soubor nedává smysl a u konceptu by
        // se originály navíc smazaly. `build()` zůstává (diff pro D39).
        $filing = $this->filingRow($filingId);
        if ((string) ($filing['origin'] ?? '') === FilingDocument::ORIGIN_IMPORTED) {
            throw new \DomainException(
                "Podání #{$filingId} je importované ze starého systému — jeho soubory jsou původní přílohy,"
                . ' nové se negenerují.',
            );
        }

        $result = $this->build($filingId, $xmlOnly);
        if ($this->attachments === null) {
            return $result;
        }

        // Přegenerování konceptu nahrazuje předchozí sadu; podané podání
        // svoje soubory nikdy nepřepisuje (dogenerují se jen chybějící).
        if ((int) ($filing['docState'] ?? 0) === FilingDocument::DOC_STATE_COMPOSED) {
            $this->deleteOwnAttachments($filingId);
        }

        $ids = [];
        foreach ($result->files as $file) {
            $ids[] = $this->store($filingId, $file, $userId);
        }
        return $result->withAttachments($ids);
    }

    /** Vyrobí soubory bez ukládání (CLI `--out`, testy, zlatý test). */
    public function build(int $filingId, bool $xmlOnly = false): FilingFilesResult
    {
        $input   = (new FilingXmlInputLoader($this->db))->load($filingId);
        $mapping = VatXmlMapping::forReportType($this->config, $input->reportType);
        if ($mapping === null) {
            throw new \RuntimeException(
                'Chybí kompilovaná konfigurace XML podání (economy.vat.xml.cz) — spusťte ds-upgrade.',
            );
        }

        (new FilingXmlValidator($mapping))->assertValid($input);

        $xml = $this->writer($mapping, $input->reportType)->write($input);
        (new EpoXsdValidator())->assertValid($xml, $input->reportType);

        $baseName = $this->baseName($filingId, $mapping, $input);
        $files    = [new FilingFile(self::KIND_XML, $baseName . '.xml', $xml, 'application/xml')];
        $warnings = [];

        if (!$xmlOnly && $this->pdf !== null) {
            foreach ($this->pdf->render($filingId, $input, $baseName) as $file) {
                $files[] = $file;
            }
            $warnings = $this->pdf->warnings();
        }

        return new FilingFilesResult($files, [], $warnings);
    }

    /** Má podání vygenerované XML? (rozhoduje o dogenerování při podání) */
    public function hasXml(int $filingId): bool
    {
        foreach ($this->ownAttachments($filingId) as $attachment) {
            if (self::kindOf($attachment) === self::KIND_XML) {
                return true;
            }
        }
        return false;
    }

    /**
     * Přílohy, které vygenerovalo podání samo (podle `metadata.kind`).
     *
     * @return list<array<string, mixed>>
     */
    public function ownAttachments(int $filingId): array
    {
        if ($this->attachments === null) {
            return [];
        }
        return array_values(array_filter(
            $this->attachments->listAttachments(self::TABLE_ID, $filingId),
            static fn (array $attachment): bool =>
                str_starts_with((string) self::kindOf($attachment), self::KIND_PREFIX),
        ));
    }

    /** `metadata.kind` přílohy; `null` u ručně nahraného souboru. */
    public static function kindOf(array $attachment): ?string
    {
        $metadata = $attachment['metadata'] ?? null;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        return is_array($metadata) && isset($metadata['kind']) ? (string) $metadata['kind'] : null;
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    private function writer(VatXmlMapping $mapping, string $reportType): EpoXmlWriter
    {
        return match ($reportType) {
            'return' => new Dp3XmlWriter($mapping),
            'cs'     => new Kh1XmlWriter($mapping),
            'rs'     => new ShvXmlWriter($mapping),
            default  => throw new \DomainException("Pro typ tvrzení '{$reportType}' není generátor XML"),
        };
    }

    /**
     * Jméno souborů: `DPHDP3-<dic>-<rok>-<MM|Qn>[-<druh>-<pořadí>]`.
     * Druh a pořadí nese jen podání, které není řádné — jinak by si dvě
     * podání téže instance přepsala soubory ve stejném adresáři.
     */
    private function baseName(int $filingId, VatXmlMapping $mapping, FilingXmlInput $input): string
    {
        $period = $input->period->month !== null
            ? sprintf('%02d', $input->period->month)
            : ($input->period->quarter !== null ? 'Q' . $input->period->quarter : 'X');

        $parts = [
            $mapping->element(),
            EpoXmlFormat::taxNumberDigits($input->header['dic'] ?? null) ?? 'bez-dic',
            (string) $input->period->year,
            $period,
        ];

        if ($input->filingKind !== FilingDocument::KIND_REGULAR) {
            $parts[] = $input->filingKind;
            $parts[] = (string) $this->sequence($filingId);
        }
        return implode('-', $parts);
    }

    private function sequence(int $filingId): int
    {
        return (int) $this->db->fetchSingle(
            'SELECT [sequence] FROM %n WHERE [id] = %i',
            FilingDocument::TABLE,
            $filingId,
        );
    }

    /** @return array{docState: int, origin: string} */
    private function filingRow(int $filingId): array
    {
        $row = $this->db->fetch(
            'SELECT [docState], [origin] FROM %n WHERE [id] = %i',
            FilingDocument::TABLE,
            $filingId,
        );
        if ($row === null) {
            throw new \DomainException("Podání #{$filingId} nenalezeno");
        }
        return ['docState' => (int) $row['docState'], 'origin' => (string) $row['origin']];
    }

    private function deleteOwnAttachments(int $filingId): void
    {
        foreach ($this->ownAttachments($filingId) as $attachment) {
            $this->attachments?->softDelete((int) $attachment['id']);
        }
    }

    /** Uloží soubor jako přílohu podání a označí ho `metadata.kind`. */
    private function store(int $filingId, FilingFile $file, ?int $userId): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epo');
        if ($tmp === false) {
            throw new \RuntimeException('Nelze vytvořit dočasný soubor pro přílohu podání');
        }

        try {
            file_put_contents($tmp, $file->content);
            $uploaded = $this->attachments?->upload(self::TABLE_ID, $filingId, $file->name, $tmp, $userId);
        } finally {
            @unlink($tmp);
        }

        if ($uploaded === null || ($uploaded['success'] ?? false) !== true) {
            throw new \RuntimeException(
                'Přílohu podání nelze uložit: ' . (string) ($uploaded['error'] ?? 'neznámá chyba'),
            );
        }

        $id = (int) $uploaded['data']['id'];
        $this->attachments?->mergeMetadata($id, ['kind' => $file->kind]);
        return $id;
    }
}
