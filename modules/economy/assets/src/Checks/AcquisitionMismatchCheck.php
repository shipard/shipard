<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\Assets\AssetJournalCheck;
use Shipard\Module\Economy\Assets\Depreciation\Amounts;

/**
 * Pořízení majetku nesouhlasí se zařazením (docs/assets.md D67, nález
 * fáze 4): pořízení na účtu pořízení (04x) s kartou ≠ potvrzená zařazení
 * + technická zhodnocení − snížení, a rozdíl trvá déle než
 * `AssetJournalCheck::ACQUISITION_DAYS` dní. Typicky doplatek pořízení po
 * zařazení, který čeká na technické zhodnocení, nebo zařazení v jiné
 * částce než pořízení.
 *
 * Patří sem i pořízení bez zařazení po lhůtě — do ní ho hlásí informační
 * alert „Majetek čeká na zařazení“ (`AwaitingActivationCheck`), který pak
 * kartu pouští sem. Jeden nález per karta; zmizí vyrovnáním rozdílu.
 */
class AcquisitionMismatchCheck extends AlertCheck
{
    /** Stable tableId of economy_assets_assets. */
    private const SUBJECT_TABLE_ID = 450;

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $days = AssetJournalCheck::ACQUISITION_DAYS;
        $findings = [];
        foreach ($this->mismatches() as $mismatch) {
            $id = (int) $mismatch['assetId'];
            $label = trim($mismatch['number'] . ' ' . $mismatch['name']) ?: '#' . $id;
            $acquired = Amounts::money((float) $mismatch['acquired']);
            $activated = Amounts::money((float) $mismatch['activated']);
            $difference = Amounts::money((float) $mismatch['difference']);

            if ($mismatch['started']) {
                $title = $isCs
                    ? "Majetek {$label}: pořízení nesouhlasí se zařazením"
                    : "Asset {$label}: acquisition does not match activation";
                $message = $isCs
                    ? "Na účtu pořízení je s kartou {$acquired}, zařazení a technická zhodnocení dávají {$activated} —"
                        . " rozdíl {$difference} trvá déle než {$days} dní. Doplatek pořízení po zařazení zařaď jako"
                        . ' technické zhodnocení, chybně zadanou částku oprav v události zařazení.'
                    : "The acquisition account holds {$acquired} for the card, activation and improvements give {$activated} —"
                        . " the difference of {$difference} has lasted more than {$days} days.";
            } else {
                $title = $isCs
                    ? "Majetek {$label}: pořízení čeká na zařazení déle než {$days} dní"
                    : "Asset {$label}: acquisition has waited for activation for more than {$days} days";
                $message = $isCs
                    ? "Karta má na účtu pořízení {$acquired}, ale není zařazená — majetek se neodepisuje. Na kartě zvol"
                        . ' Zařadit; částka a datum se předvyplní z pořízení.'
                    : "The card has {$acquired} on the acquisition account but is not activated — it is not depreciated.";
            }

            $findings[] = new AlertFinding(
                findingKey: (string) $id,
                title: $title,
                message: $message,
                severity: 'warning',
                subjectTableId: self::SUBJECT_TABLE_ID,
                subjectRowId: $id,
                actions: [[
                    'id'      => 'open_asset',
                    'label'   => $isCs ? 'Otevřít kartu' : 'Open asset card',
                    'kind'    => 'open_viewer',
                    'primary' => true,
                    'target'  => ['viewerId' => 'economy.assets.assets', 'recordId' => $id],
                ]],
                context: [
                    'asset_number' => $mismatch['number'],
                    'acquired'     => (float) $mismatch['acquired'],
                    'activated'    => (float) $mismatch['activated'],
                    'since'        => $mismatch['since'],
                ],
            );
        }
        return $findings;
    }

    /**
     * Nesoulady (b) starší než lhůta.
     *
     * @return list<array{assetId: int, number: string, name: string, acquired: float, activated: float,
     *     difference: float, started: bool, since: ?string}>
     */
    protected function mismatches(): array
    {
        $today = $this->today();

        return array_values(array_filter(
            $this->check()->cardFindings()['acquisition'],
            static fn(array $finding): bool => AssetJournalCheck::isOverdue($finding['since'], $today),
        ));
    }

    /** Seam pro testy. */
    protected function check(): AssetJournalCheck
    {
        return new AssetJournalCheck($this->db->getDibiConnection());
    }

    /** Seam pro testy. */
    protected function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
