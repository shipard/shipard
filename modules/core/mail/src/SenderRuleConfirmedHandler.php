<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\AbstractDocumentEventHandler;

/**
 * Potvrzení pravidla odesílatele odklidí čekající řádky Ostatní
 * (tasks/mail-sender-rules-after-analysis.md D8): při každém přechodu
 * pravidla `core_mail_sender_rules` do stavu 40 (karta návrhu na dashboardu
 * i uložení formuláře) zavolá {@see PostAnalysisDisposer::applyToWaiting()}.
 *
 * Běží po commitu (`stateChanged`), výjimky loguje a polyká dispatcher —
 * nikdy neblokuje potvrzení. Archivované zprávy nesou audit
 * `auto_disposed_*`, takže se ukážou v digestu a „Vrátit vše“ funguje.
 *
 * Ne-final kvůli testům — disposer se podstrkuje přes {@see disposer()}.
 */
class SenderRuleConfirmedHandler extends AbstractDocumentEventHandler
{
    /** docState potvrzeného pravidla (core.system.docStatesArchive). */
    private const RULE_STATE_CONFIRMED = 40;

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        if ($this->db === null || empty($data['id']) || $newState !== self::RULE_STATE_CONFIRMED) {
            return;
        }

        $this->disposer()->applyToWaiting((int) $data['id']);
    }

    protected function disposer(): PostAnalysisDisposer
    {
        return new PostAnalysisDisposer(new DataSourceConnection($this->db), $this->config);
    }
}
