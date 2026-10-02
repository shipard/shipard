<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Prints\PrintBuildException;
use Shipard\Core\Prints\PrintMessage;
use Shipard\Core\Prints\PrintRequest;
use Shipard\Core\Prints\PrintTranslator;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\DocTypes;

/**
 * Všechno, co bloky tisku dokladu potřebují, načtené jednou: hlavička,
 * řádky, rekapitulace DPH, snapshoty stran, jednotky a popisky DPH. Bloky
 * nad ním jsou čisté funkce bez databáze — `load()` je jediné místo se SQL.
 */
final class DocPrintContext
{
    /** @var list<PrintMessage> */
    private array $messages = [];

    /**
     * @param array<string, mixed> $head Řádek `docs_core_heads`.
     * @param list<array<string, mixed>> $rows Řádky dokladu v pořadí tisku.
     * @param list<array<string, mixed>> $recap Rekapitulace DPH v pořadí tisku.
     * @param array<string, mixed>|null $supplier Snapshot dodavatele; null,
     *        když doklad stranu nemá (D24).
     * @param array<string, mixed>|null $customer Snapshot odběratele.
     * @param array<int, string> $units id jednotky → zkratka.
     */
    public function __construct(
        public readonly array $head,
        public readonly array $rows,
        public readonly array $recap,
        public readonly ?array $supplier,
        public readonly ?array $customer,
        public readonly array $units,
        public readonly DocVatCodes $vatCodes,
        public readonly PrintTranslator $translator,
        public readonly ?ConfigRuntime $config,
    ) {}

    /**
     * Snapshot vlastní strany (výstup → dodavatel, vstup → odběratel) je
     * povinný; partnerský jen když hlavička má partnera — pokladní doklad
     * a prodejka ho mít nemusí (#90 D24). Doklad bez směru (účetní doklad)
     * nemá povinnou žádnou stranu.
     *
     * @throws PrintBuildException Doklad nemá povinný snapshot strany —
     *         tisk nesmí číst z dnešního adresáře (#90 D5).
     */
    public static function load(PrintRequest $request): self
    {
        $head   = $request->record;
        $headId = $request->recordId;

        $supplier = self::decodeSnapshot($head['supplier_snapshot'] ?? null);
        $customer = self::decodeSnapshot($head['customer_snapshot'] ?? null);

        $tradeDir   = DocDocument::resolveTradeDir($head, $request->config);
        $hasPartner = !empty($head['partner']);
        $required   = [
            'supplier' => $tradeDir === 1 || ($tradeDir === 2 && $hasPartner),
            'customer' => $tradeDir === 2 || ($tradeDir === 1 && $hasPartner),
        ];
        if (($required['supplier'] && $supplier === null) || ($required['customer'] && $customer === null)) {
            throw new PrintBuildException(
                "Document {$headId} has no party snapshot — it cannot be printed",
            );
        }

        $rows = $request->db->fetchAll(
            'SELECT * FROM [docs_core_rows] WHERE [doc_head] = %i ORDER BY [order_pos], [id]',
            $headId,
        );
        $recap = $request->db->fetchAll(
            'SELECT * FROM [docs_core_vat_recap] WHERE [doc_head] = %i ORDER BY [order_pos], [id]',
            $headId,
        );

        $units   = [];
        $unitIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['unit'] ?? 0),
            $rows,
        ))));
        if ($unitIds !== []) {
            foreach ($request->db->fetchAll(
                'SELECT [id], [shortcut] FROM [core_units] WHERE [id] IN %in',
                $unitIds,
            ) as $unit) {
                $units[(int) $unit['id']] = (string) $unit['shortcut'];
            }
        }

        // Země DPH: z registrace ve snapshotu vlastní strany; starší snapshot
        // bez ní → registrace z hlavičky.
        $own        = $tradeDir === 2 ? $customer : $supplier;
        $vatCountry = $own['vat_registration']['country'] ?? null;
        if (!is_string($vatCountry) && !empty($head['vat_registration'])) {
            $vatCountry = $request->db->fetchSingle(
                'SELECT [country] FROM [economy_codebooks_vat_registrations] WHERE [id] = %i',
                (int) $head['vat_registration'],
            );
        }

        return new self(
            head: $head,
            rows: $rows,
            recap: $recap,
            supplier: $supplier,
            customer: $customer,
            units: $units,
            vatCodes: DocVatCodes::fromConfig($request->config, is_string($vatCountry) ? $vatCountry : null),
            translator: $request->translator,
            config: $request->config,
        );
    }

    public function docType(): string
    {
        return (string) ($this->head['doc_type'] ?? '');
    }

    /** Směr obchodu: 1 výstup (my dodavatel), 2 vstup (my odběratel), null bez směru. */
    public function tradeDir(): ?int
    {
        return DocDocument::resolveTradeDir($this->head, $this->config);
    }

    /** Má doklad tištěnou rekapitulaci DPH? (Strana reverse charge páru se netiskne.) */
    public function hasVatRecap(): bool
    {
        if (!$this->showsVat()) {
            return false;
        }
        foreach ($this->recap as $row) {
            if (empty($row['is_reverse_pair'])) {
                return true;
            }
        }
        return false;
    }

    /** Vstup pro `TitleVariantResolver`. */
    public function titleContext(): DocTitleContext
    {
        return new DocTitleContext(
            docType: $this->docType(),
            vatPayer: $this->vatPayer(),
            tradeDir: $this->tradeDir(),
            hasVatRecap: $this->hasVatRecap(),
            totalAmount: (float) ($this->head['total_amount'] ?? 0.0),
        );
    }

    public function isTaxDocument(): bool
    {
        return DocTypes::isTaxDocument($this->config, $this->docType());
    }

    /** Plátce = hlavička dokladu má registraci k DPH (#90 D16). */
    public function vatPayer(): bool
    {
        return !empty($this->head['vat_registration']);
    }

    /** 0 bez DPH, 1 ze základu, 2 z ceny celkem. */
    public function vatMode(): int
    {
        return (int) ($this->head['vat_mode'] ?? 0);
    }

    /** Tiskne se DPH (sazby na řádcích, rekapitulace)? */
    public function showsVat(): bool
    {
        return $this->vatPayer() && $this->vatMode() !== 0;
    }

    public function currency(): string
    {
        return strtoupper((string) ($this->head['doc_currency'] ?? ''));
    }

    public function homeCurrency(): string
    {
        return strtoupper((string) ($this->head['home_currency'] ?? ''));
    }

    public function foreignCurrency(): bool
    {
        return $this->currency() !== '' && $this->homeCurrency() !== ''
            && $this->currency() !== $this->homeCurrency();
    }

    public function isAdvanceDeduction(array $row): bool
    {
        return ($row['operation'] ?? null) === 'sale.advanceDeduction';
    }

    public function addMessage(PrintMessage $message): void
    {
        $this->messages[] = $message;
    }

    /** @return list<PrintMessage> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** Částka / množství jako číslo v plné přesnosti; prázdná hodnota → null. */
    public static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /** Datum `YYYY-MM-DD`; prázdná hodnota → null. */
    public static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        return substr($value, 0, 10);
    }

    /** Text; prázdný / jen mezery → null. */
    public static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    /** @return array<string, mixed>|null Null = doklad stranu nemá. */
    private static function decodeSnapshot(mixed $snapshot): ?array
    {
        if (is_string($snapshot) && $snapshot !== '') {
            $snapshot = json_decode($snapshot, true);
        }
        return is_array($snapshot) && $snapshot !== [] ? $snapshot : null;
    }
}
