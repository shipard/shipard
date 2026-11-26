<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\ContactPayment;

use Dibi\Connection;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;

/**
 * Hradí fakturu už pokladní doklad, který do evidence tržeb spadá? (#171 D8c)
 * Kontaktní platba by pak tutéž tržbu zaevidovala podruhé — akce na to
 * v dialogu upozorní.
 *
 * Vazbu úhrady na fakturu řádek nenese odkazem; páruje se stejným klíčem
 * jako saldokonto, čistě nad daty dokladů (bez závislosti na saldokontu):
 * řádek úhrady (`payment.receivable`, u zálohy `advance.received`) se
 * stejným variabilním a specifickým symbolem, partner řádku = osoba pro
 * saldokonto faktury (jinak její partner) a shodná měna dokladu. Hledání
 * podle variabilního symbolu jde přes index, který na řádky dokladů přidává
 * extension doplňku (U25).
 *
 * „Do evidence spadá“ = pokladní doklad už tržbu má, nebo ho tak vyhodnotí
 * rozdílový výpočet (pravidlo nad řádky).
 */
class CashPaymentLookup
{
    /** Pohyby řádku, kterými pokladní doklad hradí pohledávku nebo zálohu. */
    public const array OPERATIONS = ['payment.receivable', 'advance.received'];

    /** Víc dokladů do varování nemá smysl vypisovat. */
    public const int LIMIT = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly DocFactsLoader $loader,
        private readonly SaleSync $sync,
    ) {
    }

    /**
     * Čísla dokladů V pořádku, které fakturu hradí a do evidence spadají.
     *
     * @param array<string, mixed> $head řádek hlavičky hrazené faktury
     * @return list<string>
     */
    public function payingDocuments(array $head): array
    {
        $reference = trim((string) ($head['payment_reference'] ?? ''));
        $partner = (int) ($head['partner_balance'] ?? 0) > 0 ? (int) $head['partner_balance'] : (int) ($head['partner'] ?? 0);
        if ($reference === '' || $partner <= 0) {
            return [];
        }

        $numbers = [];
        foreach ($this->candidates((int) ($head['id'] ?? 0), $reference, $partner, $head) as $candidate) {
            $doc = $this->loadDoc((int) $candidate['id']);
            if ($doc !== null && $this->falls($doc)) {
                $numbers[] = trim((string) $candidate['doc_number']) !== '' ? trim((string) $candidate['doc_number']) : '#' . $candidate['id'];
            }
        }
        return $numbers;
    }

    private function falls(DocFacts $doc): bool
    {
        return $this->hasSales($doc->id) || $this->sync->evaluation($doc)->applicable;
    }

    // ── Čtení (přepsatelné v testech) ───────────────────────────────────────

    /**
     * Doklady V pořádku s řádkem úhrady téhož klíče jako faktura.
     *
     * @param array<string, mixed> $head
     * @return list<array{id: int, doc_number: ?string}>
     */
    protected function candidates(int $invoiceId, string $reference, int $partner, array $head): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT h.[id], h.[doc_number] FROM %n r JOIN %n h ON h.[id] = r.[doc_head]'
            . ' WHERE r.[payment_reference] = %s AND r.[partner] = %i AND r.[operation] IN %in AND r.[row_kind] = 1'
            . ' AND COALESCE(TRIM(r.[specific_symbol]), %s) = %s'
            . ' AND h.[docState] = %i AND h.[id] <> %i AND h.[doc_currency] = %s'
            . ' ORDER BY h.[id] LIMIT %i',
            DocFactsLoader::ROWS,
            DocFactsLoader::HEADS,
            $reference,
            $partner,
            self::OPERATIONS,
            '',
            trim((string) ($head['specific_symbol'] ?? '')),
            DocFacts::STATE_CONFIRMED,
            $invoiceId,
            (string) ($head['doc_currency'] ?? ''),
            self::LIMIT,
        );
        return array_map(
            static fn($row): array => ['id' => (int) $row['id'], 'doc_number' => $row['doc_number']],
            $rows,
        );
    }

    protected function loadDoc(int $docId): ?DocFacts
    {
        return $this->loader->load($docId);
    }

    protected function hasSales(int $docId): bool
    {
        return $this->db->fetchSingle('SELECT 1 FROM %n WHERE [doc_head] = %i LIMIT 1', SaleSync::TABLE, $docId) !== null;
    }
}
