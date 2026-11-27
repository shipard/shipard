<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\NestedTransaction;

/**
 * Hlášení periferií zařízení (#177 D23, D32, U19) — tělo podepsaného
 * `PUT /_devices/self/peripherals`, vždy **úplný seznam** v tvaru
 * `peripherals.list` (kontrakt docs/native-bridge.md §3.5).
 *
 * Periferie se párují podle `peripheral_id`: známá se aktualizuje, nová
 * založí a ta, která v hlášení chybí, dostane `present = 0` — nemaže se,
 * přiřazená role přežije, když tiskárna chvíli chybí. Sloupec `role` hlášení
 * nikdy nepřepisuje; roli přiřazuje administrátor v Shipardu.
 *
 * Neznámá hodnota výčtu (`kind`, `transport`, `status`) není chyba — novější
 * aplikace smí hlásit, co server ještě nezná (kontrakt §2.4). Hlídá se tvar:
 * typy, povinná pole a délky.
 */
class PeripheralSync
{
    /** Strop počtu periferií jednoho zařízení v hlášení. */
    public const int MAX_PERIPHERALS = 64;

    private const int ID_MAX_LENGTH = 64;
    private const int NAME_MAX_LENGTH = 100;
    private const int ENUM_MAX_LENGTH = 20;
    private const int PROFILE_MAX_LENGTH = 64;
    private const int READS_MAX_ITEMS = 8;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock čas pro testy */
    public function __construct(
        private readonly DataSourceConnection $db,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * @param int $deviceRowId ID řádku zařízení (z ověřeného podepsaného volání)
     * @param mixed $body tělo požadavku
     * @return int počet přijatých periferií
     *
     * @throws DeviceException `VALIDATION_ERROR` se všemi nálezy; nic se nezapíše
     */
    public function sync(int $deviceRowId, mixed $body): int
    {
        $reported = self::validated($body);
        $now = ($this->clock)();

        $this->transactional(function () use ($deviceRowId, $reported, $now): void {
            $existing = [];
            foreach ($this->db->fetchAll(
                'SELECT id, peripheral_id FROM core_devices_peripherals WHERE device = %i',
                $deviceRowId,
            ) as $row) {
                $existing[(string) $row['peripheral_id']] = (int) $row['id'];
            }

            foreach ($reported as $peripheralId => $columns) {
                $columns += ['present' => 1, 'reported_at' => $now];
                if (isset($existing[$peripheralId])) {
                    $this->db->updateWhere(PeripheralDocument::TABLE, $columns, 'id = %i', $existing[$peripheralId]);
                    unset($existing[$peripheralId]);
                } else {
                    $this->db->insertRow(
                        PeripheralDocument::TABLE,
                        ['device' => $deviceRowId, 'peripheral_id' => $peripheralId] + $columns,
                    );
                }
            }

            // Co v hlášení chybí, zůstává — jen jako nepřítomné (U19).
            foreach ($existing as $id) {
                $this->db->updateWhere(PeripheralDocument::TABLE, ['present' => 0], 'id = %i', $id);
            }
        });

        return count($reported);
    }

    /**
     * Celé hlášení v jedné transakci; uvnitř cizí transakce jako savepoint
     * (přepsatelné v testech bez databáze).
     *
     * @param callable(): void $work
     */
    protected function transactional(callable $work): void
    {
        NestedTransaction::run($this->db->getDibiConnection(), $work);
    }

    /**
     * @return array<string, array<string, mixed>> `peripheral_id` → sloupce k zápisu
     * @throws DeviceException
     */
    private static function validated(mixed $body): array
    {
        $list = is_array($body) ? ($body['peripherals'] ?? null) : null;
        if (!is_array($list) || !array_is_list($list)) {
            throw DeviceException::validation([
                ['field' => 'peripherals', 'message' => "Field 'peripherals' must be a list"],
            ]);
        }
        if (count($list) > self::MAX_PERIPHERALS) {
            throw DeviceException::validation([
                ['field' => 'peripherals', 'message' => "Field 'peripherals' accepts at most " . self::MAX_PERIPHERALS . ' items'],
            ]);
        }

        $errors = [];
        $result = [];
        foreach ($list as $index => $item) {
            $path = "peripherals.{$index}";
            $fail = static function (string $field, string $message) use (&$errors, $path): void {
                $errors[] = ['field' => "{$path}.{$field}", 'message' => $message];
            };
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                $errors[] = ['field' => $path, 'message' => 'Peripheral must be an object'];
                continue;
            }

            $id = self::text($item, 'id', self::ID_MAX_LENGTH, true, $fail);
            $kind = self::text($item, 'kind', self::ENUM_MAX_LENGTH, true, $fail);
            $name = self::text($item, 'name', self::NAME_MAX_LENGTH, true, $fail);
            $transport = self::text($item, 'transport', self::ENUM_MAX_LENGTH, false, $fail);
            $status = self::text($item, 'status', self::ENUM_MAX_LENGTH, false, $fail);
            $profileId = self::text($item, 'profileId', self::PROFILE_MAX_LENGTH, false, $fail);

            $reads = $item['reads'] ?? null;
            if ($reads !== null) {
                $valid = is_array($reads) && array_is_list($reads) && count($reads) <= self::READS_MAX_ITEMS;
                foreach ($valid ? $reads : [] as $read) {
                    $valid = $valid && is_string($read) && $read !== '' && strlen($read) <= self::ENUM_MAX_LENGTH;
                }
                if (!$valid) {
                    $fail('reads', "Field 'reads' must be a short list of texts");
                    $reads = null;
                }
            }

            if ($id !== null && isset($result[$id])) {
                $fail('id', "Peripheral id '{$id}' is reported twice");
            }
            if ($id === null) {
                continue;
            }
            $result[$id] = [
                'kind'       => $kind,
                'name'       => $name,
                'transport'  => $transport,
                'status'     => $status,
                'profile_id' => $profileId,
                'reads'      => $reads === null ? null : json_encode($reads, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        }

        if ($errors !== []) {
            throw DeviceException::validation($errors);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $item
     * @param \Closure(string, string): void $fail
     */
    private static function text(array $item, string $field, int $maxLength, bool $required, \Closure $fail): ?string
    {
        $value = $item[$field] ?? null;
        if ($value === null) {
            if ($required) {
                $fail($field, "Field '{$field}' is required");
            }
            return null;
        }
        if (!is_string($value) || $value === '' || mb_strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $fail($field, "Field '{$field}' must be a text of at most {$maxLength} characters");
            return null;
        }
        return $value;
    }
}
