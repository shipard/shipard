<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\SalesRegistration\Evaluation\ReasonTexts;
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
                message: ReasonTexts::of($this->config)->text((string) $result->reason) . ' ' . $t->t(
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
