<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices\Actions;

use Shipard\Core\I18n\CatalogText;
use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DeviceRegistrationService;
use Shipard\Module\Core\Devices\DevicesViewer;

/**
 * Akce **Zneplatnit** na aktivním zařízení (#177 U18): ztracené nebo
 * vyřazené zařízení přestane se zdrojem dat pracovat. Jen s potvrzením
 * a jen pro administrátora (`admin` v deklaraci). Zneplatnění je konečné —
 * provádí ho `DeviceRegistrationService::revoke()`, stejně jako příkaz
 * `device-revoke`.
 */
class RevokeDeviceAction implements RecordAction
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        return (int) ($record['docState'] ?? 0) === DeviceDocument::STATE_ACTIVE;
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        return new RecordActionDescriptor(
            self::text($context, 'revoke.label', 'Revoke'),
            RecordActionDescriptor::DANGER,
            confirm: self::text(
                $context,
                'revoke.confirm',
                'The device "{name}" will stop working with this data source and the revocation cannot be undone — the device has to be registered again as a new one. Continue?',
                ['name' => (string) ($record['name'] ?? '')],
            ),
        );
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        $revoked = (new DeviceRegistrationService($context->db, $context->config))
            ->revoke((int) ($record['id'] ?? 0), $context->userId);
        if (!$revoked) {
            // Mezi vykreslením detailu a potvrzením ho zneplatnil někdo jiný.
            throw RecordActionValidationException::form(
                self::text($context, 'revoke.gone', 'The device is no longer active.'),
            );
        }
        return RecordActionResult::success(self::text($context, 'revoke.done', 'The device has been revoked.'));
    }

    /** @param array<string, scalar> $params */
    private static function text(RecordActionContext $context, string $key, string $fallback, array $params = []): string
    {
        return CatalogText::resolve($context->config?->cfgItem(DevicesViewer::LABELS_CFG_ITEM), $key, $fallback, $params);
    }
}
