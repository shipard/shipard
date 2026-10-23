<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval\Browse;

use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;

/**
 * Dočasný kořen repa pro testy katalogu a prohlížeče evaluací (#103 D106):
 * manifesty sad `tests/datasets/<země>/<sada>/manifest.jsonc` a běhy
 * `tests/eval/<sada>/<datum>-<varianta>.json` v minimálním tvaru, který
 * `EvalCatalog`, `EvalRunStore` a `DevTestingController` čtou. Test tak
 * netvrdí nic o skutečném `tests/eval/` v checkoutu — commit nového běhu
 * evaluace ho nerozbije; realistický běh čte test jen pojmenovaný.
 */
final class EvalFixtureRoot
{
    public const string PAYER = 'cz-payer';
    public const string ASSOCIATION = 'cz-assoc';

    private function __construct(public readonly string $root) {}

    /** Kořen s prázdnými `tests/datasets` a `tests/eval` — oba, aby platilo `EvalCatalog::isAvailable()`. */
    public static function create(): self
    {
        $root = sys_get_temp_dir() . '/shpd-evalfix-' . bin2hex(random_bytes(4));
        mkdir($root . '/tests/datasets', 0700, true);
        mkdir($root . '/tests/eval', 0700, true);
        return new self($root);
    }

    public function remove(): void
    {
        DatasetReader::removeTree($this->root);
    }

    /**
     * Manifest sady v `<relDir>/manifest.jsonc` — `tests/datasets/cz/payer`
     * i demo sada `demo/datasets/it-servis`; `$extra` doplní nebo přepíše
     * pole (profil, počty).
     *
     * @param array<string, mixed> $extra
     * @return string absolutní adresář sady
     */
    public function manifest(string $relDir, string $name, string $title, array $extra = []): string
    {
        $dir = $this->root . '/' . $relDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/manifest.jsonc', json_encode($extra + [
            'format' => 'shpd.dataset.v1', 'name' => $name, 'title' => $title, 'dateMode' => 'fixed',
            'created' => '2026-10-20T00:00:00Z', 'country' => 'cz', 'counts' => ['mail' => 3],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        return $dir;
    }

    /**
     * Běh `tests/eval/<set>/<basename>` jen s poli, která katalog a prohlížeč
     * čtou; `$extra` doplní nebo přepíše (metriky, `calls`, `git`).
     *
     * @param array<string, mixed> $extra
     * @return string absolutní cesta souboru
     */
    public function run(string $set, string $basename, string $startedAt, string $variant = 'baseline', string $hash = 'fixture-hash', array $extra = []): string
    {
        $dir = EvalRunStore::defaultDir($this->root, $set);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir . '/' . $basename;
        file_put_contents($path, json_encode($extra + [
            'format' => EvalRunStore::FORMAT,
            'set' => ['name' => $set, 'hash' => $hash, 'messages' => 3],
            'variant' => ['name' => $variant],
            'startedAt' => $startedAt,
            'repeat' => 1,
            'metrics' => ['calls.total' => 3, 'calls.ok' => 3, 'calls.failed' => 0, 'cost.usd' => 0.03],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        return $path;
    }

    /** Hash obsahu sady nad fixturou — totéž, co počítá `EvalCatalog::currentHash()`. */
    public function currentHash(string $datasetDir): string
    {
        return EvalRunStore::datasetHash(DatasetReader::open($datasetDir));
    }

    /**
     * Sestava společná testům katalogu i API prohlížeče: sady `cz-payer`
     * (profil `vat-payer`) a `cz-assoc` (bez složky výsledků), tři běhy
     * plátce — `2026-10-15-baseline` nad starší verzí sady, `2026-10-16-alpha`
     * (začal dřív, abecedně dřív) a `2026-10-16-baseline` (nejnovější) nad
     * aktuální sadou.
     *
     * @return string hash aktuální sady plátce
     */
    public function seedPayerAndAssociation(): string
    {
        $payerDir = $this->manifest('tests/datasets/cz/payer', self::PAYER, 'Plátce', ['profile' => 'vat-payer']);
        $this->manifest('tests/datasets/cz/association', self::ASSOCIATION, 'Spolek', ['profile' => 'association']);
        $hash = $this->currentHash($payerDir);
        $this->run(self::PAYER, '2026-10-15-baseline.json', '2026-10-15T10:00:00+00:00', 'baseline', 'old-hash');
        $this->run(self::PAYER, '2026-10-16-alpha.json', '2026-10-16T08:00:00+00:00', 'alpha', $hash);
        $this->run(self::PAYER, '2026-10-16-baseline.json', '2026-10-16T10:00:00+00:00', 'baseline', $hash);
        return $hash;
    }
}
