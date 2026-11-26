<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\ContactPayment;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Queue\AttemptResult;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\Sales\ImmediateSend;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;

/**
 * Kontaktní platba na dokladu V pořádku (#171 D8b, U17, U21) — co dělají akce
 * **Kontaktní platba** a **Zrušit kontaktní platbu**.
 *
 * Doklad zůstává V pořádku: tři sloupce se zapíšou přímo, bez přechodu stavu,
 * bez hooků jádra a bez ohledu na zámek dokladu nebo podaného období DPH —
 * obsah dokladu ani jeho zaúčtování se nemění, příznak jen říká, jak se
 * platilo. Zápis je podmíněný stavem dokladu a dosavadní hodnotou příznaku,
 * takže dvojí kliknutí ani souběh s přechodem dokladu druhou tržbu nezaloží.
 *
 * Tržba pak vzniká stejným rozdílovým výpočtem jako u přechodu dokladu
 * ({@see SaleSync}) a hned se zkusí odeslat ({@see ImmediateSend}, jistič
 * U6). Spadne-li cokoli po zápisu příznaku, tržbu dorovná záchranná síť —
 * okno má i podle `contact_payment_changed_at` a čas tržby vyjde stejně.
 */
class ContactPaymentService
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocFactsLoader $loader,
        private readonly SaleSync $sync,
        private readonly ?SaleQueue $queue = null,
    ) {
    }

    public static function create(
        Connection $db,
        ?ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        SalesRegistrationServerConfig $safety,
    ): self {
        $loader = new DocFactsLoader($db, $config, $dsConfig);
        return new self($db, $loader, new SaleSync($db, $loader, $config), SaleQueue::create($db, $config, $dsConfig, $safety));
    }

    /**
     * Nastaví příznak s časem platby, založí tržbu a zkusí ji odeslat.
     *
     * @param string $paidAt čas platby, jak ho uživatel zadal (zóna místa tržby, U23)
     */
    public function set(int $docId, string $paidAt, ?\DateTimeImmutable $now = null): ContactPaymentOutcome
    {
        $now = $this->serverNow($now);
        $doc = $this->loadDoc($docId);
        if ($doc === null || $doc->docState !== DocFacts::STATE_CONFIRMED || $doc->contactPayment || $doc->isImported()) {
            return ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_CONFLICT);
        }
        $policy = $this->policy();
        if (!$policy->allows($doc->docType, $doc->paymentMethod)) {
            return ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_NOT_ALLOWED);
        }

        $zone = $policy->saleTimeZone($doc->docType, $doc->paymentMethod);
        $normalized = PaymentTime::normalize($paidAt);
        if ($normalized === null) {
            return ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_TIME);
        }
        if (PaymentTime::isFuture($normalized, $zone, $now)) {
            return ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_FUTURE);
        }
        $normalized = PaymentTime::notAfterNow($normalized, $zone, $now);

        // Nanečisto, než se cokoli zapíše: doklad, který by ani s příznakem
        // nespadl (platba mimo platnost registrace, nulová částka), příznak
        // nedostane. „Nejde založit“ naopak zapíšeme — chybí jen nastavení
        // a tržbu po jeho opravě dorovná záchranná síť.
        $evaluation = $this->sync->evaluation($doc->withContactPayment($normalized), $now);
        if (!$evaluation->applicable && !$evaluation->unregistrable) {
            return ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_NOT_APPLICABLE, $evaluation->reason);
        }

        if (!$this->write($docId, true, $normalized, $now->format(PaymentTime::FORMAT))) {
            return ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_CONFLICT);
        }
        return $this->settle($docId, $now);
    }

    /** Zruší příznak; potvrzenou tržbu vyrovná záporná tržba s časem zrušení. */
    public function cancel(int $docId, ?\DateTimeImmutable $now = null): ContactPaymentOutcome
    {
        $now = $this->serverNow($now);
        $doc = $this->loadDoc($docId);
        if ($doc === null || $doc->docState !== DocFacts::STATE_CONFIRMED || !$doc->contactPayment) {
            return ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_CONFLICT);
        }
        if (!$this->write($docId, false, null, $now->format(PaymentTime::FORMAT))) {
            return ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_CONFLICT);
        }
        return $this->settle($docId, $now);
    }

    /** Rozdílový výpočet a okamžitý pokus — každý se svým `try`, jako v handleru dokladů. */
    private function settle(int $docId, \DateTimeImmutable $now): ContactPaymentOutcome
    {
        try {
            $result = $this->sync->sync($docId, $now);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'sales registration: sale of document ' . $docId . ' not created after contact payment change');
            return ContactPaymentOutcome::written(null);
        }
        if ($result->status !== SyncResult::NONE) {
            ErrorLogger::info('sales registration: contact payment ' . $result->status, [
                'doc'          => $docId,
                'sale'         => $result->saleId,
                'registration' => $result->registration,
                'reason'       => $result->reason,
                'serialNumber' => $result->sale['serial_number'] ?? null,
                'timeBasis'    => $result->timeBasis,
            ]);
        }
        if (!$result->isCreated() || $this->queue === null) {
            return ContactPaymentOutcome::written($result);
        }

        try {
            $attempt = (new ImmediateSend($this->queue))->attempt($result);
        } catch (\Throwable $e) {
            ErrorLogger::warn(
                'sales registration: sale ' . $result->saleId . ' of document ' . $docId
                . ' not sent right away — left to the queue',
                [
                    'sale'         => $result->saleId,
                    'doc'          => $docId,
                    'registration' => $result->registration,
                    'exception'    => get_class($e),
                    'error'        => $e->getMessage(),
                ],
            );
            $attempt = null;
        }
        return ContactPaymentOutcome::written($result, $attempt);
    }

    /** „Teď“ v zóně serveru — v ní se ukládá čas změny příznaku i čas vzniku tržby. */
    private function serverNow(?\DateTimeImmutable $now): \DateTimeImmutable
    {
        return ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    // ── Čtení a zápis (přepsatelné v testech) ───────────────────────────────

    protected function loadDoc(int $docId): ?DocFacts
    {
        return $this->loader->load($docId);
    }

    protected function policy(): ContactPaymentPolicy
    {
        return ContactPaymentPolicy::fromLoader($this->loader);
    }

    /**
     * Podmíněný zápis tří sloupců; false = doklad mezitím změnil stav nebo
     * příznak (nezapsalo se nic).
     */
    protected function write(int $docId, bool $flag, ?string $paidAt, string $stamp): bool
    {
        $this->db->query(
            'UPDATE %n SET %a WHERE [id] = %i AND [docState] = %i AND [contact_payment] = %i',
            DocFactsLoader::HEADS,
            [
                ContactPaymentSaveHandler::FLAG       => $flag ? 1 : 0,
                ContactPaymentSaveHandler::PAID_AT    => $paidAt,
                ContactPaymentSaveHandler::CHANGED_AT => $stamp,
            ],
            $docId,
            DocFacts::STATE_CONFIRMED,
            $flag ? 0 : 1,
        );
        return $this->db->getAffectedRows() > 0;
    }
}
