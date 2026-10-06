<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\SubtableCellFormatter;

/**
 * Stav transportu odeslané zprávy pro rozhraní — jeden tvar pro formulář
 * zprávy (komponenta `sentMessageTransport`), odpověď Odeslat znovu i sekci
 * Odeslaná pošta v detailu záznamu. Historie pokusů se čte z logu fronty,
 * dokud ho úklid nesmazal; stav sám žije na zprávě (#90 D43).
 */
final class SentMessageTransportInfo
{
    private const STATES_ITEM = 'core.mail.transportStates';
    private const SAFETY_ITEM = 'core.mail.safetyActions';

    /** Anglické štítky pojistky pro zdroj dat bez zkompilované konfigurace. */
    private const SAFETY_FALLBACK = [
        'redirected' => [
            'name'           => 'Redirected',
            'nameTarget'     => 'Redirected to {target}',
            'nameRestricted' => 'Recipients restricted by mail safety',
        ],
        'dropped' => ['name' => 'Held — not sent'],
    ];

    /** Kolik posledních pokusů formulář ukáže. */
    private const ATTEMPTS_LIMIT = 20;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * @param array<string, mixed> $message Řádek `core_mail_sent_messages`.
     * @return array{state: string, stateLabel: string, stateStyle: string,
     *               safety: ?array{action: string, target: ?string, label: string, style: string}}
     */
    public function state(array $message): array
    {
        $state  = (string) ($message['transport_state'] ?? SentMessageStore::TRANSPORT_QUEUED);
        $states = $this->config?->cfgItem(self::STATES_ITEM);
        $entry  = is_array($states) ? ($states[$state] ?? null) : null;

        return [
            'state'      => $state,
            'stateLabel' => is_array($entry) && isset($entry['name']) ? (string) $entry['name'] : $state,
            'stateStyle' => is_array($entry) && isset($entry['style']) ? (string) $entry['style'] : 'neutral',
            'safety'     => $this->safety($message, $state),
        ];
    }

    /**
     * Zásah pojistky odchozí pošty při posledním odeslání (#95 D6) —
     * „Odesláno“ pak znamená přesměrováno nebo zachyceno. Zpráva, která
     * čeká ve frontě nebo selhala, stopu dřívějšího odeslání neukazuje.
     *
     * @param array<string, mixed> $message
     * @return array{action: string, target: ?string, label: string, style: string}|null
     */
    private function safety(array $message, string $state): ?array
    {
        $action = (string) ($message['safety_action'] ?? '');
        if ($action === '' || $state !== SentMessageStore::TRANSPORT_SENT) {
            return null;
        }

        $actions = $this->config?->cfgItem(self::SAFETY_ITEM);
        $entry   = (is_array($actions) && is_array($actions[$action] ?? null) ? $actions[$action] : [])
            + (self::SAFETY_FALLBACK[$action] ?? []);

        $target = trim((string) ($message['safety_target'] ?? ''));
        $label  = (string) ($entry['name'] ?? $action);
        if ($action === 'redirected') {
            $label = $target !== ''
                ? str_replace('{target}', $target, (string) ($entry['nameTarget'] ?? $label))
                : (string) ($entry['nameRestricted'] ?? $label);
        }

        return [
            'action' => $action,
            'target' => $target !== '' ? $target : null,
            'label'  => $label,
            'style'  => (string) ($entry['style'] ?? 'warning'),
        ];
    }

    /**
     * @param array<string, mixed> $message Řádek `core_mail_sent_messages`.
     * @return array<string, mixed>
     */
    public function describe(array $message): array
    {
        $docState = (int) ($message['docState'] ?? 0);
        $queued   = (string) ($message['transport_state'] ?? '') === SentMessageStore::TRANSPORT_QUEUED;
        $imported = (string) ($message['send_trigger'] ?? '') === SentMessageStore::TRIGGER_IMPORT;

        return $this->state($message) + [
            'messageId' => (int) $message['id'],
            'sentAt'    => SubtableCellFormatter::dateTime($message['sent_at'] ?? null),
            'sendCount' => (int) ($message['send_count'] ?? 0),
            'lastError' => isset($message['last_error']) && $message['last_error'] !== ''
                ? (string) $message['last_error']
                : null,
            // Zpráva převzatá ze starého systému (#104 D5) — rozhraní podle
            // toho skryje Odeslat znovu, ne podle textu štítku.
            'imported'  => $imported,
            // Odeslat znovu jen zprávu ve stavu Odeslaná, která nečeká ve
            // frontě a nevznikla importem.
            'canResend' => $docState === SentMessageStore::DOC_STATE_SENT && !$queued && !$imported,
            'attempts'  => $this->attempts((int) $message['id']),
        ];
    }

    /**
     * Pokusy o odeslání všech průchodů zprávy frontou, nejnovější první.
     *
     * @return list<array{at: ?string, ok: bool, transport: string, response: ?string}>
     */
    private function attempts(int $messageId): array
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT l.[ts], l.[result], l.[transport], l.[smtp_response]'
                . ' FROM [core_mail_outbox_log] l'
                . ' JOIN [core_mail_outbox] o ON o.[id] = l.[outbox_id]'
                . ' WHERE o.[source_ref] = %s ORDER BY l.[id] DESC LIMIT %i',
                SentMessageStore::sourceRef($messageId),
                self::ATTEMPTS_LIMIT,
            );
        } catch (\Dibi\Exception) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'at'        => SubtableCellFormatter::dateTime($row['ts'] ?? null),
            'ok'        => (string) ($row['result'] ?? '') === 'ok',
            'transport' => (string) ($row['transport'] ?? ''),
            'response'  => isset($row['smtp_response']) && $row['smtp_response'] !== ''
                ? (string) $row['smtp_response']
                : null,
        ], $rows);
    }
}
