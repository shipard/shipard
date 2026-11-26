<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Sales;

use Dibi\Connection;
use Dibi\UniqueConstraintViolationException;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocSaleEvaluator;
use Shipard\Module\Economy\SalesRegistration\Evaluation\Money;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\SerialNumber;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;

/**
 * Rozdílové zasílání (#171 D6, D20, U7): tržby dokladu se srovnají s částkou,
 * která do evidence spadá. Rozdíl = částka podle vyhodnocení − součet VŠECH
 * dosavadních tržeb dokladu (bez ohledu na jejich stav); nenulový rozdíl je
 * nová tržba `pending`. Storno dokladu tak dá zápornou tržbu, oprava rozdíl
 * a oprava beze změny částky nic.
 *
 * Výpočet je idempotentní — stejnou cestou jde handler přechodu dokladu
 * i záchranná síť, která chybějící tržbu dorovná. Aby dva souběžné výpočty
 * nevytvořily dvojí rozdíl:
 *
 *  - běží pod pojmenovaným zámkem dokladu (`GET_LOCK`, funguje i uvnitř cizí
 *    transakce — handler z applieru běží před jeho commitem);
 *  - (doklad, pořadí tržby) má unikátní index; duplicita = tržbu už vytvořil
 *    jiný proces, přepočítá se.
 *
 * Čas tržby je `doc_state_changed_at` dokladu (D20) — záchranná síť tak
 * tržbě dá čas přechodu, ne čas, kdy ji našla. Doklad, který už tržbu má,
 * zůstává u její registrace; vyrovnávací tržba dokladu, který do evidence už
 * nespadá (storno, změna způsobu úhrady), bere registraci, jednotku
 * a označení pokladny z poslední tržby.
 */
class SaleSync
{
    public const string TABLE = 'economy_sales_registration_sales';

    public const string STATE_PENDING = 'pending';

    /** Jak dlouho čekat na zámek dokladu (s). */
    public const int LOCK_TIMEOUT = 5;

    /** Kolikrát přepočítat po duplicitě na unikátním indexu. */
    private const int MAX_RETRIES = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly DocFactsLoader $loader,
        private readonly ?ConfigRuntime $config = null,
        private readonly DocSaleEvaluator $evaluator = new DocSaleEvaluator(),
        private readonly int $lockTimeout = self::LOCK_TIMEOUT,
    ) {
    }

    public static function create(Connection $db, ?ConfigRuntime $config, ?DataSourceConfig $dsConfig): self
    {
        return new self($db, new DocFactsLoader($db, $config, $dsConfig), $config);
    }

    /**
     * Srovná tržby dokladu s částkou, která do evidence spadá.
     *
     * @param bool $dryRun nic nezapíše, jen řekne, co by vzniklo
     */
    public function sync(int $docId, ?\DateTimeImmutable $now = null, bool $dryRun = false): SyncResult
    {
        $now ??= new \DateTimeImmutable();

        // Rychlá cesta bez zámku: doklad bez tržeb, který nespadá, je hotový
        // (většina dokladů — přijaté faktury, faktury převodem).
        $doc = $this->loadDoc($docId);
        if ($doc === null) {
            return SyncResult::none($docId);
        }
        if (!$this->hasSales($docId)) {
            $evaluation = $this->evaluator->evaluate($doc, $this->registrations(), $this->soldAt($doc, $now));
            if (!$evaluation->applicable && !$evaluation->unregistrable) {
                return SyncResult::none($docId);
            }
        }

        if (!$this->lock($docId)) {
            return SyncResult::busy($docId);
        }
        try {
            for ($try = 0; $try < self::MAX_RETRIES; $try++) {
                $doc = $this->loadDoc($docId);
                if ($doc === null) {
                    return SyncResult::none($docId);
                }
                $result = $this->difference($doc, $now, $dryRun);
                if ($result !== null) {
                    return $result;
                }
            }
            return SyncResult::busy($docId);
        } finally {
            $this->unlock($docId);
        }
    }

    /** Jeden výpočet rozdílu; null = duplicita na unikátním indexu, přepočítat. */
    private function difference(DocFacts $doc, \DateTimeImmutable $now, bool $dryRun): ?SyncResult
    {
        if (!in_array($doc->docState, [DocFacts::STATE_CONFIRMED, DocFacts::STATE_CANCELLED], true)) {
            // Koncept a V opravě se nevyhodnocují — rozdíl se počítá až po
            // návratu do V pořádku nebo po stornu.
            return SyncResult::none($doc->id);
        }

        $sales = $this->salesOf($doc->id);
        $last = $sales !== [] ? $sales[array_key_last($sales)] : null;
        $soldAt = $this->soldAt($doc, $now);

        $evaluation = $this->evaluator->evaluate(
            $doc,
            $this->registrations(),
            $soldAt,
            $last !== null ? (int) $last['registration'] : null,
        );
        // Storno vyhodnocení rozhodne dřív, než přijde na registraci —
        // vyrovnávací tržba tak vznikne i po jejím zániku.
        if ($evaluation->unregistrable) {
            return SyncResult::unregistrable($doc->id, $evaluation->reason, $evaluation->registration?->id);
        }

        $expected = $evaluation->applicable ? $evaluation->cents : 0;
        $sum = 0;
        foreach ($sales as $sale) {
            $sum += Money::cents($sale['amount']);
        }
        $difference = $expected - $sum;
        if ($difference === 0) {
            return SyncResult::none($doc->id);
        }

        $sequence = $last !== null ? ((int) $last['doc_sequence']) + 1 : 1;
        if ($evaluation->applicable) {
            $type = $evaluation->registration->type;
            $data = [
                'registration'     => $evaluation->registration->id,
                'unit'             => $evaluation->unit,
                'unit_id'          => $evaluation->unitId,
                'cash_register_id' => $evaluation->cashRegisterId,
                'currency'         => $type->currency,
            ];
        } else {
            // $last existuje: bez tržeb by rozdíl proti nule byl nulový.
            $type = $this->typeOfRegistration((int) $last['registration']);
            $data = [
                'registration'     => (int) $last['registration'],
                'unit'             => $last['unit'] !== null ? (int) $last['unit'] : null,
                'unit_id'          => (string) $last['unit_id'],
                'cash_register_id' => (string) $last['cash_register_id'],
                'currency'         => (string) $last['currency'],
            ];
        }

        $serial = SerialNumber::of($doc->docNumber, $sequence);
        if ($type !== null) {
            $problem = $this->evaluator->saleProblem($type, $serial, $difference);
            if ($problem !== null) {
                return SyncResult::unregistrable($doc->id, $problem, (int) $data['registration']);
            }
        }

        $stamp = $now->format('Y-m-d H:i:s');
        $data = [
            'doc_head'      => $doc->id,
            'doc_sequence'  => $sequence,
        ] + $data + [
            'serial_number' => $serial,
            'sold_at'       => $soldAt->format('Y-m-d H:i:s'),
            'amount'        => Money::decimal($difference),
            'created'       => $stamp,
            'state'         => self::STATE_PENDING,
            'attempt_count' => 0,
            'next_attempt'  => $stamp,
        ];
        if ($dryRun) {
            return SyncResult::wouldCreate($doc->id, $data);
        }

        try {
            $saleId = $this->insertSale($data);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        return SyncResult::created($doc->id, $saleId, $data);
    }

    /**
     * Čas tržby (D20) = čas přechodu dokladu, v zóně serveru (v ní se
     * `doc_state_changed_at` ukládá). Doklad bez času (starší data) bere „teď“.
     */
    private function soldAt(DocFacts $doc, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if ($doc->stateChangedAt === null) {
            return $now;
        }
        try {
            return new \DateTimeImmutable($doc->stateChangedAt, new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable) {
            return $now;
        }
    }

    // ── Čtení a zápis (přepsatelné v testech) ───────────────────────────────

    protected function loadDoc(int $docId): ?DocFacts
    {
        return $this->loader->load($docId);
    }

    /** @return list<RegistrationFacts> */
    protected function registrations(): array
    {
        return $this->loader->registrations();
    }

    protected function hasSales(int $docId): bool
    {
        return $this->db->fetchSingle('SELECT 1 FROM %n WHERE [doc_head] = %i LIMIT 1', self::TABLE, $docId) !== null;
    }

    /**
     * Všechny tržby dokladu v pořadí vzniku.
     *
     * @return list<array<string, mixed>>
     */
    protected function salesOf(int $docId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [doc_sequence], [amount], [registration], [unit], [unit_id], [cash_register_id], [currency]'
            . ' FROM %n WHERE [doc_head] = %i ORDER BY [doc_sequence]',
            self::TABLE,
            $docId,
        );
        return array_map(static fn($row): array => iterator_to_array($row), $rows);
    }

    /** Typ registrace bez ohledu na její stav (vyrovnávací tržba po archivaci registrace). */
    protected function typeOfRegistration(int $registrationId): ?SalesRegistrationType
    {
        $typeId = $this->db->fetchSingle('SELECT [type] FROM %n WHERE [id] = %i', RegistrationDocument::TABLE, $registrationId);
        return is_string($typeId) ? SalesRegistrationTypes::fromConfig($this->config)->get($typeId) : null;
    }

    /** @param array<string, mixed> $data */
    protected function insertSale(array $data): int
    {
        return (int) NestedTransaction::run($this->db, function () use ($data): int {
            $this->db->query('INSERT INTO %n %v', self::TABLE, $data);
            return (int) $this->db->getInsertId();
        });
    }

    protected function lock(int $docId): bool
    {
        return (int) $this->db->fetchSingle(
            'SELECT GET_LOCK(CONCAT(DATABASE(), %s), %i)',
            self::lockKey($docId),
            $this->lockTimeout,
        ) === 1;
    }

    protected function unlock(int $docId): void
    {
        $this->db->query('SELECT RELEASE_LOCK(CONCAT(DATABASE(), %s))', self::lockKey($docId));
    }

    /** Zámek je per databáze (jméno databáze v klíči) — zdroje dat sdílejí server. */
    public static function lockKey(int $docId): string
    {
        return ':salesreg:doc:' . $docId;
    }
}
