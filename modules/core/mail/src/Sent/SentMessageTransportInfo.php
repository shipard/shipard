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

    /** Kolik posledních pokusů formulář ukáže. */
    private const ATTEMPTS_LIMIT = 20;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * @param array<string, mixed> $message Řádek `core_mail_sent_messages`.
     * @return array{state: string, stateLabel: string, stateStyle: string}
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

        return $this->state($message) + [
            'messageId' => (int) $message['id'],
            'sentAt'    => SubtableCellFormatter::dateTime($message['sent_at'] ?? null),
            'sendCount' => (int) ($message['send_count'] ?? 0),
            'lastError' => isset($message['last_error']) && $message['last_error'] !== ''
                ? (string) $message['last_error']
                : null,
            // Odeslat znovu jen zprávu ve stavu Odeslaná, která nečeká ve frontě.
            'canResend' => $docState === SentMessageStore::DOC_STATE_SENT && !$queued,
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
