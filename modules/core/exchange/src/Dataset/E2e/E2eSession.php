<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

use Shipard\Api\SessionService;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Přihlášení proklikání (tasks/testing-phase7a.md D92, I6): vyhrazený
 * uživatel `e2e` — administrátor, **systémový** (neukazuje se ve „Vystavil“,
 * `DocAuthorResolver` ho přeskočí jako CLI), bez hesla (`password_hash`
 * NULL — nelze se jím přihlásit jinak než session z tohoto příkazu);
 * `keepOnReset` uživatelů (D64) ho nechá přežít přeseedování. Existujícího
 * uživatele příkaz nepřepisuje: není-li administrátor, jen varuje.
 *
 * Session vzniká přes {@see SessionService} s krátkým TTL a token se zapíše
 * **jen** do souboru Playwright `storageState` (0600, `tempnam` + `rename`
 * v adresáři běhu) — ne na stdout, do argv, logu ani reportu. {@see close()}
 * je idempotentní a zneplatní **všechny** sessions uživatele (klient po 401
 * volá `/_auth/refresh` a token rotuje — jednotlivý token by nestačil) a
 * soubor smaže; {@see invalidateAll()} na začátku běhu uklidí session, která
 * přežila SIGKILL minulého běhu.
 */
class E2eSession
{
    public const string LOGIN = 'e2e';
    public const string FULL_NAME = 'Proklikání datových sad';
    public const string STORAGE_FILE = 'storage-state.json';
    public const string LANGUAGE = 'cs';

    /** @var array{id: int, login: string, full_name: string, is_admin: bool}|null */
    private ?array $user = null;
    private ?string $storagePath = null;
    private bool $closed = false;

    /**
     * @param string $fullName zobrazované jméno nově založeného uživatele (existující se nemění)
     * @param ?string $language `shpd_language` v `storageState` (`cs` / `en` / `auto` = podle `locale` prohlížeče); null = nezapisovat
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly SessionService $sessions,
        private readonly string $login = self::LOGIN,
        private readonly string $fullName = self::FULL_NAME,
        private readonly ?string $language = self::LANGUAGE,
    ) {}

    /**
     * Uživatel proklikání: založit, když chybí; existujícího nechat (varování,
     * když není administrátor), neaktivního odmítnout.
     *
     * @return array{id: int, login: string, full_name: string, is_admin: bool, created: bool, warnings: list<string>}
     * @throws E2eException uživatel existuje, ale je neaktivní
     */
    public function ensureUser(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id, login, full_name, is_admin, is_active, is_system FROM core_system_users WHERE login = %s',
            $this->login,
        );
        $warnings = [];
        if ($row === null) {
            $id = $this->db->insertRow('core_system_users', [
                'login'         => $this->login,
                'password_hash' => null,
                'full_name'     => $this->fullName,
                'email'         => null,
                'is_active'     => 1,
                'is_admin'      => 1,
                'is_system'     => 1,
            ]);
            $this->user = ['id' => $id, 'login' => $this->login, 'full_name' => $this->fullName, 'is_admin' => true];
            return $this->user + ['created' => true, 'warnings' => []];
        }
        if (!(bool) ($row['is_active'] ?? false)) {
            throw new E2eException("Uživatel '{$this->login}' je neaktivní — aktivuj ho nebo smaž, dataset-e2e ho založí znovu");
        }
        $isAdmin = (bool) ($row['is_admin'] ?? false);
        if (!$isAdmin) {
            $warnings[] = "Uživatel '{$this->login}' není administrátor — proklikání poběží s jeho právy (dataset-e2e existujícího uživatele nemění)";
        }
        if (!(bool) ($row['is_system'] ?? false)) {
            $warnings[] = "Uživatel '{$this->login}' není systémový — bude se nabízet ve „Vystavil“ (dataset-e2e existujícího uživatele nemění)";
        }
        $this->user = [
            'id'        => (int) $row['id'],
            'login'     => (string) $row['login'],
            'full_name' => (string) ($row['full_name'] ?? $this->login),
            'is_admin'  => $isAdmin,
        ];
        return $this->user + ['created' => false, 'warnings' => $warnings];
    }

    /** @return array{id: int, login: string, full_name: string, is_admin: bool}|null po {@see ensureUser()} */
    public function user(): ?array
    {
        return $this->user;
    }

    /** Zneplatní všechny sessions uživatele (úklid po SIGKILL minulého běhu). Vrací počet. */
    public function invalidateAll(): int
    {
        $user = $this->user ?? throw new \LogicException('ensureUser() must run first');
        return $this->sessions->invalidateAllForUser($user['id'], $this->db);
    }

    /**
     * Založí session a zapíše `storageState` do `$dir` (0600). Vrací cestu
     * k souboru; token zůstává jen v něm.
     *
     * @throws E2eException soubor nejde zapsat
     */
    public function open(string $origin, string $dir, int $ttlSeconds): string
    {
        $user = $this->user ?? throw new \LogicException('ensureUser() must run first');
        if ($this->storagePath !== null) {
            throw new \LogicException('session already open');
        }
        $dir = rtrim($dir, '/');
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new E2eException("Adresář běhu {$dir} neexistuje nebo nejde zapisovat");
        }
        $this->closed = false;
        [$token] = $this->sessions->createSession($user['id'], $this->db, null, $ttlSeconds);
        $target = $dir . '/' . self::STORAGE_FILE;
        $json = json_encode(self::storageState($origin, $token, $user, $this->language), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        unset($token);
        $tmp = @tempnam($dir, '.storage-');
        if ($tmp === false || $json === false) {
            $this->sessions->invalidateAllForUser($user['id'], $this->db);
            throw new E2eException("storageState nejde zapsat do {$dir}");
        }
        if (@file_put_contents($tmp, $json . "\n") === false || !@chmod($tmp, 0600) || !@rename($tmp, $target)) {
            @unlink($tmp);
            $this->sessions->invalidateAllForUser($user['id'], $this->db);
            throw new E2eException("storageState nejde zapsat: {$target}");
        }
        $this->storagePath = $target;
        return $target;
    }

    public function storagePath(): ?string
    {
        return $this->storagePath;
    }

    /** Zneplatní sessions uživatele a smaže `storageState`; opakované volání nic nedělá. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->storagePath !== null) {
            @unlink($this->storagePath);
            $this->storagePath = null;
        }
        if ($this->user !== null) {
            $this->sessions->invalidateAllForUser($this->user['id'], $this->db);
        }
    }

    /**
     * Obsah `storageState` pro Playwright: žádné cookies, `localStorage`
     * originu aplikace s tokenem (`shpd_token`), uživatelem (`shpd_user`,
     * tvar odpovědi `POST /_auth/login`) a jazykem rozhraní.
     *
     * @param array{id: int, login: string, full_name: string, is_admin: bool} $user
     * @param ?string $language jazyk rozhraní (`cs` / `en` / `auto`); null = klíč vynechat
     * @return array<string, mixed>
     */
    public static function storageState(string $origin, string $token, array $user, ?string $language = self::LANGUAGE): array
    {
        $profile = [
            'id'           => $user['id'],
            'login'        => $user['login'],
            'full_name'    => $user['full_name'],
            'is_admin'     => (bool) $user['is_admin'],
            'has_password' => false,
        ];
        $localStorage = [
            ['name' => 'shpd_token', 'value' => $token],
            ['name' => 'shpd_user', 'value' => (string) json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ];
        if ($language !== null) {
            $localStorage[] = ['name' => 'shpd_language', 'value' => $language];
        }
        return [
            'cookies' => [],
            'origins' => [[
                'origin' => rtrim($origin, '/'),
                'localStorage' => $localStorage,
            ]],
        ];
    }
}
