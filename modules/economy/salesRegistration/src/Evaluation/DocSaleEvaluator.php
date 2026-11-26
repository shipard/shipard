<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Evaluation;

use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;

/**
 * Spadá doklad do evidence tržeb a jakou částkou? (#171 D8a, D8d, D8e, D18,
 * D22, U9, U11) — čistá služba bez sítě, bez databáze a bez zápisu. Stejné
 * vyhodnocení používá handler přechodu dokladu, záchranná síť i upozornění.
 *
 * Pořadí rozhodování:
 *
 *  1. doklad převzatý importem → nespadá nikdy (D8e, U11);
 *  2. jen stavy V pořádku a Storno; Storno = částka 0 (D6);
 *  3. registrace podle země registrace DPH dokladu a platnosti k času tržby
 *     (D22), jejíž typ má pro hlavičku pravidlo (D8a, D23) a která
 *     nezačala až po vystavení dokladu (D8d); žádná = nespadá, víc = nejde
 *     založit;
 *  4. částka (D18): pravidlo nad hlavičkou bere částku dokladu v domácí
 *     měně (obsahuje zaokrouhlení), pravidlo nad řádky součet vyhovujících
 *     řádků — a když vyhovují všechny řádky s nenulovou částkou, zase
 *     částku dokladu, aby šla hotovost zaokrouhleně;
 *  5. jednotka z číselné řady, jinak výchozí jednotka registrace; označení
 *     pokladny = kód řady; obojí přes masky typu.
 *
 * Kontaktní platba (D8b, U14, U15) je druhý zdroj v kroku 3–4: doklad,
 * který pravidlo typu nemá, ale nese příznak platby na místě a data typu mu
 * ho dovolují (`contactPayment`), jde celou částkou dokladu. Platnost
 * registrace se pak posuzuje k **času platby** a datum vystavení dokladu
 * nerozhoduje — platba je nová skutečnost v době evidence, i u dokladu
 * vystaveného dřív. Pravidlo má přednost: doklad, který spadá pravidlem,
 * příznak ignoruje.
 */
final class DocSaleEvaluator
{
    /**
     * @param list<RegistrationFacts> $registrations      registrace ve stavu V pořádku
     * @param \DateTimeInterface      $soldAt             čas přechodu dokladu (čas tržby podle D20);
     *        kontaktní platba bere svůj čas platby z dokladu a tenhle jen bez něj (U16)
     * @param int|null                $pinnedRegistration registrace dosavadních tržeb dokladu: doklad,
     *        který už tržbu má, zůstává u své registrace — platnost k času tržby ani D8d se znovu
     *        neposuzují (oprava dokladu po skončení registrace by jinak poslala storno platné tržby)
     */
    public function evaluate(
        DocFacts $doc,
        array $registrations,
        \DateTimeInterface $soldAt,
        ?int $pinnedRegistration = null,
    ): DocEvaluation {
        if ($doc->isImported()) {
            return DocEvaluation::notApplicable(DocEvaluation::REASON_IMPORTED);
        }
        if ($doc->docState === DocFacts::STATE_CANCELLED) {
            return DocEvaluation::notApplicable(DocEvaluation::REASON_CANCELLED);
        }
        if ($doc->docState !== DocFacts::STATE_CONFIRMED) {
            return DocEvaluation::notApplicable(DocEvaluation::REASON_STATE);
        }

        if ($pinnedRegistration !== null) {
            $pinned = array_values(array_filter(
                $registrations,
                static fn(RegistrationFacts $registration): bool => $registration->id === $pinnedRegistration,
            ));
            if ($pinned === []) {
                // Registrace dosavadních tržeb už není V pořádku — rozdíl nejde spočítat.
                return DocEvaluation::unregistrable(DocEvaluation::REASON_REGISTRATION_GONE);
            }
            $matching = $this->source($doc, $pinned[0]->type) !== null ? $pinned : [];
            $reason = DocEvaluation::REASON_NO_RULE;
        } else {
            [$matching, $reason] = $this->matchingRegistrations($doc, $registrations, $soldAt);
        }
        if ($matching === []) {
            return DocEvaluation::notApplicable($reason);
        }
        if (count($matching) > 1) {
            return DocEvaluation::unregistrable(DocEvaluation::REASON_MULTIPLE_REGISTRATIONS);
        }
        $registration = $matching[0];
        $type = $registration->type;

        $source = (string) $this->source($doc, $type);
        $cents = $this->amount($doc, $type);
        if ($cents === null) {
            return DocEvaluation::notApplicable(DocEvaluation::REASON_NO_MATCHING_ROWS);
        }
        if ($cents === 0) {
            return DocEvaluation::notApplicable(DocEvaluation::REASON_ZERO_AMOUNT);
        }

        // Částka dokladu v domácí měně jde do zprávy jako částka v měně typu.
        if ($doc->homeCurrency !== $type->currency) {
            return DocEvaluation::unregistrable(DocEvaluation::REASON_CURRENCY, $registration);
        }

        $unit = $doc->seriesUnit ?? $registration->defaultUnit;
        if ($unit === null) {
            return DocEvaluation::unregistrable(DocEvaluation::REASON_NO_UNIT, $registration);
        }
        $unitId = $registration->units[$unit] ?? null;
        if ($unitId === null) {
            // Jednotka na řadě patří jiné registraci (nebo už neexistuje).
            return DocEvaluation::unregistrable(DocEvaluation::REASON_FOREIGN_UNIT, $registration);
        }
        if (!$type->isValidUnitId($unitId)) {
            return DocEvaluation::unregistrable(DocEvaluation::REASON_UNIT_ID, $registration);
        }

        $cashRegisterId = (string) $doc->seriesCode;
        if ($cashRegisterId === '' || !$type->isValidCashRegisterId($cashRegisterId)) {
            return DocEvaluation::unregistrable(DocEvaluation::REASON_CASH_REGISTER_ID, $registration);
        }

        return DocEvaluation::applicable($cents, $registration, $unit, $unitId, $cashRegisterId, $source);
    }

    /**
     * Jde tržbu s tímto pořadovým číslem a částkou poslat? Kontroluje se až
     * nad konkrétní tržbou — pořadové číslo závisí na pořadí tržby dokladu
     * a částka je rozdíl, ne částka dokladu. Vrací důvod (U9), nebo null.
     */
    public function saleProblem(SalesRegistrationType $type, string $serialNumber, int $cents): ?string
    {
        if ($serialNumber === '' || !$type->isValidSerialNumber($serialNumber)) {
            return DocEvaluation::REASON_SERIAL_NUMBER;
        }
        if (!$type->isValidAmount(Money::decimal($cents))) {
            return DocEvaluation::REASON_AMOUNT;
        }
        return null;
    }

    /**
     * Registrace, pod které doklad spadá, a důvod, proč žádná.
     *
     * Kontaktní platba (D8b, U15): příznak na hlavičce nahrazuje podmínku
     * „typ má pro hlavičku pravidlo“; platnost registrace se bere k času
     * platby a D8d podle data vystavení se neuplatní.
     *
     * @param list<RegistrationFacts> $registrations
     * @return array{0: list<RegistrationFacts>, 1: string}
     */
    private function matchingRegistrations(DocFacts $doc, array $registrations, \DateTimeInterface $soldAt): array
    {
        $reason = DocEvaluation::REASON_NO_REGISTRATION;
        $matching = [];
        foreach ($registrations as $registration) {
            $type = $registration->type;
            if ($type->country !== $doc->country) {
                continue;
            }
            $source = $this->source($doc, $type);
            $soldOn = \DateTimeImmutable::createFromInterface($soldAt)->setTimezone($type->saleTimeZone())->format('Y-m-d');
            if ($source === DocEvaluation::SOURCE_CONTACT_PAYMENT) {
                // U15: rozhoduje čas platby (už v zóně místa tržby, U23);
                // bez něj čas přechodu dokladu (U16).
                $paidOn = $doc->contactPaymentAt !== null ? substr($doc->contactPaymentAt, 0, 10) : $soldOn;
                if (!$registration->isValidOn($paidOn)) {
                    $reason = DocEvaluation::REASON_PAYMENT_OUTSIDE_REGISTRATION;
                    continue;
                }
                $matching[] = $registration;
                continue;
            }
            if (!$registration->isValidOn($soldOn)) {
                continue;
            }
            if ($source === null) {
                $reason = DocEvaluation::REASON_NO_RULE;
                continue;
            }
            // D8d: doklad vystavený před začátkem platnosti registrace do
            // evidence nevstoupí ani pozdější opravou nebo stornem.
            if ($registration->validFrom !== null && $doc->issueDate < $registration->validFrom) {
                $reason = DocEvaluation::REASON_BEFORE_REGISTRATION;
                continue;
            }
            $matching[] = $registration;
        }
        return [$matching, $reason];
    }

    /**
     * Čím doklad pod typ spadá: pravidlem (má přednost), kontaktní platbou
     * (příznak + data typu, U14), nebo ničím (null).
     */
    private function source(DocFacts $doc, SalesRegistrationType $type): ?string
    {
        if ($type->ruleFor($doc->docType, $doc->paymentMethod, $doc->cashDir) !== null) {
            return DocEvaluation::SOURCE_RULE;
        }
        if ($doc->contactPayment && $type->allowsContactPayment($doc->docType, $doc->paymentMethod, $doc->cashDir)) {
            return DocEvaluation::SOURCE_CONTACT_PAYMENT;
        }
        return null;
    }

    /** Částka v haléřích, která do evidence spadá; null = pravidlu nad řádky nevyhovuje žádný řádek. */
    private function amount(DocFacts $doc, SalesRegistrationType $type): ?int
    {
        $rule = $type->ruleFor($doc->docType, $doc->paymentMethod, $doc->cashDir);
        if ($rule === null) {
            // Kontaktní platba — vždy celá částka dokladu (D8b); u faktury
            // s odpočtem zálohy je to částka k úhradě.
            return $this->source($doc, $type) === DocEvaluation::SOURCE_CONTACT_PAYMENT ? $doc->totalCents : null;
        }
        if (!$rule->isRowRule()) {
            return $doc->totalCents;
        }

        $sum = 0;
        $matched = false;
        $allMatch = true;
        foreach ($doc->rows as $row) {
            if ($row['cents'] === 0) {
                continue;
            }
            if ($rule->matchesOperation($row['operation'])) {
                $sum += $row['cents'];
                $matched = true;
            } else {
                $allMatch = false;
            }
        }
        if (!$matched) {
            return null;
        }
        // Celý doklad je tržba → částka dokladu včetně zaokrouhlení (D18).
        return $allMatch ? $doc->totalCents : $sum;
    }
}
