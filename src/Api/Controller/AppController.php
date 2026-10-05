<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\MailSafetyConfig;
use Shipard\Core\Settings\AvatarStorage;
use Shipard\Core\Settings\BrandingStorage;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Settings\UserSettingsStore;

/**
 * Veřejné info o aplikaci + branding obrázky.
 *
 * Endpoints:
 *   GET    /_app/info             Název, zkrácený název, ikona, logo (VEŘEJNÉ)
 *   GET    /_app/manifest         Web app manifest pro PWA instalaci (VEŘEJNÉ)
 *   GET    /_app/branding/{slot}  Binární obsah slotu (VEŘEJNÉ, immutable cache)
 *   POST   /_app/branding/{slot}  Upload (multipart, pole `file`) — vyžaduje auth
 *   DELETE /_app/branding/{slot}  Smazání souboru i metadat — vyžaduje auth
 *   GET    /_app/avatar           Avatar přihlášeného uživatele — vyžaduje auth
 *   POST   /_app/avatar           Upload avataru (multipart, downscale) — auth
 *   DELETE /_app/avatar           Smazání avataru přihlášeného uživatele — auth
 *
 * Branding GET endpointy jsou vědomě veřejné (login obrazovka, favicon bez
 * tokenu) — nesmí sem přibýt nic citlivého (DB jméno, moduly, uživatelé).
 * Z pojistky odchozí pošty proto `info` vystavuje jen režim, žádné adresy.
 * Avatar je naopak per-uživatel a celý za auth (i GET).
 */
class AppController
{
    /** Shipard paleta pro manifest — literály z frontend/src/styles/variables.css. */
    private const string MANIFEST_THEME_COLOR      = '#005089'; // --shpd-color-primary
    private const string MANIFEST_BACKGROUND_COLOR = '#ffffff'; // --shpd-color-bg

    private SettingsStore $settings;
    private BrandingStorage $storage;
    private AvatarStorage $avatars;

    /** @param array<string, \Shipard\Core\Database\TableDefinition> $tables */
    public function __construct(
        private DataSourceConnection $db,
        private DataSourceConfig $config,
        private array $tables = [],
        private ?MailSafetyConfig $mailSafety = null,
    ) {
        $this->settings = new SettingsStore($db);
        $this->storage  = new BrandingStorage($config->getDataSourceDir());
        $this->avatars  = new AvatarStorage($config->getDataSourceDir());
    }

    /**
     * GET /_app/info
     *
     * `app.name` má přednost před `main.json` name; shortName padá na name.
     *
     * `$dsState` je efektivní stav DS (#56 R5) — endpoint je veřejný, sem
     * dojde jen `active` nebo `read_only` (blokované stavy skončí 503 už
     * v resolveru), nic citlivého neprozrazuje.
     */
    public function info(string $dsState = DataSourceState::ACTIVE): Response
    {
        $values = $this->settings->getMany([
            'app.name',
            'app.shortName',
            'app.icon',
            'app.companyLogo',
            'app.theme',
            'app.shell',
        ]);

        [$name, $shortName] = $this->resolveNames($values);

        $policy = $this->config->getAuthPolicy();

        return Response::success([
            'name'        => $name,
            'shortName'   => $shortName,
            'icon'        => self::slotInfo('icon', $values['app.icon']),
            'companyLogo' => self::slotInfo('companyLogo', $values['app.companyLogo']),
            // DS-wide výchozí vzhled ({mode, custom} nebo null). Veřejné spolu
            // s brandingem — je to jen barva sidebaru, nic citlivého. Klient
            // z něj počítá efektivní vzhled pro follow-uživatele.
            'theme'       => is_array($values['app.theme']) ? $values['app.theme'] : null,
            // DS-wide výchozí shell ({shell, params} nebo null) — stejný
            // kontrakt jako theme, klient z něj počítá efektivní shell.
            'shell'       => is_array($values['app.shell']) ? $values['app.shell'] : null,
            // Stav DS pro SPA: `read_only` → banner + skrytý chat (fáze 2).
            'dsState'     => $dsState,
            // Pojistka odchozí pošty (#95 D7) — jen režim, SPA podle něj
            // upozorní, že pošta nejde skutečným příjemcům. Adresy
            // přesměrování ani povolené domény sem nepatří.
            'mailSafety'  => $this->mailSafety !== null ? ['mode' => $this->mailSafety->mode] : null,
            // Auth politika pro login obrazovku — jen id + label providerů,
            // nikdy clientId/secret/issuer.
            'auth'        => [
                'local'     => $policy->localLogin,
                'providers' => array_map(
                    static fn ($p) => ['id' => $p->id, 'label' => $p->label],
                    $policy->providers,
                ),
            ],
        ]);
    }

    /**
     * Název a zkrácený název aplikace z settings s fallbacky: `app.name` →
     * `main.json` name; `app.shortName` → name. Sdílí `info()` a `manifest()`.
     *
     * @param array<string, mixed> $values hodnoty z SettingsStore::getMany()
     * @return array{0: string, 1: string} [name, shortName]
     */
    private function resolveNames(array $values): array
    {
        $name = is_string($values['app.name'] ?? null) && trim($values['app.name']) !== ''
            ? $values['app.name']
            : $this->config->getName();

        $shortName = is_string($values['app.shortName'] ?? null) && trim($values['app.shortName']) !== ''
            ? $values['app.shortName']
            : $name;

        return [$name, $shortName];
    }

    /**
     * GET /_app/manifest — web app manifest (PWA, tasks/pwa-v1.md, #52).
     *
     * Manifest-only (bez service workeru). Per-DS jméno, ikony zatím statická
     * defaultní sada z buildu. `$devMode` = DS ID v cestě (`/{ds-id}/app/`),
     * prod bez prefixu (`/app/`) — rozhoduje DataSourceResolver, ne parsování
     * URL tady.
     *
     * Cesty k ikonám jsou záměrně absolutní: relativní by prohlížeč
     * resolvoval proti URL manifestu (`…/api/v1/_app/manifest`), ne proti
     * `scope`. `start_url`/`scope`/`id` musí být absolutní ze stejného důvodu.
     */
    public function manifest(bool $devMode): Response
    {
        $values = $this->settings->getMany(['app.name', 'app.shortName']);
        [$name, $shortName] = $this->resolveNames($values);

        $base = $devMode ? '/' . $this->config->getId() : '';
        $app  = $base . '/app/';

        $icon = static fn (string $file, string $size, ?string $purpose = null): array => array_filter([
            'src'     => $app . 'icons/' . $file,
            'sizes'   => $size,
            'type'    => 'image/png',
            'purpose' => $purpose,
        ]);

        $manifest = [
            'name'             => $name,
            'short_name'       => $shortName,
            'id'               => $app,
            'start_url'        => $app,
            'scope'            => $app,
            'display'          => 'standalone',
            'lang'             => $this->config->getDefaultLanguage(),
            'theme_color'      => self::MANIFEST_THEME_COLOR,
            'background_color' => self::MANIFEST_BACKGROUND_COLOR,
            'icons'            => [
                $icon('icon-192.png', '192x192'),
                $icon('icon-512.png', '512x512'),
                $icon('icon-maskable-192.png', '192x192', 'maskable'),
                $icon('icon-maskable-512.png', '512x512', 'maskable'),
            ],
        ];

        // Jméno se mění zřídka; prohlížeč manifest při návštěvách stejně
        // re-fetchuje, hodina cache tedy nic nerozbije.
        return Response::raw($manifest)
            ->withHeader('Content-Type', 'application/manifest+json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Veřejný stav slotu pro API odpovědi: `{url, hash}` nebo null.
     * URL je relativní k API base — frontend si prefix doplní sám.
     */
    public static function slotInfo(string $slot, mixed $metadata): ?array
    {
        if (!is_array($metadata) || !is_string($metadata['hash'] ?? null)) {
            return null;
        }
        return [
            'url'  => '/_app/branding/' . $slot . '?h=' . $metadata['hash'],
            'hash' => $metadata['hash'],
        ];
    }

    /**
     * GET /_app/branding/{slot} — binární obsah, immutable cache (URL nese
     * ?h={hash} pro cache-busting).
     */
    public function brandingGet(string $slot): Response
    {
        if (!BrandingStorage::isValidSlot($slot)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $metadata = $this->settings->get(BrandingStorage::SLOT_SETTINGS_KEYS[$slot]);
        if (!is_array($metadata) || !is_string($metadata['storedAs'] ?? null)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $filePath = $this->storage->getFilePath($metadata['storedAs']);
        if (!file_exists($filePath)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $mime = is_string($metadata['mime'] ?? null) ? $metadata['mime'] : 'application/octet-stream';
        $this->sendFile($filePath, $this->buildBrandingHeaders($mime, filesize($filePath) ?: null));

        // sendFile exits — jen pro typovou úplnost
        return Response::success(null, 204);
    }

    /**
     * Hlavičky pro servírování brandingu. SVG dostává CSP + nosniff —
     * ochrana proti XSS při přímé navigaci na URL. Public kvůli testům
     * (sendFile ukončuje proces).
     */
    public function buildBrandingHeaders(string $mime, ?int $size): array
    {
        $headers = [
            'Content-Type'  => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];
        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }
        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'";
            $headers['X-Content-Type-Options']  = 'nosniff';
        }
        return $headers;
    }

    /**
     * POST /_app/branding/{slot} — multipart upload (pole `file`).
     */
    public function brandingUpload(string $slot, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!BrandingStorage::isValidSlot($slot)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMessage = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor je příliš velký',
                UPLOAD_ERR_NO_FILE => 'Žádný soubor nebyl nahrán',
                UPLOAD_ERR_PARTIAL => 'Soubor byl nahrán jen částečně',
                default => 'Chyba při nahrávání souboru',
            };
            return Response::error('UPLOAD_ERROR', $errorMessage, 400);
        }

        $file    = $_FILES['file'];
        $tmpPath = (string) $file['tmp_name'];
        $size    = (int) ($file['size'] ?? 0);

        $validated = $this->storage->validateUpload($slot, $tmpPath, $size);
        if (is_string($validated)) {
            return Response::error('VALIDATION_ERROR', $validated, 422);
        }

        // Hash před store() — rename tmp soubor přesune.
        $hash     = substr((string) hash_file('sha256', $tmpPath), 0, 16);
        $storedAs = $this->storage->store($slot, $tmpPath, $validated['ext']);

        $metadata = [
            'filename' => (string) ($file['name'] ?? $storedAs),
            'storedAs' => $storedAs,
            'mime'     => $validated['mime'],
            'size'     => $size,
            'hash'     => $hash,
            'modified' => date('c'),
        ];
        $this->settings->set(BrandingStorage::SLOT_SETTINGS_KEYS[$slot], $metadata);

        return Response::success($metadata + ['url' => self::slotInfo($slot, $metadata)['url']], 201);
    }

    /**
     * DELETE /_app/branding/{slot} — smaže soubor i metadata.
     */
    public function brandingDelete(string $slot, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!BrandingStorage::isValidSlot($slot)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $this->storage->deleteSlotFiles($slot);
        $this->settings->delete(BrandingStorage::SLOT_SETTINGS_KEYS[$slot]);

        return Response::success(null, 204);
    }


    /**
     * GET /_app/avatar — binární obsah avataru PŘIHLÁŠENÉHO uživatele.
     * Vyžaduje auth (na rozdíl od brandingGet) — avatar je per-uživatel a není
     * veřejný. Uživatel se bere z tokenu, ne z URL (žádný {userId} parametr).
     */
    public function avatarGet(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $store    = new UserSettingsStore($this->db, $auth->userId);
        $metadata = $store->get(AvatarStorage::SETTINGS_KEY);
        if (!is_array($metadata) || !is_string($metadata['storedAs'] ?? null)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $filePath = $this->avatars->getFilePath($metadata['storedAs']);
        if (!file_exists($filePath)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $mime = is_string($metadata['mime'] ?? null) ? $metadata['mime'] : 'image/jpeg';
        // Avatar je per-uživatel — cache jen privátně, ne veřejně (na rozdíl od
        // brandingu). Cache-busting přes ?h={hash} v URL.
        $this->sendFile($filePath, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Length' => (string) (filesize($filePath) ?: 0),
        ]);

        return Response::success(null, 204);
    }

    /**
     * POST /_app/avatar — multipart upload (pole `file`) pro přihlášeného
     * uživatele. Obrázek se při uložení downscaluje na čtvercový avatar.
     */
    public function avatarUpload(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMessage = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor je příliš velký',
                UPLOAD_ERR_NO_FILE => 'Žádný soubor nebyl nahrán',
                UPLOAD_ERR_PARTIAL => 'Soubor byl nahrán jen částečně',
                default => 'Chyba při nahrávání souboru',
            };
            return Response::error('UPLOAD_ERROR', $errorMessage, 400);
        }

        $file    = $_FILES['file'];
        $tmpPath = (string) $file['tmp_name'];
        $size    = (int) ($file['size'] ?? 0);

        $validated = $this->avatars->validateUpload($tmpPath, $size);
        if (is_string($validated)) {
            return Response::error('VALIDATION_ERROR', $validated, 422);
        }

        $stored = $this->avatars->store($auth->userId, $tmpPath);
        // Hash z VÝSLEDNÉHO souboru (po downscale) — cache-buster musí odpovídat
        // tomu, co se reálně servíruje.
        $hash = substr((string) hash_file('sha256', $this->avatars->getFilePath($stored['storedAs'])), 0, 16);

        $metadata = [
            'filename' => (string) ($file['name'] ?? $stored['storedAs']),
            'storedAs' => $stored['storedAs'],
            'mime'     => $stored['mime'],
            'hash'     => $hash,
            'modified' => date('c'),
        ];
        $store = new UserSettingsStore($this->db, $auth->userId);
        $store->set(AvatarStorage::SETTINGS_KEY, $metadata);

        return Response::success($metadata + ['url' => self::avatarInfo($metadata)['url']], 201);
    }

    /**
     * DELETE /_app/avatar — smaže soubor i metadata přihlášeného uživatele.
     */
    public function avatarDelete(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $this->avatars->deleteUserFiles($auth->userId);
        $store = new UserSettingsStore($this->db, $auth->userId);
        $store->delete(AvatarStorage::SETTINGS_KEY);

        return Response::success(null, 204);
    }

    /**
     * Veřejný stav avataru pro API odpovědi: `{url, hash}` nebo null.
     * URL je relativní k API base; nenese {userId} — endpoint bere uživatele
     * z tokenu.
     */
    public static function avatarInfo(mixed $metadata): ?array
    {
        if (!is_array($metadata) || !is_string($metadata['hash'] ?? null)) {
            return null;
        }
        return [
            'url'  => '/_app/avatar?h=' . $metadata['hash'],
            'hash' => $metadata['hash'],
        ];
    }

    /** @param array<string, string> $headers */
    private function sendFile(string $filePath, array $headers): never
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        readfile($filePath);
        exit;
    }
}
