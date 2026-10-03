<?php

declare(strict_types=1);

namespace Shipard\Core\Settings;

use Shipard\Core\Server\PermissionSpec;

/**
 * Per-uživatelský avatar se single-slot sémantikou — soubory v
 * `{dsPath}/branding/avatars/` uložené jako `{userId}.{ext}`. Metadata
 * (původní jméno, mime, hash, …) drží {@see UserSettingsStore} pod klíčem
 * `account.avatar` (per-user, scope `user`); tahle třída řeší jen validaci,
 * downscale a práci se soubory.
 *
 * Model je záměrně symetrický k {@see BrandingStorage} (jeden soubor na slot,
 * nový upload smaže starý i s jinou příponou), ale klíčuje na `userId` místo
 * pojmenovaného slotu. Avatary žijí ve stejném `branding/` stromu, který
 * `ds-reset` nemaže → avatar přežívá reset, stejně jako branding.
 *
 * Na rozdíl od brandingu je avatar per-uživatel a servíruje se jen za auth
 * (žádný veřejný GET) — viz AppController::avatarGet().
 *
 * Bez závislosti na HTTP.
 */
class AvatarStorage
{
    public const int MAX_FILE_SIZE = 2 * 1024 * 1024;

    /** Klíč v core_system_user_settings, pod kterým žijí metadata avataru. */
    public const string SETTINGS_KEY = 'account.avatar';

    /** Cílová hrana downscalovaného avataru (px). Avatar v sidebaru je ~28px,
     *  256 dává rezervu pro retina i případné větší použití (detail uživatele). */
    public const int TARGET_SIZE = 256;

    private const array MIME_EXTENSIONS = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $dsPath)
    {
    }

    public function getDir(): string
    {
        return $this->dsPath . '/branding/avatars';
    }

    public function getFilePath(string $storedAs): string
    {
        return $this->getDir() . '/' . $storedAs;
    }

    /**
     * Detekce mime z obsahu (finfo). Avatar je vždy rastr (po downscale JPEG/
     * PNG/WebP) — SVG záměrně nepovolujeme (avatar je fotka, ne vektor, a
     * vyhneme se tím XSS ploše veřejného-ish obrázku).
     */
    public function detectMime(string $filePath): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($filePath);
        $mime  = is_string($mime) ? strtolower(explode(';', $mime)[0]) : '';

        return isset(self::MIME_EXTENSIONS[$mime]) ? $mime : null;
    }

    /**
     * Validace nahrávaného souboru.
     *
     * @return array{mime: string, ext: string}|string metadata při úspěchu, chybová hláška při selhání
     */
    public function validateUpload(string $tmpPath, int $size): array|string
    {
        if ($size > self::MAX_FILE_SIZE) {
            $maxMb = self::MAX_FILE_SIZE / (1024 * 1024);
            return "Soubor je příliš velký (max {$maxMb} MB)";
        }
        if ($size <= 0 || !is_file($tmpPath)) {
            return 'Soubor je prázdný nebo se nepodařilo nahrát';
        }

        $mime = $this->detectMime($tmpPath);
        if ($mime === null) {
            return 'Nepodporovaný typ souboru — povolené jsou PNG, JPEG a WebP';
        }

        return ['mime' => $mime, 'ext' => self::MIME_EXTENSIONS[$mime]];
    }

    /**
     * Downscale na čtvercový avatar a uloží jako `avatars/{userId}.jpg`.
     * Předchozí soubor uživatele (i s jinou příponou) smaže — slot drží vždy
     * nejvýš jeden soubor.
     *
     * Výstup je vždy JPEG (downscalovaný přes vipsthumbnail, stejný nástroj
     * jako u příloh). Crop na čtverec přes --smartcrop attention, aby avatar
     * neměl divné poměry stran v kolečku sidebaru.
     *
     * @return array{storedAs: string, mime: string} jméno souboru + výsledné mime
     */
    public function store(int $userId, string $tmpPath): array
    {
        $dir = $this->getDir();
        if (!PermissionSpec::ensureDsDir($dir)) {
            throw new \RuntimeException("Cannot create avatars directory: {$dir}");
        }

        $this->deleteUserFiles($userId);

        $storedAs   = $userId . '.jpg';
        $targetPath = $dir . '/' . $storedAs;

        // Downscale + čtvercový smartcrop přes libvips (vipsthumbnail) — stejný
        // nástroj jako ThumbnailGenerator u příloh. Selhání = fallback na prostou
        // kopii originálu (avatar se zobrazí, jen nebude downscalovaný).
        $cmd = sprintf(
            'vipsthumbnail %s --size=%dx%d --smartcrop attention -o %s[Q=88] 2>/dev/null',
            escapeshellarg($tmpPath),
            self::TARGET_SIZE,
            self::TARGET_SIZE,
            escapeshellarg($targetPath),
        );
        exec($cmd, $out, $exitCode);

        if ($exitCode !== 0 || !file_exists($targetPath)) {
            // Fallback — ulož originál tak jak je (validace mime už proběhla).
            if (!@copy($tmpPath, $targetPath)) {
                throw new \RuntimeException("Failed to store avatar file: {$targetPath}");
            }
        }
        @unlink($tmpPath);

        return ['storedAs' => $storedAs, 'mime' => 'image/jpeg'];
    }

    /** Smaže všechny soubory avataru uživatele (`{userId}.*`). */
    public function deleteUserFiles(int $userId): void
    {
        foreach (glob($this->getDir() . '/' . $userId . '.*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
