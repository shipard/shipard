<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\ContactPayment;

use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Kontaktní platba při uložení dokladu (#171 U16, U21, U22) — handler
 * `beforeSave` na `docs_core_heads`. Sloupce edituje záložka formuláře
 * ({@see ContactPaymentFormTab}) v Konceptu a V opravě; tady se hlídá, co
 * záložka sama neumí:
 *
 *  - příznak jen u typů dokladů a způsobů úhrady podle dat typu evidence
 *    (U14) — i když se u dokladu s příznakem změní způsob úhrady;
 *  - čas platby ne v budoucnu (v zóně místa tržby, U23);
 *  - vypnutý příznak čas platby nedrží;
 *  - změna příznaku nebo času nastaví `contact_payment_changed_at` (U21).
 *    Při uložení, které zároveň mění stav dokladu, je to týž čas jako
 *    `doc_state_changed_at` — tržba pak vzniká přechodem, ne změnou příznaku.
 *
 * Příznak bez času je v pořádku — čas tržby je pak čas přechodu do V pořádku
 * (U16). Doklad, kterého se příznak netýká, handler nechává být a nic do něj
 * nezapisuje (ani výchozí hodnoty).
 *
 * Chybu k poli handler vrátit neumí; `\DomainException` dá formuláři 422
 * s hláškou nad poli (jako {@see \Shipard\Module\Economy\SalesRegistration\Series\SeriesUnitHandler}).
 */
class ContactPaymentSaveHandler extends AbstractDocumentEventHandler
{
    public const string TABLE = 'docs_core_heads';

    public const string FLAG = 'contact_payment';
    public const string PAID_AT = 'contact_payment_at';
    public const string CHANGED_AT = 'contact_payment_changed_at';

    public function onBeforeSave(string $tableId, array &$data, ?array $originalData): void
    {
        if ($tableId !== self::TABLE) {
            return;
        }
        $effective = static fn(string $key): mixed
            => array_key_exists($key, $data) ? $data[$key] : ($originalData[$key] ?? null);

        $oldFlag = !empty($originalData[self::FLAG] ?? null);
        $oldPaidAt = PaymentTime::normalize($originalData[self::PAID_AT] ?? null);
        $flag = !empty($effective(self::FLAG));
        $rawPaidAt = $effective(self::PAID_AT);
        $paidAt = PaymentTime::normalize($rawPaidAt);

        if (!$flag && !$oldFlag && $paidAt === null && $oldPaidAt === null && self::isBlank($rawPaidAt)) {
            // Doklad bez příznaku — většina uložení; čas se sjednotí na null,
            // jen když ho uložení samo posílá.
            if (array_key_exists(self::PAID_AT, $data)) {
                $data[self::PAID_AT] = null;
            }
            return;
        }

        $t = Texts::of($this->config);
        if ($flag && $paidAt === null && !self::isBlank($rawPaidAt)) {
            throw new \DomainException($t->t('contactPayment.errorTime', 'The payment time is not a valid date and time.'));
        }
        if (!$flag) {
            // Vypnutý příznak čas platby nedrží.
            $paidAt = null;
        }

        $docType = (string) ($effective('doc_type') ?? '');
        $paymentMethod = (int) ($effective('payment_method') ?? -1);
        $changed = $flag !== $oldFlag || $paidAt !== $oldPaidAt;
        $headChanged = $originalData === null
            || (string) ($originalData['doc_type'] ?? '') !== $docType
            || (int) ($originalData['payment_method'] ?? -1) !== $paymentMethod;

        if ($flag && ($changed || $headChanged)) {
            $policy = $this->policy();
            if (!$policy->hasRegistration()) {
                throw new \DomainException($t->t(
                    'contactPayment.errorNoRegistration',
                    'Contact payment cannot be set — there is no confirmed sales registration.',
                ));
            }
            if (!$policy->allows($docType, $paymentMethod)) {
                throw new \DomainException($t->t(
                    'contactPayment.errorNotAllowed',
                    'Contact payment cannot be set for this document and payment method. It is meant for an invoice paid by bank transfer that the customer paid on the spot; a document paid in cash or by card is registered automatically, and payment gateway, set-off and cash on delivery are not contact payments.',
                ));
            }
            if ($paidAt !== null && $paidAt !== $oldPaidAt) {
                $zone = $policy->saleTimeZone($docType, $paymentMethod);
                if (PaymentTime::isFuture($paidAt, $zone, $this->now())) {
                    throw new \DomainException($t->t('contactPayment.errorFuture', 'The payment time must not be in the future.'));
                }
                $paidAt = PaymentTime::notAfterNow($paidAt, $zone, $this->now());
                $changed = $flag !== $oldFlag || $paidAt !== $oldPaidAt;
            }
        }

        if (array_key_exists(self::PAID_AT, $data) || $paidAt !== $oldPaidAt) {
            $data[self::PAID_AT] = $paidAt;
        }
        if ($changed) {
            $data[self::CHANGED_AT] = $this->changeStamp($data, $originalData);
        }
    }

    /**
     * Čas změny příznaku: při uložení, které zároveň mění stav dokladu, čas
     * tohoto přechodu (`DocDocument::beforeSave()` běží před handlery
     * a `doc_state_changed_at` už nastavil), jinak „teď“.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $originalData
     */
    private function changeStamp(array $data, ?array $originalData): string
    {
        $stateChanged = $originalData === null
            || (array_key_exists('docState', $data) && (int) $data['docState'] !== (int) ($originalData['docState'] ?? 0));
        $transition = $stateChanged ? PaymentTime::normalize($data['doc_state_changed_at'] ?? null) : null;
        return $transition ?? $this->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(PaymentTime::FORMAT);
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** Politika U14 nad registracemi V pořádku (přepsatelné v testech). */
    protected function policy(): ContactPaymentPolicy
    {
        if ($this->db === null) {
            return new ContactPaymentPolicy([]);
        }
        return ContactPaymentPolicy::fromLoader(new DocFactsLoader($this->db, $this->config, $this->dsConfig));
    }

    /** Přepsatelné v testech. */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
