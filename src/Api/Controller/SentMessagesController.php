<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Module\Core\Mail\Sent\SentMessageException;
use Shipard\Module\Core\Mail\Sent\SentMessageImportException;
use Shipard\Module\Core\Mail\Sent\SentMessageImportService;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;
use Shipard\Module\Core\Mail\Sent\SentMessageTransport;
use Shipard\Module\Core\Mail\Sent\SentMessageTransportInfo;

/**
 * Endpointy:
 *   POST /_sent-messages/{id}/resend
 *   POST /_mail/sent/import
 *
 * Odeslat znovu (#90 D44): další průchod téže zprávy transportem — stejní
 * příjemci, stejné přílohy, žádná nová zpráva. Zkusí odeslat hned; selhání
 * transportu nechá zprávu ve frontě a odpověď to řekne stavem `queued`.
 *
 * Práva (D38): kdo smí tabulku Odeslané pošty (`guardTable`) a zdroj dat
 * není jen pro čtení (`ReadOnlyPolicy` — routa v ní výjimku nemá).
 * Chyby: 404 neznámá zpráva, 409 `IMPORTED` (zpráva ze starého systému,
 * #104 D5), 409 `INVALID_STATE` (archivovaná / smazaná), 409
 * `ALREADY_QUEUED` (už čeká ve frontě).
 *
 * Import (#104 D1): runner ze starého systému pod API klíčem posílá
 * `multipart/form-data` — pole `payload` (JSON objekt, kontrakt D3)
 * a soubory `attachments[]` v pořadí, ve kterém mají být u zprávy. Jedno
 * volání = zpráva i přílohy v jedné transakci (`SentMessageImportService`).
 * 201 `{id, created: true, attachments}`, 200 `{id, created: false}` pro
 * známý `import_ref`, 400 bez payloadu, 401 bez API klíče, 422 porušený
 * kontrakt (`details[{field, code}]`).
 */
class SentMessagesController
{
    public function __construct(
        private readonly SentMessageStore $store,
        private readonly SentMessageTransport $transport,
        private readonly SentMessageTransportInfo $info,
        private readonly ?SentMessageImportService $importer = null,
    ) {}

    /**
     * @param array<string, mixed> $form Pole `multipart/form-data` (`$_POST`).
     * @param list<array{name: string, tmp_name: string}> $files Soubory
     *        `attachments[]` v pořadí z požadavku (`MultipartFiles::collect`).
     */
    public function import(AuthContext $auth, array $form, array $files): Response
    {
        if (!$auth->isAuthenticated || $auth->tokenType !== 'api_key') {
            return Response::error('UNAUTHORIZED', 'API key required', 401);
        }
        if ($this->importer === null) {
            return Response::error('INTERNAL_ERROR', 'Sent message import is not available', 500);
        }

        $raw = $form['payload'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return Response::error('BAD_REQUEST', 'Missing multipart field payload (JSON object)', 400);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || array_is_list($payload)) {
            return Response::error('BAD_REQUEST', 'Field payload must be a JSON object', 400);
        }

        try {
            $result = $this->importer->import($payload, $files, $auth->userId);
        } catch (SentMessageImportException $e) {
            return Response::error(
                'VALIDATION_ERROR',
                $e->getMessage(),
                422,
                [['field' => $e->field, 'code' => $e->errorCode]],
            );
        }

        if (!$result->created) {
            return Response::success(['id' => $result->id, 'created' => false]);
        }
        return Response::success([
            'id'          => $result->id,
            'created'     => true,
            'attachments' => $result->attachments,
        ], 201);
    }

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
