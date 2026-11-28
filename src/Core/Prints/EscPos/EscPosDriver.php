<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Definice ovladače účtenkové tiskárny (#177 U27, U28) — jediné místo, odkud
 * kodér bere bajty příkazů ESC/POS. Hodnoty jsou v cfgItem
 * `core.prints.printerDrivers`; třída je jen ověří a složí z nich příkazy.
 */
final class EscPosDriver
{
    /** Příkazy s pevnými bajty. */
    private const FIXED_COMMANDS = [
        'init', 'lineFeed', 'boldOn', 'boldOff', 'fontSmall', 'fontNormal',
        'alignLeft', 'alignCenter', 'alignRight', 'cut', 'openCashDrawer',
    ];

    /** Předpony, za které kodér doplní jeden bajt. */
    private const PREFIX_COMMANDS = ['size', 'feed'];

    private const QR_PARTS = ['prefix', 'model', 'moduleSize', 'errorCorrection', 'store', 'print'];

    public const ALIGNMENTS = ['left', 'center', 'right'];

    /** Největší násobek šířky a výšky znaku (`GS ! n`). */
    public const MAX_SIZE = 8;

    /**
     * @param array<string, string> $commands  příkaz → bajty
     * @param array<string, string> $codePages kódová stránka → bajty
     * @param array<string, string> $qr        část příkazu QR → bajty
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        private readonly array $commands,
        private readonly array $codePages,
        private readonly array $qr,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $id, array $data): self
    {
        $commands = [];
        $source   = is_array($data['commands'] ?? null) ? $data['commands'] : [];
        foreach ([...self::FIXED_COMMANDS, ...self::PREFIX_COMMANDS] as $command) {
            $commands[$command] = self::bytes($id, "commands.{$command}", $source[$command] ?? null);
        }

        $codePages = [];
        foreach (is_array($data['codePages'] ?? null) ? $data['codePages'] : [] as $page => $bytes) {
            $codePages[(string) $page] = self::bytes($id, "codePages.{$page}", $bytes);
        }
        if ($codePages === []) {
            throw new EscPosConfigException("Printer driver '{$id}': no code page defined");
        }

        $qr       = [];
        $qrSource = is_array($data['qr'] ?? null) ? $data['qr'] : [];
        foreach (self::QR_PARTS as $part) {
            $qr[$part] = self::bytes($id, "qr.{$part}", $qrSource[$part] ?? null);
        }

        $name = $data['name'] ?? null;

        return new self($id, is_string($name) && $name !== '' ? $name : $id, $commands, $codePages, $qr);
    }

    public function init(): string
    {
        return $this->commands['init'];
    }

    public function hasCodePage(string $codePage): bool
    {
        return isset($this->codePages[$codePage]);
    }

    public function codePage(string $codePage): string
    {
        return $this->codePages[$codePage]
            ?? throw new EscPosConfigException("Printer driver '{$this->id}': code page '{$codePage}' is not defined");
    }

    public function lineFeed(): string
    {
        return $this->commands['lineFeed'];
    }

    public function bold(bool $on): string
    {
        return $this->commands[$on ? 'boldOn' : 'boldOff'];
    }

    public function font(bool $small): string
    {
        return $this->commands[$small ? 'fontSmall' : 'fontNormal'];
    }

    /** Násobek šířky a výšky znaku, obojí 1–8. */
    public function size(int $width, int $height): string
    {
        if ($width < 1 || $width > self::MAX_SIZE || $height < 1 || $height > self::MAX_SIZE) {
            throw new \InvalidArgumentException("Character size {$width}x{$height} is out of range");
        }
        return $this->commands['size'] . chr((($width - 1) << 4) | ($height - 1));
    }

    public function align(string $align): string
    {
        return match ($align) {
            'left'   => $this->commands['alignLeft'],
            'center' => $this->commands['alignCenter'],
            'right'  => $this->commands['alignRight'],
            default  => throw new \InvalidArgumentException("Unknown alignment '{$align}'"),
        };
    }

    /** Vytiskne rozepsaný řádek a posune papír o `$lines` řádků. */
    public function feed(int $lines): string
    {
        if ($lines < 0 || $lines > 255) {
            throw new \InvalidArgumentException("Feed of {$lines} lines is out of range");
        }
        return $this->commands['feed'] . chr($lines);
    }

    public function cut(): string
    {
        return $this->commands['cut'];
    }

    public function openCashDrawer(): string
    {
        return $this->commands['openCashDrawer'];
    }

    /** QR kód: nastavení, uložení dat (UTF-8) a tisk. */
    public function qr(string $data): string
    {
        return $this->qrFunction($this->qr['model'])
            . $this->qrFunction($this->qr['moduleSize'])
            . $this->qrFunction($this->qr['errorCorrection'])
            . $this->qrFunction($this->qr['store'] . $data)
            . $this->qrFunction($this->qr['print']);
    }

    private function qrFunction(string $body): string
    {
        $length = strlen($body);
        if ($length > 0xFFFF) {
            throw new \InvalidArgumentException('QR code data are too long');
        }
        return $this->qr['prefix'] . chr($length & 0xFF) . chr($length >> 8) . $body;
    }

    private static function bytes(string $id, string $path, mixed $value): string
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new EscPosConfigException("Printer driver '{$id}': '{$path}' must be a non-empty list of bytes");
        }
        $bytes = '';
        foreach ($value as $byte) {
            if (!is_int($byte) || $byte < 0 || $byte > 255) {
                throw new EscPosConfigException("Printer driver '{$id}': '{$path}' must contain bytes 0-255");
            }
            $bytes .= chr($byte);
        }
        return $bytes;
    }
}
