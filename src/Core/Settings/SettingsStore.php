<?php

declare(strict_types=1);

namespace Shipard\Core\Settings;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Tenká služba nad key-value tabulkou `core_system_settings`.
 *
 * Hodnoty se ukládají jako JSON (sloupec `value`), klíče používají tečkové
 * namespacy (`app.name`, `app.icon`, …). Konstruktor bere jen DB připojení,
 * takže je volatelná z HTTP kontextu i z CLI (sestavy, generátor faktur).
 *
 * Request-level cache: každý přečtený/zapsaný klíč se drží v privátním poli,
 * opakované get() v rámci jednoho requestu nejdou do DB.
 */
class SettingsStore implements KeyValueStore
{
    public const string TABLE = 'core_system_settings';

    /** @var array<string, mixed> cache klíč → dekódovaná hodnota (vč. null pro "neexistuje") */
    private array $cache = [];

    public function __construct(private readonly DataSourceConnection $db)
    {
    }

    /** Vrátí dekódovanou hodnotu klíče, nebo null pokud klíč neexistuje. */
    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $raw = $this->db->fetchSingle(
            'SELECT `value` FROM `' . self::TABLE . '` WHERE `key` = %s',
            $key,
        );

        $value = is_string($raw) ? json_decode($raw, true) : null;
        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Načte více klíčů jedním dotazem.
     *
     * @param  string[] $keys
     * @return array<string, mixed> mapa key → value; neexistující klíče mají null
     */
    public function getMany(array $keys): array
    {
        $result  = [];
        $missing = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->cache)) {
                $result[$key] = $this->cache[$key];
            } else {
                $missing[]    = $key;
                $result[$key] = null;
            }
        }

        if ($missing !== []) {
            $rows = $this->db->fetchAll(
                'SELECT `key`, `value` FROM `' . self::TABLE . '` WHERE `key` IN %in',
                $missing,
            );
            foreach ($rows as $row) {
                $value = is_string($row['value']) ? json_decode($row['value'], true) : null;
                $result[$row['key']]       = $value;
                $this->cache[$row['key']]  = $value;
            }
            foreach ($missing as $key) {
                if (!array_key_exists($key, $this->cache)) {
                    $this->cache[$key] = null;
                }
            }
        }

        return $result;
    }

    /**
     * Upsert hodnoty. `set($key, null)` klíč smaže — null a "neexistuje"
     * jsou pro čtenáře nerozlišitelné, takže by řádek jen zabíral místo.
     */
    public function set(string $key, mixed $value): void
    {
        if ($value === null) {
            $this->delete($key);
            return;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->db->execute(
            'INSERT INTO `' . self::TABLE . '` (`key`, `value`, `modified`) VALUES (%s, %s, NOW())'
            . ' ON DUPLICATE KEY UPDATE `value` = %s, `modified` = NOW()',
            $key,
            $json,
            $json,
        );

        $this->cache[$key] = $value;
    }

    public function delete(string $key): void
    {
        $this->db->deleteWhere(self::TABLE, '`key` = %s', $key);
        $this->cache[$key] = null;
    }
}
