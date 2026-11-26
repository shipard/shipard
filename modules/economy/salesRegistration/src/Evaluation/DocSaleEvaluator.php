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
 * Kontaktní platba (fáze 3, D8b) přidá další zdroj v kroku 3–4: doklad,
 * který pravidlo typu nemá, ale nese příznak platby na místě, půjde celou
 * částkou — viz {@see self::matchingRegistrations()}.
 */
final class DocSaleEvaluator
{
    /**
     * @param list<RegistrationFacts> $registrations registrace ve stavu V pořádku
     * @param \DateTimeInterface      $soldAt        čas tržby (čas přechodu dokladu)
     */
    public function evaluate(DocFacts $doc, array $registrations, \DateTimeInterface $soldAt): DocEvaluation
    {
        if ($doc->isImported()) {
            return DocEvaluation::notApplicable(DocEvaluation::REASON_IMPORTED);
        }
        if ($doc->docState === DocFacts::STATE_CANCELLED) {
            return DocEvaluation::notApplicable(DocEvaluation::REASON_CANCELLED);
        }
        if ($doc->docState !== DocFacts::STATE_CONFIRMED) {
            return DocEvaluation::notApplicable(DocEvaluation::REASON_STATE);
        }

        [$matching, $reason] = $this->matchingRegistrations($doc, $registrations, $soldAt);
        if ($matching === []) {
            return DocEvaluation::notApplicable($reason);
        }
        if (count($matching) > 1) {
            return DocEvaluation::unregistrable(DocEvaluation::REASON_MULTIPLE_REGISTRATIONS);
        }
        $registration = $matching[0];
        $type = $registration->type;

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

        return DocEvaluation::applicable($cents, $registration, $unit, $unitId, $cashRegisterId);
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
     * Místo pro Kontaktní platbu (fáze 3, D8b): příznak na hlavičce nahradí
     * podmínku „typ má pro hlavičku pravidlo“ a D8d se pro akci na dokladu
     * V pořádku neuplatní (nová platba v době evidence).
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
            $soldOn = \DateTimeImmutable::createFromInterface($soldAt)->setTimezone($type->saleTimeZone())->format('Y-m-d');
            if (!$registration->isValidOn($soldOn)) {
                continue;
            }
            if ($type->ruleFor($doc->docType, $doc->paymentMethod, $doc->cashDir) === null) {
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

    /** Částka v haléřích, která do evidence spadá; null = pravidlu nad řádky nevyhovuje žádný řádek. */
    private function amount(DocFacts $doc, SalesRegistrationType $type): ?int
    {
        $rule = $type->ruleFor($doc->docType, $doc->paymentMethod, $doc->cashDir);
        if ($rule === null) {
            return null;
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
