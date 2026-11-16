<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Utils\JsoncParser;

/**
 * Nabídka účetních položek (accountingItemsDefault/Npo.jsonc) — sdílená
 * čtečka pro SetupController (generátor položek), materializaci položky
 * ze štítku a přehled štítků v Nastavení. Varianta osnovy se čte ze
 * settings klíče economy.accountChart.
 *
 * Nabídka je kurátorovaná: při více položkách se stejným štítkem vyhrává
 * první v pořadí souboru.
 */
class AccountingItemsOffer
{
    /** @var array<string, array{groups: list<array<string, mixed>>, items: array<string, array<string, mixed>>}|null> */
    private array $seedCache = [];

    private ?string $variantCache = null;
    private bool $variantLoaded = false;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ModulePathResolver $modulePathResolver = null,
    ) {
    }

    /** Hodnota economy.accountChart, nebo null = nerozhodnuto. */
    public function variant(): ?string
    {
        if (!$this->variantLoaded) {
            $variant = (new SettingsStore($this->db))->get('economy.accountChart');
            $this->variantCache  = is_string($variant) && $variant !== '' ? $variant : null;
            $this->variantLoaded = true;
        }
        return $this->variantCache;
    }

    /**
     * Seed sady podle varianty osnovy — tvar {groups, items} (Task 11),
     * items klíčované kódem položky. Dva soubory, ne jeden s filtrem —
     * obě osnovy používají stejná čísla pro jiné účty.
     *
     * @return array{groups: list<array<string, mixed>>, items: array<string, array<string, mixed>>}|null
     *         null = neznámá varianta / soubor chybí / nečitelný / bez items
     */
    public function loadSeed(string $variant): ?array
    {
        if (array_key_exists($variant, $this->seedCache)) {
            return $this->seedCache[$variant];
        }
        return $this->seedCache[$variant] = $this->loadSeedFresh($variant);
    }

    /**
     * Reverzní mapa **číslo účtu → štítky** z nabídky dané varianty osnovy
     * (bez varianty = aktivní). Jeden účet může nést víc štítků napříč
     * položkami nabídky — kolize řeší volající (settings obrazovka je
     * ukazuje jako `candidateTags`, booking-history reverz je vyhodí jako
     * nejednoznačné).
     *
     * Sdílená mezi reverzními návrhy v Nastavení (D15/D27) a reverzem nad
     * souborem účetní historie (`AccountTagMap`) — jediné místo, které ví,
     * jak se z nabídky čte opačný směr než {@see defaultAccountForTag()}.
     *
     * @return array<string, list<string>> prázdné = neznámá varianta / soubor chybí
     */
    public function tagsByAccount(?string $variant = null): array
    {
        $variant ??= $this->variant();
        $seed = $variant !== null ? $this->loadSeed($variant) : null;
        if ($seed === null) {
            return [];
        }

        $byAccount = [];
        foreach ($seed['items'] as $entry) {
            $account = (string) ($entry['account'] ?? '');
            if ($account === '') {
                continue;
            }
            foreach ((array) ($entry['contentTags'] ?? []) as $tag) {
                if (is_string($tag) && $tag !== '' && !in_array($tag, $byAccount[$account] ?? [], true)) {
                    $byAccount[$account][] = $tag;
                }
            }
        }
        return $byAccount;
    }

    /**
     * Jako {@see tagsByAccount()}, ale s **názvy položek nabídky** per štítek:
     * `účet → štítek → názvy`. Vrací všechny jazykové varianty názvu
     * (`name`, `name:cs`, `name:en`) — konzument porovnává proti maximu, aby
     * výsledek nezávisel na jazyku DS.
     *
     * Slouží sanity checku reverzu při neznámé osnově (D36,
     * `AccountTagMap`): cizí systém vede pod `518202` finanční leasing,
     * nabídka tam má internetové připojení — bez porovnání názvů by přesná
     * shoda čísla vyrobila falešný štítek.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function namesByAccountTag(?string $variant = null): array
    {
        $variant ??= $this->variant();
        $seed = $variant !== null ? $this->loadSeed($variant) : null;
        if ($seed === null) {
            return [];
        }

        $out = [];
        foreach ($seed['items'] as $entry) {
            $account = (string) ($entry['account'] ?? '');
            if ($account === '') {
                continue;
            }
            $names = [];
            foreach (['name', 'name:cs', 'name:en'] as $key) {
                $name = trim((string) ($entry[$key] ?? ''));
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
            if ($names === []) {
                continue;
            }
            foreach ((array) ($entry['contentTags'] ?? []) as $tag) {
                if (!is_string($tag) || $tag === '') {
                    continue;
                }
                foreach ($names as $name) {
                    if (!in_array($name, $out[$account][$tag] ?? [], true)) {
                        $out[$account][$tag][] = $name;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Výchozí účet pro obsahový štítek z nabídky aktivní varianty osnovy —
     * číslo účtu první položky nabídky nesoucí přesně tento štítek; když
     * žádná není, prefix fallback (D3): první položka se štítkem stejné
     * skupiny (`vehicle.consumables` → `vehicle.*`). null = štítek nemá
     * v nabídce mapování (vědomé review, např. admin.other, goods.stock).
     *
     * Čte ho přehled štítků v Nastavení (stav „výchozí účet z nabídky“);
     * na řádek vytěženého dokladu se účet odtud nenavrhuje (#36 D3).
     */
    public function defaultAccountForTag(string $tag): ?string
    {
        $variant = $this->variant();
        if ($variant === null) {
            return null;
        }
        $seed = $this->loadSeed($variant);
        if ($seed === null) {
            return null;
        }

        $exact = $this->accountByTagMatch($seed['items'], static fn(string $t): bool => $t === $tag);
        if ($exact !== null) {
            return $exact;
        }

        $dot = strpos($tag, '.');
        if ($dot === false) {
            return null;
        }
        $prefix = substr($tag, 0, $dot + 1);
        return $this->accountByTagMatch(
            $seed['items'],
            static fn(string $t): bool => str_starts_with($t, $prefix),
        );
    }

    /**
     * První položka nabídky aktivní varianty nesoucí přesně tento štítek —
     * včetně klíče `code`. Bez prefix fallbacku: materializace položky ze
     * skupinového zásahu by založila položku, která hledaný štítek nenese
     * (D26 — fallback smí jen navrhovat účet, ne položku).
     *
     * @return array<string, mixed>|null
     */
    public function entryForTag(string $tag): ?array
    {
        $variant = $this->variant();
        if ($variant === null) {
            return null;
        }
        $seed = $this->loadSeed($variant);
        if ($seed === null) {
            return null;
        }
        foreach ($seed['items'] as $code => $entry) {
            foreach ((array) ($entry['contentTags'] ?? []) as $t) {
                if ($t === $tag) {
                    $entry['code'] = (string) $code;
                    return $entry;
                }
            }
        }
        return null;
    }

    /**
     * Lokalizované pole z JSONC záznamu nabídky — `{base}:{jazyk}` →
     * `{base}:en` → `{base}` → $fallback (stejný chain jako ConfigLocalizer).
     * Sdílené SetupControllerem a AccountingItemMaterializerem.
     *
     * @param array<string, mixed> $entry
     */
    public static function localizedField(array $entry, string $base, string $language, string $fallback): string
    {
        foreach ([$base . ':' . $language, $base . ':en', $base] as $key) {
            if (!empty($entry[$key])) {
                return (string) $entry[$key];
            }
        }
        return $fallback;
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param callable(string): bool $matches
     */
    private function accountByTagMatch(array $items, callable $matches): ?string
    {
        foreach ($items as $entry) {
            foreach ((array) ($entry['contentTags'] ?? []) as $tag) {
                if (is_string($tag) && $matches($tag)) {
                    $account = (string) ($entry['account'] ?? '');
                    return $account !== '' ? $account : null;
                }
            }
        }
        return null;
    }

    /**
     * @return array{groups: list<array<string, mixed>>, items: array<string, array<string, mixed>>}|null
     */
    private function loadSeedFresh(string $variant): ?array
    {
        $file = match ($variant) {
            'default' => 'accountingItemsDefault.jsonc',
            'npo'     => 'accountingItemsNpo.jsonc',
            default   => null,
        };
        // Bez resolveru se soubory hledají relativně k této třídě — žije
        // uvnitř modulu economy.items, takže dirname(__DIR__) je jeho kořen.
        $modulePath = $this->modulePathResolver?->getPath('economy.items') ?? dirname(__DIR__);
        if ($file === null || $modulePath === null) {
            return null;
        }
        $path = $modulePath . '/config/' . $file;
        if (!is_file($path)) {
            ErrorLogger::error('AccountingItemsOffer: accounting items seed not found', ['file' => $file]);
            return null;
        }

        $seed = JsoncParser::parseFile($path);
        if (!is_array($seed) || !is_array($seed['items'] ?? null)) {
            return null;
        }

        $groups   = [];
        $groupIds = [];
        foreach ((array) ($seed['groups'] ?? []) as $entry) {
            if (is_array($entry) && !empty($entry['id'])) {
                $groups[] = $entry;
                $groupIds[(string) $entry['id']] = true;
            }
        }

        $byCode = [];
        foreach ($seed['items'] as $entry) {
            if (!is_array($entry) || empty($entry['code'])) {
                continue;
            }
            // Neznámá skupina je chyba seedu, ne důvod položku zahodit —
            // offer ji vrátí tak, jak je, klient ji zobrazí v sekci Ostatní.
            $group = (string) ($entry['group'] ?? '');
            if (!isset($groupIds[$group])) {
                ErrorLogger::error('AccountingItemsOffer: seed entry has unknown group', [
                    'file'  => $file,
                    'code'  => (string) $entry['code'],
                    'group' => $group,
                ]);
            }
            $byCode[(string) $entry['code']] = $entry;
        }
        return ['groups' => $groups, 'items' => $byCode];
    }
}
