<?php

declare(strict_types=1);

namespace Shipard\Core\Settings;

use Shipard\Core\Server\PermissionSpec;

/**
 * Branding obrázky se single-slot sémantikou — soubory v `{dsPath}/branding/`
 * uložené jako `{slot}.{ext}`. Metadata (původní jméno, mime, hash, …) drží
 * SettingsStore pod klíčem slotu (viz SLOT_SETTINGS_KEYS); tahle třída řeší
 * jen validaci a práci se soubory. `ds-reset` se adresáře nedotýká, branding
 * tedy přežívá reset bez další práce.
 *
 * Bez závislosti na HTTP — logo čte i generátor sestav / CLI.
 */
class BrandingStorage
{
    public const array SLOTS = ['icon', 'companyLogo'];

    /** Klíč v core_system_settings, pod kterým žijí metadata slotu. */
    public const array SLOT_SETTINGS_KEYS = [
        'icon'        => 'app.icon',
        'companyLogo' => 'app.companyLogo',
    ];

    public const int MAX_FILE_SIZE = 2 * 1024 * 1024;

    private const array MIME_EXTENSIONS = [
        'image/png'                => 'png',
        'image/jpeg'               => 'jpg',
        'image/webp'               => 'webp',
        'image/svg+xml'            => 'svg',
        'image/x-icon'             => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    /** ICO má smysl jen jako favicon — pro companyLogo (sestavy) ho nepovolujeme. */
    private const array ICON_ONLY_MIMES = ['image/x-icon', 'image/vnd.microsoft.icon'];

    public function __construct(private readonly string $dsPath)
    {
    }

    public static function isValidSlot(string $slot): bool
    {
        return in_array($slot, self::SLOTS, true);
    }

    public function getDir(): string
    {
        return $this->dsPath . '/branding';
    }

    public function getFilePath(string $storedAs): string
    {
        return $this->getDir() . '/' . $storedAs;
    }

    /**
     * Detekce mime z obsahu (finfo). SVG bez XML deklarace finfo hlásí jako
     * text/html či text/plain — fallback kontrola začátku obsahu.
     */
    public function detectMime(string $filePath): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($filePath);
        $mime  = is_string($mime) ? strtolower(explode(';', $mime)[0]) : '';

        if (isset(self::MIME_EXTENSIONS[$mime])) {
            return $mime;
        }

        $head    = (string) file_get_contents($filePath, length: 1024);
        $trimmed = ltrim($head, "\xEF\xBB\xBF \t\r\n");
        if (str_starts_with($trimmed, '<svg')
            || (str_starts_with($trimmed, '<?xml') && str_contains($head, '<svg'))) {
            return 'image/svg+xml';
        }

        return null;
    }

    /**
     * Validace nahrávaného souboru pro daný slot.
     *
     * @return array{mime: string, ext: string}|string metadata při úspěchu, chybová hláška při selhání
     */
    public function validateUpload(string $slot, string $tmpPath, int $size): array|string
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
            return 'Nepodporovaný typ souboru — povolené jsou PNG, JPEG, WebP a SVG';
        }
        if ($slot !== 'icon' && in_array($mime, self::ICON_ONLY_MIMES, true)) {
            return 'Formát ICO je povolen jen pro ikonu aplikace';
        }

        return ['mime' => $mime, 'ext' => self::MIME_EXTENSIONS[$mime]];
    }

    /**
     * Uloží soubor slotu jako `branding/{slot}.{ext}`. Předchozí soubor slotu
     * (i s jinou příponou) smaže — slot drží vždy nejvýš jeden soubor.
     *
     * @return string storedAs (jméno souboru v branding/)
     */
    public function store(string $slot, string $tmpPath, string $ext): string
    {
        $dir = $this->getDir();
        if (!PermissionSpec::ensureDsDir($dir)) {
            throw new \RuntimeException("Cannot create branding directory: {$dir}");
        }

        $this->deleteSlotFiles($slot);

        $storedAs   = $slot . '.' . $ext;
        $targetPath = $dir . '/' . $storedAs;

        // rename místo move_uploaded_file — stejný vzor jako FileStorage
        // (testovatelnost; tmp soubor už prošel validací obsahu).
        if (!@rename($tmpPath, $targetPath)) {
            if (!@copy($tmpPath, $targetPath)) {
                throw new \RuntimeException("Failed to store branding file: {$targetPath}");
            }
            @unlink($tmpPath);
        }

        return $storedAs;
    }

    /**
     * Jméno uloženého souboru slotu (`{slot}.{ext}`), null když slot nic
     * nedrží. Čte přímo adresář — pro konzumenty bez SettingsStore (tisky).
     */
    public function findSlotFile(string $slot): ?string
    {
        if (!self::isValidSlot($slot)) {
            return null;
        }
        $files = glob($this->getDir() . '/' . $slot . '.*') ?: [];
        return $files === [] ? null : basename($files[0]);
    }

    /** Smaže všechny soubory slotu (`{slot}.*`). */
    public function deleteSlotFiles(string $slot): void
    {
        foreach (glob($this->getDir() . '/' . $slot . '.*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
