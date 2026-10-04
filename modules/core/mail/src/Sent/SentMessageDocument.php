<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Odeslaná zpráva (#90 D41). Vzniká jen odesláním (`SentMessageStore`),
 * její obsah je pevný a fyzicky se nemaže — přes dokumentový lifecycle jde
 * měnit jen stav (Odeslaná ↔ V archivu / Smazaná).
 *
 * Read-only stavy zastaví změnu obsahu už ve formuláři a v generickém CRUD;
 * tady je pojistka pro každého dalšího volajícího gateway.
 */
class SentMessageDocument extends Document
{
    /** Sloupce, které smí uložení změnit. */
    private const MUTABLE_COLUMNS = ['id', 'docState', 'docStateMain', 'modified'];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Odeslaná zpráva vzniká jen odesláním záznamu — založit ji ručně nelze',
                'system_managed',
            );
            return $result;
        }

        $row = $this->db?->fetch('SELECT * FROM %n WHERE [id] = %i', SentMessageStore::TABLE, $id);
        if ($row === null) {
            return $result;
        }
        $original = $row->toArray();

        foreach ($data as $column => $value) {
            if (in_array($column, self::MUTABLE_COLUMNS, true) || !array_key_exists($column, $original)) {
                continue;
            }
            if (self::normalize($value) !== self::normalize($original[$column])) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'Obsah odeslané zprávy nelze měnit — zprávu lze jen archivovat nebo smazat',
                    'immutable',
                );
                break;
            }
        }

        return $result;
    }

    public function beforeDelete(array $data): void
    {
        throw new \LogicException('Sent messages are never deleted physically — use the Deleted state');
    }

    private static function normalize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return $value === null ? '' : (string) $value;
    }
}
