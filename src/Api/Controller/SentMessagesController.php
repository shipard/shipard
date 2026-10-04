<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Module\Core\Mail\Sent\SentMessageException;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;
use Shipard\Module\Core\Mail\Sent\SentMessageTransport;
use Shipard\Module\Core\Mail\Sent\SentMessageTransportInfo;

/**
 * Endpoint:
 *   POST /_sent-messages/{id}/resend
 *
 * Odeslat znovu (#90 D44): další průchod téže zprávy transportem — stejní
 * příjemci, stejné přílohy, žádná nová zpráva. Zkusí odeslat hned; selhání
 * transportu nechá zprávu ve frontě a odpověď to řekne stavem `queued`.
 *
 * Práva (D38): kdo smí tabulku Odeslané pošty (`guardTable`) a zdroj dat
 * není jen pro čtení (`ReadOnlyPolicy` — routa v ní výjimku nemá).
 * Chyby: 404 neznámá zpráva, 409 `INVALID_STATE` (archivovaná / smazaná),
 * 409 `ALREADY_QUEUED` (už čeká ve frontě).
 */
class SentMessagesController
{
    public function __construct(
        private readonly SentMessageStore $store,
        private readonly SentMessageTransport $transport,
        private readonly SentMessageTransportInfo $info,
    ) {}

    /** @param array<string, \Shipard\Core\Database\TableDefinition> $tables */
    public function resend(int $id, AuthContext $auth, array $tables): Response
    {
        $guardErr = TableAccessGuard::guardTable(
            SentMessageStore::TABLE,
            $auth,
            $tables[SentMessageStore::TABLE] ?? null,
        );
        if ($guardErr !== null) {
            return $guardErr;
        }

        try {
            $this->transport->resend($id, true, $auth->isAuthenticated ? $auth->userId : null);
        } catch (SentMessageException $e) {
            return Response::error(
                $e->errorCode,
                $e->getMessage(),
                $e->errorCode === SentMessageException::NOT_FOUND ? 404 : 409,
            );
        }

        $message   = $this->store->get($id) ?? ['id' => $id];
        $transport = $this->info->describe($message);

        return Response::success([
            'transportState' => $transport['state'],
            'transport'      => $transport,
        ]);
    }
}
