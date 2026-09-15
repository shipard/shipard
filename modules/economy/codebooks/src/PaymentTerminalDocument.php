<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Platební terminál / brána (economy_codebooks_payment_terminals, #72 D4).
 *
 * - `kind` 0 terminál patří jedné pokladně (`cash_desk` povinná), 1 brána
 *   pokladnu nemá (`cash_desk` prázdná).
 * - `partner` je Osoba pro saldokonto — protistrana pohledávky 311 z
 *   dokladu placeného kartou / bránou; ve stavu V pořádku povinná, číselník
 *   bez protistrany nedává smysl.
 * - `is_default` je výlučné v rámci pokladny (terminál) resp. mezi bránami
 *   (brána) — stejný mechanismus jako `CashDeskDocument` per měna.
 */
class PaymentTerminalDocument extends Document
{
    public const KIND_TERMINAL = 0;
    public const KIND_GATEWAY = 1;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['code'])) {
            $result->addError('code', 'Kód je povinný', 'required');
        }
        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $kind = (int) ($data['kind'] ?? self::KIND_TERMINAL);
        if (!in_array($kind, [self::KIND_TERMINAL, self::KIND_GATEWAY], true)) {
            $result->addError('kind', 'Neplatný druh — terminál nebo brána.', 'invalid_value');
        }

        $hasCashDesk = !empty($data['cash_desk']);
        if ($kind === self::KIND_TERMINAL && !$hasCashDesk) {
            $result->addError('cash_desk', 'Terminál patří pokladně — vyberte ji.', 'cash_desk_required');
        }
        if ($kind === self::KIND_GATEWAY && $hasCashDesk) {
            $result->addError('cash_desk', 'Platební brána pokladnu nemá — pole nechte prázdné.', 'cash_desk_not_allowed');
        }

        // Klientský filtr lookupu není bezpečnostní hranice; existence
        // pokladny se vynucuje tady (vzor CashDeskDocument / accounting_account).
        if ($hasCashDesk && $this->db !== null) {
            $row = $this->db->fetch(
                'SELECT id FROM economy_codebooks_cash_desks WHERE id = %i AND docState IN (10, 40, 80)',
                (int) $data['cash_desk'],
            );
            if ($row === null || $row === false) {
                $result->addError('cash_desk', 'Pokladna neexistuje nebo je archivovaná.', 'invalid');
            }
        }

        $state = (int) ($data['docState'] ?? 10);
        if (in_array($state, [40, 80], true) && empty($data['partner'])) {
            $result->addError(
                'partner',
                'Terminál / brána bez osoby pro saldokonto nemá smysl — vyberte protistranu, za kterou vzniká pohledávka.',
                'partner_required',
            );
        }

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']
        ) {
            $result->addError('valid_to', 'Platnost do nesmí být dříve než platnost od.', 'invalid_range');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['code', 'name', 'notice'] as $col) {
            if (isset($data[$col]) && $data[$col] !== null) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
        if ((int) ($data['kind'] ?? self::KIND_TERMINAL) === self::KIND_GATEWAY) {
            $data['cash_desk'] = null;
        }
    }

    public function afterPersist(array $data): void
    {
        if (empty($data['is_default']) || empty($data['id'])) {
            return;
        }

        $kind = (int) ($data['kind'] ?? self::KIND_TERMINAL);
        $cashDesk = !empty($data['cash_desk']) ? (int) $data['cash_desk'] : null;
        if ($kind === self::KIND_TERMINAL && $cashDesk === null) {
            return;
        }

        $this->clearOtherDefaults($kind, $cashDesk, (int) $data['id']);
    }

    /**
     * Odznačí ostatní defaulty ve stejném rozsahu: terminály téže pokladny,
     * resp. všechny ostatní brány (`cash_desk` NULL).
     */
    protected function clearOtherDefaults(int $kind, ?int $cashDesk, int $id): void
    {
        if ($this->db === null) {
            return;
        }

        $this->db->query(
            'UPDATE [economy_codebooks_payment_terminals] SET [is_default] = 0
             WHERE [kind] = %i AND [cash_desk] <=> %iN AND [is_default] = 1 AND [id] != %i',
            $kind,
            $cashDesk,
            $id,
        );
    }
}
