<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunner;

/**
 * Viewer došlých zpráv (core_mail_incoming_messages).
 *
 * Layout řádku podle spec §5.1:
 *   t1 — titulek: subject, u generického / prázdného předmětu a ručních
 *        zpráv ai_title (IncomingMessageTitle, D3)
 *   i1 — received_at relativní („před 2 h", „včera 14:32", „12. 3.")
 *   t2 — partner dokumentu (Osoba ?? partner_name), fallback odesílatel
 *        (tasks/mail-message-title-partner.md D7)
 *   i2 — badge primárního typu (barva dle cfgItem)
 *   t3 — [mailbox.name] + „od: odesílatel" (jen když t2 nese partnera)
 *        + první řádek body_plain (preview)
 *
 * Detail panel (§5.3): hlavička (předmět · partner · od: odesílatel ·
 * schránka · doručeno
 * + badges stavu a primárního typu) nad taby, taby Obsah (tělo, přílohy,
 * technické údaje) / Analýzy / Extrahované dokumenty / Originál.
 */
class IncomingMessagesViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.mail.docStatesIncoming';

    /** Mapování docState → span class (tamtéž jako v PersonsViewer). */
    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'warning',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    /** Vzory generických předmětů z cfgItem — memoizované per instance. */
    private ?array $genericPatterns = null;

    /** Barevné hinty pro badge primárního typu — klíč = cfgItem key. */
    private const PRIMARY_TYPE_SPAN_CLASS = [
        'invoiceReceived' => 'primary',
        'other'           => 'muted',
        'creditNote'      => 'warning',
        'order'           => 'success',
        'quotation'       => 'primary',
        'statement'       => 'muted',
        'complaint'       => 'danger',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        // LEFT JOIN Osoby — smazaná/archivovaná Osoba nesmí řádek vyfiltrovat
        // (t2 pak padá na partner_name, P9).
        $sql = 'SELECT m.`id`, m.`message_id`, m.`subject`, m.`ai_title`, m.`source_type`,'
            . ' m.`sender_email`, m.`sender_name`,'
            . ' m.`primary_type`, m.`received_at`, m.`body_plain`, m.`docState`, m.`docStateMain`,'
            . ' m.`analysis_state`, m.`is_bulk`, m.`partner_person`, m.`partner_name`,'
            . ' p.`full_name` AS partner_full_name,'
            . ' m.`mailbox`, mb.`name` AS mailbox_name, mb.`mailbox_id` AS mailbox_code'
            . ' FROM `' . $this->table . '` m'
            . ' LEFT JOIN `core_mail_mailboxes` mb ON mb.`id` = m.`mailbox`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = m.`partner_person`';

        $conditions = [];
        $params     = [];

        // viewGroup filter (Active / Archive / Trash)
        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all' && $this->docStatesCfgItem !== null && $this->config !== null) {
            $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
            $states = $cfg->getViewGroupStates($viewGroup);
            if ($states !== []) {
                $placeholders = implode(', ', array_fill(0, count($states), '%i'));
                $conditions[] = 'm.`docState` IN (' . $placeholders . ')';
                $params = array_merge($params, $states);
            } elseif ($viewGroup !== 'active') {
                // Prázdná skupina → vrátíme 0 řádků (nikoli všechno)
                $conditions[] = '1=0';
            }
        }

        // Fulltext search — subject i ai_title (vždy oba, D3), sender_email,
        // sender_name, partner (snapshot z canonicalu i jméno Osoby), body_plain
        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains([
                'm.`subject`', 'm.`ai_title`', 'm.`sender_email`', 'm.`sender_name`', 'm.`partner_name`',
                'p.`full_name`', 'm.`body_plain`',
            ], $search);
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        // Řazení: docStateMain (Nová nahoře), pak chronologicky newest-first
        $sql .= ' ORDER BY m.`docStateMain` ASC, m.`received_at` DESC, m.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState  = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => $this->displayTitle($rowData),
            'i1'         => $this->formatRelativeDate($rowData['received_at'] ?? null),
            'stateStyle' => $stateStyle,
        ];

        // t2: partner dokumentu (Osoba, jinak jméno z canonicalu), fallback
        // odesílatel (sender_name, jinak sender_email) — D7.
        $senderName = trim((string) ($rowData['sender_name'] ?? ''));
        $senderEmail = trim((string) ($rowData['sender_email'] ?? ''));
        $sender = $senderName !== '' ? $senderName : $senderEmail;
        $partner = $this->partnerLabel($rowData);
        $row['t2'] = $partner !== '' ? $partner : ($sender !== '' ? $sender : null);

        // i2: primární typ (badge s lokalizovaným jménem a barvou dle typu)
        // + badge stavu AI analýzy (hodnota 0 = Bez analýzy se nezobrazuje)
        $primaryType = (string) ($rowData['primary_type'] ?? 'other');
        $typeLabel = $this->resolvePrimaryTypeLabel($primaryType);
        $i2 = [[
            'text'  => $typeLabel,
            'class' => self::PRIMARY_TYPE_SPAN_CLASS[$primaryType] ?? 'muted',
        ]];
        $analysisBadge = $this->buildAnalysisBadge((int) ($rowData['analysis_state'] ?? 0));
        if ($analysisBadge !== null) {
            $i2[] = [
                'text'  => $analysisBadge['label'],
                'class' => self::STATE_SPAN_CLASS[$analysisBadge['style']] ?? 'muted',
            ];
        }
        if (!empty($rowData['is_bulk'])) {
            $i2[] = ['text' => 'hromadná', 'class' => 'muted'];
        }
        $row['i2'] = $i2;

        // t3: [mailbox.name] + „od: odesílatel" (jen když ho t2 vytlačil
        // partner) + první řádek body_plain
        $mailboxName = trim((string) ($rowData['mailbox_name'] ?? ''));
        $bodyPreview = $this->firstBodyLine($rowData['body_plain'] ?? null, 100);
        $t3Parts = [];
        if ($mailboxName !== '') {
            $t3Parts[] = ['text' => '[' . $mailboxName . ']', 'class' => 'muted'];
        }
        if ($partner !== '' && $sender !== '') {
            $t3Parts[] = ['text' => $this->fromLabel() . ': ' . $sender, 'class' => 'muted'];
        }
        if ($bodyPreview !== '') {
            $t3Parts[] = ['text' => $bodyPreview];
        }
        $row['t3'] = $t3Parts !== [] ? $t3Parts : null;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT m.*, mb.`name` AS mailbox_name, mb.`mailbox_id` AS mailbox_code,'
            . ' p.`full_name` AS partner_full_name'
            . ' FROM `' . $this->table . '` m'
            . ' LEFT JOIN `core_mail_mailboxes` mb ON mb.`id` = m.`mailbox`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = m.`partner_person`'
            . ' WHERE m.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        $header = $this->buildDetailHeader($record);

        $tabs = [];

        // Tab 1 — Obsah
        $tabs[] = [
            'id'      => 'content',
            'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'content', 'Content'),
            'content' => $this->buildContentTab($record),
        ];

        // Tab 2 — Analýzy
        $tabs[] = [
            'id'      => 'analyses',
            'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'analyses', 'Analyses'),
            'content' => $this->buildAnalysesTab((int) $record['id']),
        ];

        // Tab 3 — Návrh (dokumentový návrh poslední analýzy)
        $tabs[] = [
            'id'      => 'proposal',
            'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'proposal', 'Proposal'),
            'content' => $this->buildProposalTab($record),
        ];

        // Tab 4 — Originál (raw .eml)
        $tabs[] = [
            'id'      => 'raw',
            'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'original', 'Original'),
            'content' => $this->buildRawSourceTab($record),
        ];

        return [
            'title'    => $header['title'],
            'subtitle' => $header['subtitle'],
            'badges'   => $header['badges'],
            // Stejny klic jako viewers[].icon v module.jsonc - jeden vyznam,
            // jedna ikona pro radek vieweru i hlavicku detailu.
            'icon'     => 'mail',
            'tabs'     => $tabs,
        ];
    }

    public function getToolbarActions(?array $selectedRow): array
    {
        // Start from the base — gives localized create/edit. Then we override
        // create's label with the mail-specific "New message" / "Nová zpráva"
        // and append reanalyze when the message is in an analyzable state.
        $actions = parent::getToolbarActions($selectedRow);

        $mailDefs = ($this->config?->cfgItem('core.mail.viewerDefaults') ?? [])['toolbarActions'] ?? [];

        if (isset($mailDefs['create']) && isset($actions[0]) && $actions[0]['id'] === 'create') {
            $actions[0]['label']   = $mailDefs['create']['name']    ?? $actions[0]['label'];
            $actions[0]['variant'] = $mailDefs['create']['variant'] ?? $actions[0]['variant'];
        }

        if ($selectedRow === null) {
            return $actions;
        }

        // "Zařadit do Spisovny" — ruční dispozice zprávy do base.registry,
        // viditelná mimo Koš (docState != 90). Obsluha ve Viewer.svelte
        // (POST /_registry/from-message/{ndx} → FormDialog nad novým Konceptem).
        if ((int) ($selectedRow['docState'] ?? 0) !== 90) {
            $fileDef = $mailDefs['fileToRegistry'] ?? ['name' => 'File to registry', 'variant' => 'secondary'];
            $actions[] = [
                'id'      => 'fileToRegistry',
                'label'   => $fileDef['name'] ?? 'File to registry',
                'variant' => $fileDef['variant'] ?? 'secondary',
                'meta'    => [
                    'messageNdx' => (int) $selectedRow['id'],
                ],
            ];
        }

        // "Znova analyzovat" je viditelné jen když analysis_state ∈ {30, 70}
        // (Analyzováno / Analýza selhala) a zpráva není v Archivu/Koši —
        // zrcadlí validaci AnalysisController::reanalyze.
        $analysisState = (int) ($selectedRow['analysis_state'] ?? 0);
        $docState = (int) ($selectedRow['docState'] ?? 0);
        if (($analysisState !== 30 && $analysisState !== 70) || $docState === 80 || $docState === 90) {
            return $actions;
        }

        // Inject seznam aktivních profilů → frontend dropdown bez další API
        // round-trip. Spec §5.3 chce dropdown s profily, ne číselné ID.
        $profiles = $this->db->fetchAll(
            'SELECT `id`, `profile_id`, `name` FROM `core_mail_ai_profiles`'
            . ' WHERE `is_active` = %i ORDER BY `is_default` DESC, `name` ASC',
            1,
        );
        $profileOptions = [];
        foreach ($profiles as $p) {
            $profileOptions[] = [
                'ndx' => (int) $p['id'],
                'profile_id' => (string) $p['profile_id'],
                'name' => (string) $p['name'],
            ];
        }

        $reanalyzeDef = $mailDefs['reanalyze'] ?? ['name' => 'Reanalyze', 'variant' => 'secondary'];
        $actions[] = [
            'id'      => 'reanalyze',
            'label'   => $reanalyzeDef['name']    ?? 'Reanalyze',
            'variant' => $reanalyzeDef['variant'] ?? 'secondary',
            'meta' => [
                'messageNdx' => (int) $selectedRow['id'],
                'profiles' => $profileOptions,
            ],
        ];

        return $actions;
    }

    // -------------------------------------------------------------------------
    // Private — detail tabs
    // -------------------------------------------------------------------------

    /**
     * Hlavička detailu nad taby — předmět jako title, odesílatel · schránka ·
     * doručeno jako subtitle, badges se stavem a primárním typem. Renderuje
     * generický header v ViewerDetail (detail.title/subtitle/badges).
     *
     * @return array{title: string, subtitle: ?string, badges: array<int, array{label: string, style: string}>}
     */
    private function buildDetailHeader(array $record): array
    {
        $subject = $this->displayTitle($record);

        $senderName  = trim((string) ($record['sender_name'] ?? ''));
        $senderEmail = trim((string) ($record['sender_email'] ?? ''));
        $sender = match (true) {
            $senderName !== '' && $senderEmail !== '' => $senderName . ' <' . $senderEmail . '>',
            $senderName !== ''                        => $senderName,
            default                                   => $senderEmail,
        };

        // Subtitle: partner · od: odesílatel · schránka · doručeno (D7);
        // bez partnera zůstává odesílatel bez prefixu jako dřív.
        $partner = $this->partnerLabel($record);
        $subtitleParts = [];
        if ($partner !== '') {
            $subtitleParts[] = $partner;
        }
        if ($sender !== '') {
            $subtitleParts[] = $partner !== '' ? $this->fromLabel() . ': ' . $sender : $sender;
        }
        $mailbox = $this->formatMailbox($record);
        if ($mailbox !== '') {
            $subtitleParts[] = $mailbox;
        }
        $received = $this->formatDateTime($record['received_at'] ?? null);
        if ($received !== null) {
            $subtitleParts[] = $received;
        }

        $badges = [];
        $stateBadge = $this->buildStateBadge((int) ($record['docState'] ?? 10));
        if ($stateBadge !== null) {
            $badges[] = $stateBadge;
        }

        $primaryType = (string) ($record['primary_type'] ?? 'other');
        $typeStyle = self::PRIMARY_TYPE_SPAN_CLASS[$primaryType] ?? 'muted';
        $badges[] = [
            'label' => $this->resolvePrimaryTypeLabel($primaryType),
            // Detail badge nemá variantu `muted` — mapujeme na `neutral`.
            'style' => $typeStyle === 'muted' ? 'neutral' : $typeStyle,
        ];

        $analysisBadge = $this->buildAnalysisBadge((int) ($record['analysis_state'] ?? 0));
        if ($analysisBadge !== null) {
            $badges[] = [
                'label' => $analysisBadge['label'],
                'style' => $analysisBadge['style'] === 'archive' ? 'neutral' : $analysisBadge['style'],
            ];
        }

        // Stav technického předzpracování (tasks/mail-preprocess.md) — 0 = netýká se, skryto.
        $preprocessBadge = $this->buildCfgStateBadge('core.mail.preprocessStates', (int) ($record['preprocess_state'] ?? 0));
        if ($preprocessBadge !== null) {
            $badges[] = [
                'label' => $preprocessBadge['label'],
                'style' => $preprocessBadge['style'] === 'archive' ? 'neutral' : $preprocessBadge['style'],
            ];
        }

        return [
            'title'    => $subject !== '' ? $subject : '(bez předmětu)',
            'subtitle' => $subtitleParts !== [] ? implode(' · ', $subtitleParts) : null,
            'badges'   => $badges,
        ];
    }

    /**
     * Badge stavu AI analýzy z cfgItem `core.mail.analysisStates`.
     * Hodnota 0 (Bez analýzy) se nezobrazuje → null.
     *
     * @return array{label: string, style: string}|null
     */
    private function buildAnalysisBadge(int $analysisState): ?array
    {
        return $this->buildCfgStateBadge('core.mail.analysisStates', $analysisState);
    }

    /**
     * Badge stavu z enumInt cfgItem (analysisStates, preprocessStates).
     * Hodnota 0 („netýká se") se nezobrazuje → null.
     *
     * @return array{label: string, style: string}|null
     */
    private function buildCfgStateBadge(string $cfgItemId, int $state): ?array
    {
        if ($state === 0 || $this->config === null) {
            return null;
        }

        $cfg = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfg) || !isset($cfg[(string) $state])) {
            return null;
        }
        $entry = $cfg[(string) $state];

        return [
            'label' => (string) ($entry['name'] ?? $state),
            'style' => (string) ($entry['stateStyle'] ?? 'concept'),
        ];
    }

    /** Badge stavu zprávy (label + stateStyle) z docState configu. */
    private function buildStateBadge(int $docState): ?array
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return null;
        }

        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $label = (string) ($stateData['stateName'] ?? '');
        if ($label === '') {
            return null;
        }

        return [
            'label' => $label,
            'style' => (string) ($stateData['stateStyle'] ?? 'concept'),
        ];
    }

    private function buildContentTab(array $record): array
    {
        // Předmět, odesílatel, schránka, doručeno, typ a stav jsou v hlavičce
        // detailu nad taby (buildDetailHeader). Tady zůstávají jen technické
        // identifikátory zprávy.
        $techItems = [];
        // Když hlavička ukazuje titulek z AI místo generického předmětu,
        // původní předmět zůstává dohledatelný tady (D3).
        if ($this->usesAiTitle($record)) {
            $this->addItem($techItems, $this->viewerLabel('subject', 'Subject'), $record['subject'] ?? null);
        }
        $this->addItem($techItems, 'Kód zprávy', $record['message_id'] ?? null);
        if (!empty($record['external_message_id'])) {
            $this->addItem($techItems, 'Message-ID', (string) $record['external_message_id']);
        }

        $bodyHtml = (string) ($record['body_html'] ?? '');
        $bodyPlain = (string) ($record['body_plain'] ?? '');

        // Tělo: preferujeme HTML, fallback na plain. HTML je nedůvěryhodný
        // vstup (e-mail) — frontend ho renderuje v sandboxovaném iframe
        // (SandboxedHtml.svelte), do DB se ukládá raw (api-contract §7).
        $bodyContent = null;
        if ($bodyHtml !== '') {
            $bodyContent = ['type' => 'untrusted-html', 'html' => $bodyHtml];
        } elseif ($bodyPlain !== '') {
            $bodyContent = [
                'type' => 'html',
                'html' => '<pre style="white-space: pre-wrap; font-family: inherit;">'
                    . htmlspecialchars($bodyPlain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</pre>',
            ];
        }

        $attachments = $this->fetchContentAttachments($record);

        // Skládáme bloky: tělo, přílohy a technické údaje jen pokud existují.
        // Tělo bez headingu — s hlavičkou nad taby začíná obsah rovnou textem.
        $blocks = [];
        if ($bodyContent !== null) {
            $blocks[] = $bodyContent;
        }
        if ($attachments !== []) {
            $blocks[] = [
                'type' => 'heading',
                'text' => $this->detailTabLabel('core.mail.viewerDetailLabels', 'attachments', 'Attachments'),
            ];
            $blocks[] = ['type' => 'attachment-grid', 'attachments' => $attachments];
        }
        $preprocessItems = $this->buildPreprocessItems($record);
        if ($preprocessItems !== []) {
            $blocks[] = [
                'type'   => 'properties',
                'groups' => [['title' => 'Předzpracování', 'items' => $preprocessItems]],
            ];
        }
        if ($techItems !== []) {
            $blocks[] = [
                'type'   => 'properties',
                'groups' => [['title' => 'Technické údaje', 'items' => $techItems]],
            ];
        }

        if ($blocks === []) {
            return ['type' => 'html', 'html' => '<p class="muted">Zpráva nemá žádný obsah.</p>'];
        }
        if (count($blocks) === 1) {
            return $blocks[0];
        }

        return ['type' => 'composite', 'blocks' => $blocks];
    }

    /**
     * Výsledky technického předzpracování z `preprocess_state` +
     * `preprocess_log` (stav, pokusy, výsledek per akce, čas). Prázdné pro
     * zprávy, kterých se předzpracování netýká (stav 0 bez logu).
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function buildPreprocessItems(array $record): array
    {
        $state = (int) ($record['preprocess_state'] ?? 0);
        $log = PreprocessRunner::decodeLog($record['preprocess_log'] ?? null);
        if ($state === 0 && $log === []) {
            return [];
        }

        $items = [];
        $badge = $this->buildCfgStateBadge('core.mail.preprocessStates', $state);
        $this->addItem($items, 'Stav', $badge['label'] ?? (string) $state);

        if (($log['trigger'] ?? null) === PreprocessRunner::TRIGGER_ISDOC) {
            // ISDOC-only běh (#81): žádná pravidla, runner spuštěn jen kvůli
            // importu — bez tohoto řádku by blok ukazoval jen stav a ISDOC.
            $this->addItem($items, 'Spuštěno', 'ISDOC import');
        }

        $plan = is_array($log['plan'] ?? null) ? $log['plan'] : [];
        $ruleIds = [];
        foreach ($plan as $entry) {
            if (is_array($entry) && ($entry['ruleId'] ?? '') !== '') {
                $ruleIds[] = (string) $entry['ruleId'];
            }
        }
        $this->addItem($items, 'Pravidla', implode(', ', $ruleIds));

        $attempts = (int) ($log['attempts'] ?? 0);
        if ($attempts > 0) {
            $this->addItem($items, 'Pokusy', (string) $attempts);
        }

        foreach (is_array($log['results'] ?? null) ? $log['results'] : [] as $result) {
            if (!is_array($result)) {
                continue;
            }
            $label = trim(((string) ($result['ruleId'] ?? '')) . ' / ' . ((string) ($result['action'] ?? '')), ' /');
            $value = !empty($result['ok']) ? 'OK' : 'Chyba';
            $note = trim((string) ($result['note'] ?? ''));
            if ($note !== '') {
                $value .= ' — ' . $note;
            }
            if (!empty($result['attachmentId'])) {
                $value .= ' (příloha #' . (int) $result['attachmentId'] . ')';
            }
            $this->addItem($items, $label !== '' ? $label : 'Akce', $value);
        }

        if (!empty($log['isdoc']) && $log['isdoc'] !== 'skipped') {
            $this->addItem($items, 'ISDOC', (string) $log['isdoc']);
        }
        $this->addItem($items, 'Dokončeno', $this->formatDateTime($log['finishedAt'] ?? null));

        return $items;
    }

    /**
     * Obsahové přílohy zprávy pro blok `attachment-grid` (AttachmentGrid).
     * Vylučuje raw .eml (raw_source_attachment); velikost posíláme v bajtech,
     * formátuje frontend (formatFileSize v api/attachments.js).
     *
     * `generated` = příloha vygenerovaná předzpracováním (provenance
     * `metadata.generatedBy`), frontend ji označí badgem.
     *
     * @return array<int, array{id: int, name: string, mime_type: string, file_size: int, generated: bool}>
     */
    private function fetchContentAttachments(array $record): array
    {
        $rawId = isset($record['raw_source_attachment']) && $record['raw_source_attachment'] !== null
            ? (int) $record['raw_source_attachment']
            : null;

        // Seznam obsahových příloh = core_attachments_files.table_id = 303 AND record_id = msg.id
        // s vyloučením raw .eml (raw_source_attachment_ndx)
        $sql = 'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`, `metadata`'
            . ' FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0';
        $params = [303, (int) $record['id']];

        if ($rawId !== null) {
            $sql .= ' AND `id` != %i';
            $params[] = $rawId;
        }

        $sql .= ' ORDER BY `att_order` ASC, `name` ASC';

        $files = $this->db->fetchAll($sql, ...$params);
        $out = [];
        foreach ($files as $f) {
            $out[] = [
                'id'        => (int) $f['id'],
                'name'      => (string) ($f['name'] ?? $f['file_name']),
                'mime_type' => (string) ($f['mime_type'] ?? ''),
                'file_size' => (int) ($f['file_size'] ?? 0),
                'generated' => PreprocessRunner::isGeneratedAttachment((array) $f),
            ];
        }

        return $out;
    }

    private function buildAnalysesTab(int $messageId): array
    {
        // Nevalidní výstup úspěšného běhu poznáme podle forenzního wrapperu
        // (`AnalysisController` ho skládá s `_validationError` jako prvním
        // klíčem) — bez tahání celého canonical_json per běh.
        $invalidMarker = '{"_validationError"';
        $analyses = $this->db->fetchAll(
            'SELECT `id`, `analyzed_at`, `status`, `model_name`, `model_version`, `prompt_version`,'
            . ' `confidence`, `cost_usd`, `duration_ms`, `canonical_json` IS NOT NULL AS `has_proposal`,'
            . ' LEFT(`canonical_json`, ' . strlen($invalidMarker) . ') = %s AS `invalid_output`,'
            . ' `resolution`, `error_message`'
            . ' FROM `core_mail_message_analyses`'
            . ' WHERE `message` = %i'
            . ' ORDER BY `analyzed_at` DESC',
            $invalidMarker,
            $messageId,
        );

        if ($analyses === []) {
            return ['type' => 'html', 'html' => '<p class="muted">Pro tuto zprávu zatím neexistuje žádná AI analýza.</p>'];
        }

        $statusLabels = [1 => 'Probíhá', 2 => 'Úspěch', 3 => 'Selhala'];
        $resolutionMap = $this->loadAnalysisResolutions();
        $presenter = new AnalysisErrorPresenter($this->db, $this->config);
        $rows = [];
        foreach ($analyses as $a) {
            $confidence = $a['confidence'] !== null ? number_format((float) $a['confidence'], 3) : '—';
            $cost = $a['cost_usd'] !== null ? '$' . number_format((float) $a['cost_usd'], 4) : '—';
            $duration = $a['duration_ms'] !== null
                ? number_format((int) $a['duration_ms'] / 1000, 1) . ' s'
                : '—';
            $resolution = $a['resolution'] !== null ? (int) $a['resolution'] : null;

            // Sloupec Chyba: lidský titulek (+ detail) z katalogu u selhaných
            // běhů a u běhů s nevalidním výstupem; technická hláška sem nejde.
            $error = '—';
            if ((int) ($a['status'] ?? 1) === 3) {
                $error = $this->failureCell($presenter->fromErrorMessage(
                    isset($a['error_message']) ? (string) $a['error_message'] : null,
                    isset($a['prompt_version']) ? (string) $a['prompt_version'] : null,
                ));
            } elseif (!empty($a['invalid_output'])) {
                $error = $this->failureCell($presenter->forInvalidOutput(
                    isset($a['prompt_version']) ? (string) $a['prompt_version'] : null,
                ));
            }

            $rows[] = [
                'analyzed_at' => $this->formatDateTime($a['analyzed_at'] ?? null),
                'status'      => $statusLabels[(int) ($a['status'] ?? 1)] ?? '—',
                'error'       => $error,
                'model'       => trim(($a['model_name'] ?? '') . ' ' . ($a['model_version'] ?? '')),
                'prompt'      => $a['prompt_version'] ?? '',
                'confidence'  => $confidence,
                'proposal'    => !empty($a['has_proposal']) ? 'ano' : 'ne',
                'resolution'  => $resolution !== null
                    ? ($resolutionMap[$resolution]['name'] ?? (string) $resolution)
                    : '—',
                'cost'        => $cost,
                'duration'    => $duration,
            ];
        }

        return [
            'type'    => 'table',
            'columns' => [
                ['id' => 'analyzed_at', 'label' => 'Čas'],
                ['id' => 'status',      'label' => 'Stav'],
                ['id' => 'error',       'label' => 'Chyba'],
                ['id' => 'model',       'label' => 'Model'],
                ['id' => 'prompt',      'label' => 'Prompt'],
                ['id' => 'confidence',  'label' => 'Jistota'],
                ['id' => 'proposal',    'label' => 'Návrh'],
                ['id' => 'resolution',  'label' => 'Verdikt'],
                ['id' => 'cost',        'label' => 'Cena'],
                ['id' => 'duration',    'label' => 'Trvání'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Tab „Návrh" — dokumentový návrh poslední úspěšné analýzy (D1: nejvýše
     * jeden). Vrací custom content type `proposal` s jednou kartou: typ,
     * confidence pásmo (runtime resolver), summary z canonicalu, verdikt
     * (resolution badge), hint dalších nálezů (`secondary_findings`)
     * a akce Použít / Zamítnout / Detail. Bez návrhu prázdný stav
     * s klasifikací zprávy.
     *
     * Selhání (tasks/mail-analysis-error-messages.md D3a, D5): při
     * `analysis_state = 70` nese obsah `failure` z poslední selhané
     * analýzy — frontend kreslí kartu selhání místo prázdného stavu
     * (klasifikace se pak neposílá; `primary_type` je ve stavu 70 jen
     * výchozí hodnota) nebo nad starším návrhem, pokud existuje. Návrh
     * s nevalidním výstupem (`_validationError`) nese vlastní
     * `proposal.failure` kategorie `invalidOutput`.
     */
    private function buildProposalTab(array $record): array
    {
        $messageId = (int) $record['id'];
        $presenter = new AnalysisErrorPresenter($this->db, $this->config);
        $failure = (int) ($record['analysis_state'] ?? 0) === IncomingMessageDocument::ANALYSIS_FAILED
            ? $this->buildFailure($messageId, $presenter)
            : null;

        $analysis = $this->db->fetchRow(
            'SELECT * FROM `core_mail_message_analyses`'
            . ' WHERE `message` = %i AND `status` = %i'
            . ' ORDER BY `analyzed_at` DESC, `id` DESC LIMIT 1',
            $messageId, 2,
        );

        if ($analysis === null || $analysis['canonical_json'] === null || $analysis['canonical_json'] === '') {
            $content = [
                'type' => 'proposal',
                'proposal' => null,
                'failure' => $failure,
            ];
            if ($failure === null) {
                $content['classification'] = [
                    'primary_type' => (string) ($record['primary_type'] ?? 'other'),
                    'primary_type_label' => $this->primaryTypeLabelFor((string) ($record['primary_type'] ?? 'other')),
                ];
            }
            return $content;
        }

        $canonical = json_decode((string) $analysis['canonical_json'], true);
        $canonical = is_array($canonical) ? $canonical : [];
        $aiFailed = isset($canonical['_validationError']);
        $proposalFailure = $aiFailed
            ? $this->failurePayload(
                $presenter->forInvalidOutput(isset($analysis['prompt_version']) ? (string) $analysis['prompt_version'] : null),
                $analysis,
            )
            : null;

        $proposedType = (string) ($analysis['proposed_type'] ?? 'other');
        $confidence = $analysis['confidence'] !== null ? (float) $analysis['confidence'] : null;
        $resolution = $analysis['resolution'] !== null ? (int) $analysis['resolution'] : null;
        $resolutionMap = $this->loadAnalysisResolutions();

        $band = null;
        if (!$aiFailed && $resolution === null) {
            $resolver = new AnalysisConfidenceResolver($this->db);
            $profileNdx = $analysis['profile'] !== null ? (int) $analysis['profile'] : null;
            $band = $resolver->bandForAnalysis($confidence, $profileNdx, $canonical);
        }

        $docState = (int) ($record['docState'] ?? 0);
        $actionable = !$aiFailed
            && $resolution === null
            && (int) ($record['analysis_state'] ?? 0) === 30
            && $docState !== 80 && $docState !== 90;

        return [
            'type' => 'proposal',
            'failure' => $failure,
            'proposal' => [
                'analysisNdx'        => (int) $analysis['id'],
                'messageNdx'         => $messageId,
                'ai_failed'          => $aiFailed,
                'failure'            => $proposalFailure,
                'proposed_type'      => $proposedType,
                'proposed_type_label' => $this->primaryTypeLabelFor($proposedType),
                'confidence'         => $confidence !== null ? round($confidence, 3) : null,
                'band'               => $band,
                'summary'            => $aiFailed ? null : $this->summarizeExtractedJson((string) $analysis['canonical_json']),
                'resolution'         => $resolution,
                'resolution_label'   => $resolution !== null
                    ? ($resolutionMap[$resolution]['name'] ?? (string) $resolution)
                    : null,
                'resolution_style'   => $resolution !== null
                    ? ($resolutionMap[$resolution]['stateStyle'] ?? 'concept')
                    : null,
                'resolved_at'        => $this->formatDateTime($analysis['resolved_at'] ?? null),
                'rejected_reason'    => $analysis['rejected_reason'] ?? null,
                'secondary_findings' => $this->secondaryFindingsFor($analysis),
                'can_apply'          => $actionable,
                'can_reject'         => $actionable,
            ],
            'classification' => [
                'primary_type' => (string) ($record['primary_type'] ?? 'other'),
                'primary_type_label' => $this->primaryTypeLabelFor((string) ($record['primary_type'] ?? 'other')),
            ],
        ];
    }

    /**
     * `failure` pro stav 70: poslední selhaný běh (`status = 3`) přes
     * katalog hlášek. Bez řádku (nekonzistentní data) kategorie `unknown`.
     *
     * @return array<string, mixed>
     */
    private function buildFailure(int $messageId, AnalysisErrorPresenter $presenter): array
    {
        $failed = $this->db->fetchRow(
            'SELECT `id`, `analyzed_at`, `prompt_version`, `error_message` FROM `core_mail_message_analyses`'
            . ' WHERE `message` = %i AND `status` = %i'
            . ' ORDER BY `analyzed_at` DESC, `id` DESC LIMIT 1',
            $messageId, 3,
        );
        $info = $presenter->fromErrorMessage(
            isset($failed['error_message']) ? (string) $failed['error_message'] : null,
            isset($failed['prompt_version']) ? (string) $failed['prompt_version'] : null,
        );
        return $this->failurePayload($info, $failed ?? []);
    }

    /**
     * Tvar `failure` pro frontend: hláška z katalogu + čas a verze promptu
     * běhu (technické podrobnosti, sbalené).
     *
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private function failurePayload(AnalysisErrorInfo $info, array $analysis): array
    {
        return $info->toArray() + [
            'analyzedAt'    => $this->formatDateTime($analysis['analyzed_at'] ?? null),
            'promptVersion' => isset($analysis['prompt_version']) ? (string) $analysis['prompt_version'] : null,
        ];
    }

    /** Buňka sloupce Chyba v tabu Analýzy: titulek, za pomlčkou případný detail. */
    private function failureCell(AnalysisErrorInfo $info): string
    {
        return $info->detail !== null && $info->detail !== ''
            ? $info->title . ' — ' . $info->detail
            : $info->title;
    }

    /**
     * Informativní hint dalších nálezů běhu (D7): pole `{type, note}`
     * z analysis_json, typ přeložený přes primaryTypes. Žádné entity,
     * žádný stav.
     *
     * @param array<string, mixed> $analysis
     * @return list<array{type: string, type_label: string, note: string}>
     */
    private function secondaryFindingsFor(array $analysis): array
    {
        $analysisJson = json_decode((string) ($analysis['analysis_json'] ?? ''), true);
        $findings = is_array($analysisJson) ? ($analysisJson['secondary_findings'] ?? null) : null;
        if (!is_array($findings)) {
            return [];
        }
        $out = [];
        foreach ($findings as $f) {
            if (!is_array($f)) {
                continue;
            }
            $type = trim((string) ($f['type'] ?? ''));
            $out[] = [
                'type' => $type,
                'type_label' => $this->primaryTypeLabelFor($type),
                'note' => trim((string) ($f['note'] ?? '')),
            ];
        }
        return $out;
    }

    /** Label typu z cfgItem `core.mail.primaryTypes` (fallback klíč). */
    private function primaryTypeLabelFor(string $type): string
    {
        $cfg = $this->config?->cfgItem('core.mail.primaryTypes');
        $entry = is_array($cfg) ? ($cfg[$type] ?? null) : null;
        return is_array($entry) ? (string) ($entry['name'] ?? $type) : $type;
    }

    /**
     * @return array<int, array{name: string, stateStyle: string, icon: ?string}>
     */
    private function loadAnalysisResolutions(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('core.mail.analysisResolutions');
        if ($cfg === null) {
            return [];
        }
        $out = [];
        foreach ($cfg as $key => $entry) {
            $out[(int) $key] = [
                'name' => (string) ($entry['name'] ?? $key),
                'stateStyle' => (string) ($entry['stateStyle'] ?? 'concept'),
                'icon' => $entry['icon'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Krátké shrnutí extrahovaného JSON pro list view ("Faktura č. X, 12 500 Kč, dodavatel Y").
     */
    private function summarizeExtractedJson(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        // Common path z faktury default profilu
        $fields = $decoded['fields'] ?? $decoded;
        if (!is_array($fields)) {
            return null;
        }
        $parts = [];
        if (!empty($fields['invoice_number'])) {
            $parts[] = 'č. ' . (string) $fields['invoice_number'];
        }
        if (!empty($fields['total_amount'])) {
            $currency = (string) ($fields['currency'] ?? 'Kč');
            $parts[] = number_format((float) $fields['total_amount'], 2, ',', ' ') . ' ' . $currency;
        }
        $supplier = $fields['supplier']['name'] ?? null;
        if (is_string($supplier) && $supplier !== '') {
            $parts[] = $supplier;
        }
        return $parts === [] ? null : implode(', ', $parts);
    }

    private function buildRawSourceTab(array $record): array
    {
        $rawId = isset($record['raw_source_attachment']) && $record['raw_source_attachment'] !== null
            ? (int) $record['raw_source_attachment']
            : null;

        if ($rawId === null) {
            return [
                'type' => 'html',
                'html' => '<p class="muted">Originální <code>.eml</code> není k dispozici (zpráva pořízena ručně).</p>',
            ];
        }

        $raw = $this->db->fetchRow(
            'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`, `created`'
            . ' FROM `core_attachments_files` WHERE `id` = %i AND `is_deleted` = 0',
            $rawId,
        );

        if ($raw === null) {
            return ['type' => 'html', 'html' => '<p class="muted">Originál byl smazán nebo není dostupný.</p>'];
        }

        return [
            'type'   => 'properties',
            'groups' => [[
                'title' => 'Originální .eml',
                'items' => [
                    ['label' => 'Název',     'value' => (string) ($raw['name'] ?? $raw['file_name'])],
                    ['label' => 'Velikost',  'value' => $this->formatFileSize((int) ($raw['file_size'] ?? 0))],
                    ['label' => 'MIME',      'value' => (string) ($raw['mime_type'] ?? '')],
                    ['label' => 'Uloženo',   'value' => $this->formatDateTime($raw['created'] ?? null)],
                ],
            ]],
        ];
    }

    // -------------------------------------------------------------------------
    // Private — formátovací helpery
    // -------------------------------------------------------------------------

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }

        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        return $stateData['stateStyle'] ?? 'concept';
    }

    private function resolvePrimaryTypeLabel(string $key): string
    {
        if ($this->config !== null) {
            $types = $this->config->cfgItem('core.mail.primaryTypes');
            if (is_array($types) && isset($types[$key]['name'])) {
                return (string) $types[$key]['name'];
            }
        }

        // Fallback bez configu
        return match ($key) {
            'invoiceReceived' => 'Přijatá faktura',
            'other'           => 'Ostatní',
            'creditNote'      => 'Dobropis',
            'order'           => 'Objednávka',
            'quotation'       => 'Nabídka',
            'statement'       => 'Výpis / Saldo',
            'complaint'       => 'Reklamace',
            default           => $key,
        };
    }

    /**
     * Partner zprávy pro zobrazení: jméno Osoby (`partner_full_name` z JOINu),
     * jinak snapshot `partner_name` z canonicalu (P7), jinak prázdný řetězec.
     *
     * @param array<string, mixed> $row
     */
    private function partnerLabel(array $row): string
    {
        $fullName = trim((string) ($row['partner_full_name'] ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }
        return trim((string) ($row['partner_name'] ?? ''));
    }

    /**
     * Popisek „od" před odesílatelem (t3, subtitle detailu) — z cfgItem
     * `core.mail.viewerDetailLabels.labels.from`, bez configu anglicky.
     */
    private function fromLabel(): string
    {
        return $this->viewerLabel('from', 'from');
    }

    /** Drobný popisek z `core.mail.viewerDetailLabels.labels.*`, bez configu anglický fallback. */
    private function viewerLabel(string $key, string $englishFallback): string
    {
        $labels = ($this->config?->cfgItem('core.mail.viewerDetailLabels') ?? [])['labels'] ?? [];
        return (string) ($labels[$key]['name'] ?? $englishFallback);
    }

    /**
     * Titulek řádku / detailu — předmět, nebo `ai_title` u generického /
     * prázdného předmětu a ručních zpráv (pravidlo D3).
     *
     * @param array<string, mixed> $row
     */
    private function displayTitle(array $row): string
    {
        return IncomingMessageTitle::display(
            (string) ($row['subject'] ?? ''),
            isset($row['ai_title']) ? (string) $row['ai_title'] : null,
            (int) ($row['source_type'] ?? 0),
            $this->genericPatterns(),
        );
    }

    /** @param array<string, mixed> $row */
    private function usesAiTitle(array $row): bool
    {
        return IncomingMessageTitle::usesAiTitle(
            (string) ($row['subject'] ?? ''),
            isset($row['ai_title']) ? (string) $row['ai_title'] : null,
            (int) ($row['source_type'] ?? 0),
            $this->genericPatterns(),
        );
    }

    /** @return list<string> */
    private function genericPatterns(): array
    {
        return $this->genericPatterns ??= IncomingMessageTitle::patternsFrom($this->config);
    }

    private function formatMailbox(array $record): string
    {
        $name = trim((string) ($record['mailbox_name'] ?? ''));
        $code = trim((string) ($record['mailbox_code'] ?? ''));
        if ($name !== '' && $code !== '') {
            return $name . ' (' . $code . ')';
        }
        return $name !== '' ? $name : $code;
    }

    private function formatRelativeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ts = $this->parseDateTime($value);
        if ($ts === null) {
            return null;
        }

        $diff = time() - $ts;

        if ($diff < 60) {
            return 'právě teď';
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return 'před ' . $mins . ' min';
        }
        if ($diff < 86400) {
            $hrs = (int) floor($diff / 3600);
            return 'před ' . $hrs . ' h';
        }
        if ($diff < 2 * 86400) {
            return 'včera ' . date('H:i', $ts);
        }
        if ($diff < 7 * 86400) {
            $days = (int) floor($diff / 86400);
            return 'před ' . $days . ' d';
        }

        // Starší než týden → datum
        return date('j. n.', $ts);
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = $this->parseDateTime($value);
        return $ts !== null ? date('j. n. Y H:i', $ts) : null;
    }

    private function parseDateTime(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts !== false ? $ts : null;
        }
        return null;
    }

    private function firstBodyLine(mixed $body, int $maxLen): string
    {
        if (!is_string($body) || $body === '') {
            return '';
        }
        $lines = preg_split('/\R/', trim($body), 2);
        $first = is_array($lines) && $lines !== [] ? (string) $lines[0] : '';
        $first = trim($first);
        if (mb_strlen($first) > $maxLen) {
            $first = mb_substr($first, 0, $maxLen - 1) . '…';
        }
        return $first;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', ' ') . ' kB';
        }
        return number_format($bytes / (1024 * 1024), 1, ',', ' ') . ' MB';
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
