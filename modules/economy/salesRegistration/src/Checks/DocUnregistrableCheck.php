<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Sales\ReconcileScanner;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Upozornění `economy.salesregistration.doc_unregistrable` (#171 U9):
 * doklad do evidence tržeb spadá, ale tržbu založit nejde — chybí evidenční
 * jednotka, kód číselné řady neprojde jako označení pokladny, dokladu
 * odpovídá víc registrací, pořadové číslo nebo částka jsou mimo meze.
 *
 * Stejné vyhodnocení jako záchranná síť (průchod nanečisto, okno 7 dní):
 * po opravě nastavení tržbu dorovná síť s původním časem a nález zmizí.
 * Jeden nález na doklad.
 */
class DocUnregistrableCheck extends AlertCheck
{
    public const int DOCS_TABLE_ID = 401;
    public const string DOCS_TABLE = 'docs_core_heads';

    /** Víc nálezů naráz nemá smysl — příčina bývá jedna (nastavení). */
    public const int MAX_FINDINGS = 30;

    public function run(): array
    {
        $t = Texts::of($this->config);
        $findings = [];
        foreach ($this->unregistrable() as $result) {
            if (count($findings) >= self::MAX_FINDINGS) {
                break;
            }
            $docId = $result->docId;
            $number = $this->docNumber($docId);
            $findings[] = new AlertFinding(
                findingKey: (string) $docId,
                title: $t->t('alert.unregistrableTitle', 'Document {number} cannot be registered as a sale', ['number' => $number]),
                message: $this->reasonText((string) $result->reason, $t) . ' ' . $t->t(
                    'alert.unregistrableFix',
                    'Once the settings are fixed, the sale is created automatically with the original time.',
                ),
                severity: 'error',
                subjectTableId: self::DOCS_TABLE_ID,
                subjectRowId: $docId,
                actions: [[
                    'id'      => 'open_document',
                    'label'   => $t->t('alert.openDocument', 'Open document'),
                    'kind'    => 'open_form',
                    'primary' => true,
                    'target'  => ['table' => self::DOCS_TABLE, 'mode' => 'edit', 'id' => $docId],
                ]],
                context: ['doc' => $docId, 'reason' => $result->reason, 'registration' => $result->registration],
            );
        }
        return $findings;
    }

    private function reasonText(string $reason, Texts $t): string
    {
        return match ($reason) {
            DocEvaluation::REASON_MULTIPLE_REGISTRATIONS => $t->t('alert.reasonMultipleRegistrations', 'More than one sales registration is valid for the document — only one may be valid at a time for a country.'),
            DocEvaluation::REASON_REGISTRATION_GONE => $t->t('alert.reasonRegistrationGone', 'The registration under which the document was registered is no longer confirmed.'),
            DocEvaluation::REASON_NO_UNIT => $t->t('alert.reasonNoUnit', 'Neither the number series of the document nor the registration has a registration unit — set the default unit of the registration or a unit on the number series.'),
            DocEvaluation::REASON_FOREIGN_UNIT => $t->t('alert.reasonForeignUnit', 'The registration unit set on the number series belongs to a different registration.'),
            DocEvaluation::REASON_UNIT_ID => $t->t('alert.reasonUnitId', 'The number of the registration unit does not have the form the registration type prescribes.'),
            DocEvaluation::REASON_CASH_REGISTER_ID => $t->t('alert.reasonCashRegisterId', 'The code of the number series cannot be used as a cash register identifier — change the code of the series.'),
            DocEvaluation::REASON_SERIAL_NUMBER => $t->t('alert.reasonSerialNumber', 'The document number is too long or contains characters that the serial number of a sale does not allow.'),
            DocEvaluation::REASON_AMOUNT => $t->t('alert.reasonAmount', 'The amount is outside the range the sales registration service accepts.'),
            DocEvaluation::REASON_CURRENCY => $t->t('alert.reasonCurrency', 'The home currency of the document differs from the currency of the registration type.'),
            default => $t->t('alert.reasonOther', 'The sale cannot be created ({reason}).', ['reason' => $reason]),
        };
    }

    /**
     * Doklady, které spadají, ale tržbu založit nejde (přepsatelné v testech).
     *
     * @return list<SyncResult>
     */
    protected function unregistrable(): array
    {
        $scanner = ReconcileScanner::create($this->db->getDibiConnection(), $this->config, $this->dsConfig);
        return array_values(array_filter(
            $scanner->run(ReconcileScanner::DEFAULT_DAYS, null, true),
            static fn(SyncResult $result): bool => $result->status === SyncResult::UNREGISTRABLE,
        ));
    }

    /** Číslo dokladu pro titulek (přepsatelné v testech). */
    protected function docNumber(int $docId): string
    {
        $number = $this->db->fetchSingle('SELECT `doc_number` FROM `' . self::DOCS_TABLE . '` WHERE `id` = %i', $docId);
        return is_string($number) && trim($number) !== '' ? trim($number) : '#' . $docId;
    }
}
