<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Base\Persons\Send\SendPurposes;

/**
 * Kontakt osoby. Hlídá účely odesílání (#90 D34): `send_purposes` je seznam
 * id z cfgItemu `base.persons.sendPurposes`, bez duplicit; prázdný výběr se
 * ukládá jako NULL — kontakt bez účelu se při odesílání nepoužije.
 */
class ContactDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (!array_key_exists('send_purposes', $data)) {
            return $result;
        }

        $purposes = SendPurposes::decode($data['send_purposes']);
        if ($purposes === null) {
            $result->addError('send_purposes', 'Účely odesílání musí být seznam id účelů', 'invalid');
            return $result;
        }
        if (count($purposes) !== count(array_unique($purposes))) {
            $result->addError('send_purposes', 'Účel odesílání smí být u kontaktu jen jednou', 'invalid');
        }

        // Bez zkompilované konfigurace není proti čemu validovat.
        $known = $this->config?->cfgItem(ConfigCompiler::SEND_PURPOSES_ITEM);
        if (is_array($known)) {
            foreach ($purposes as $purpose) {
                if (!array_key_exists($purpose, $known)) {
                    $result->addError(
                        'send_purposes',
                        sprintf('Neznámý účel odesílání "%s"', $purpose),
                        'invalid',
                    );
                }
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        // JSON sloupce nemají auto-serializaci — encode tady.
        if (array_key_exists('send_purposes', $data)) {
            $data['send_purposes'] = SendPurposes::encode(SendPurposes::decode($data['send_purposes']) ?? []);
        }
    }
}
