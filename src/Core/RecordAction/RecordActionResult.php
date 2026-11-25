<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Výsledek provedené akce záznamu (#172 D14): zpráva pro uživatele
 * a úroveň. Frontend ji ukáže jako oznámení a vždy obnoví detail i seznam.
 */
final class RecordActionResult
{
    public const string SUCCESS = 'success';
    public const string INFO = 'info';
    public const string WARNING = 'warning';

    public const array LEVELS = [self::SUCCESS, self::INFO, self::WARNING];

    public function __construct(
        public readonly string $message,
        public readonly string $level = self::SUCCESS,
    ) {
        if (trim($message) === '') {
            throw new \InvalidArgumentException('Record action result needs a message');
        }
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(
                "Record action result level '{$level}' is not one of: " . implode(', ', self::LEVELS),
            );
        }
    }

    public static function success(string $message): self
    {
        return new self($message, self::SUCCESS);
    }

    public static function info(string $message): self
    {
        return new self($message, self::INFO);
    }

    public static function warning(string $message): self
    {
        return new self($message, self::WARNING);
    }

    /** @return array{message: string, level: string} */
    public function toArray(): array
    {
        return ['message' => $this->message, 'level' => $this->level];
    }
}
