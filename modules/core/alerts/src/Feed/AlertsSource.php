<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Alerts\Feed;

use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;
use Shipard\Core\Feed\FeedTexts;

/**
 * Feed zdroj deterministických alertů — aktivní alerty (`alert_state=10`,
 * Snoozed/Resolved/Dismissed NE) jako karty feedu.
 *
 * `severity` → `kind`: error(30)→urgent, warning(20)→review, info(10)→info.
 * `actions[]` alertu se **propíšou beze změny** — jsou už `open_form`/
 * `open_viewer` a už lokalizované (check je vyrábí lokalizované při reconcile).
 * Alert karty tedy jen navigují; snooze/dismiss zůstává ve viewer detailu.
 *
 * `title` = titulek alertu, `subtitle` = zpráva alertu (fallback check_id),
 * `timestamp` = `last_seen_at` (fallback `first_seen_at`), `id` = `"alert:{id}"`.
 *
 * **Agregace per check a sekce**: skupina = alerty jednoho `check_id` se
 * shodnou sekcí z nálezu (`context.navSection`; nálezy bez ní tvoří vlastní
 * skupinu). Víc než `GROUP_THRESHOLD` aktivních alertů skupiny se sbalí do
 * jedné skupinové karty (`id = "alert-group:{check_id}"`, se sekcí z nálezu
 * `"alert-group:{check_id}:{sekce}"`), která individuální karty skupiny plně
 * nahrazuje. Titulek = lokalizovaný název checku z `AlertCheckRegistry`
 * (fallback `check_id`), kind dle nejvyšší severity ve skupině, jediná
 * primary akce `open_viewer` na alerts viewer. Check společný víc agendám
 * tak dá kartu — a odznak — každé sekci, které se jeho nálezy týkají; práh
 * platí per skupina, takže sekce s pár nálezy má dál individuální karty.
 *
 * **Agregace podle tagu `setup`** (ds-setup.md D8, fáze 0 před oběma dalšími):
 * všechny aktivní alerty checků s `tags: ["setup"]` se sbalí do JEDNÉ karty
 * `alert-group:setup` bez prahu (od jedné položky) a dotčené checky se
 * vyřadí z per-check agregace i z individuálních karet. Primární akce
 * `open_panel` → panel dsSetup. Bez registry se tagy nedají zjistit →
 * fáze 0 se přeskočí (fail-open, alerty projdou individuálně).
 *
 * **Sekce feedu** (#101 D5): individuální i skupinové karty nesou
 * `feedSection = alerts` (Upozornění) bez ohledu na závažnost — uvnitř sekce
 * je řadí collector dle `kind`, tedy dle závažnosti. Setup karta patří do
 * Položek k založení (`newItems`). `kind` zůstává dle severity (county,
 * badge sekcí navigace, AI shrnutí).
 *
 * Texty skupinových karet (podtitulek, labely akcí, setup karta) jdou
 * z katalogu `core.alerts.feedTexts` přes `FeedTexts` (ICU plurály, #101
 * D11–D15), bez katalogu anglický fallback. Titulky a zprávy samotných
 * alertů vyrábí checky při reconcile (#102).
 * Detaily: docs/dashboard.md §5.2.
 */
final class AlertsSource implements FeedSource
{
    private const TABLE = 'core_alerts_alerts';

    /** alert_state Active — kanonická hodnota žije v AlertReconciler. */
    private const STATE_ACTIVE = AlertReconciler::STATE_ACTIVE;

    private const SEVERITY_ERROR   = 30;
    private const SEVERITY_WARNING = 20;
    private const SEVERITY_INFO    = 10;

    /** Nad tolik aktivních alertů jedné skupiny (check × sekce) → jedna skupinová karta. */
    private const int GROUP_THRESHOLD = 3;

    /**
     * Sekce navigace z kontextu nálezu (`context.navSection`); prázdná
     * hodnota = bez sekce. Tentýž výraz dělí skupiny ve fázi 1 i 2.
     */
    private const NAV_SECTION_SQL = "NULLIF(JSON_VALUE(`context`, '$.navSection'), '')";

    /** cfgItem se sekcemi navigace — názvy sekcí do podtitulku skupinové karty. */
    private const NAV_SECTIONS_CFG_ITEM = 'global.navSections';

    /** Katalog textů karet (`config/feedTexts.jsonc`). */
    private const FEED_TEXTS_CFG_ITEM = 'core.alerts.feedTexts';

    /** `null` = titulky skupinových karet degradují na `check_id`. */
    public function __construct(
        private readonly ?AlertCheckRegistry $registry = null,
    ) {}

    public function collectCards(FeedContext $ctx): array
    {
        $cards = [];
        $texts = FeedTexts::forContext($ctx, self::FEED_TEXTS_CFG_ITEM);

        // Fáze 0 — tagová agregace setup checků (D8). Bez prahu: osm
        // samostatných setup karet nechceme nikdy. Karta se přidává mimo
        // LIMIT fáze 2, takže ji nápor jiných alertů nevytlačí.
        $setupCheckIds = $this->setupCheckIds();
        if ($setupCheckIds !== []) {
            $agg = $ctx->db->fetchRow(
                'SELECT COUNT(*) AS `cnt`, MAX(`severity`) AS `max_severity`,'
                . ' MAX(`last_seen_at`) AS `last_at`, MAX(`first_seen_at`) AS `first_at`'
                . ' FROM `' . self::TABLE . '`'
                . ' WHERE `alert_state` = %i AND `check_id` IN %in',
                self::STATE_ACTIVE,
                $setupCheckIds,
            );
            if ($agg !== null && (int) ($agg['cnt'] ?? 0) > 0) {
                $cards[] = $this->buildSetupCard($ctx, $texts, $agg, $setupCheckIds);
            }
        }

        // Fáze 1 — agregát per check a sekce z nálezu. Malý výsledek (počet
        // skupin, ne alertů), bez LIMITu → skupinové karty mají pravdivý
        // počet i nad pojistným limitem zdroje. Skupina bez sekce z nálezu
        // má `nav_section` NULL.
        $groups = $ctx->db->fetchAll(
            'SELECT `check_id`, ' . self::NAV_SECTION_SQL . ' AS `nav_section`,'
            . ' COUNT(*) AS `cnt`, MAX(`severity`) AS `max_severity`,'
            . ' MAX(`last_seen_at`) AS `last_at`, MAX(`first_seen_at`) AS `first_at`'
            . ' FROM `' . self::TABLE . '`'
            . ' WHERE `alert_state` = %i'
            . ' GROUP BY `check_id`, ' . self::NAV_SECTION_SQL,
            self::STATE_ACTIVE,
        );

        $individualCheckIds = [];
        $sectionNames = null;
        foreach ($groups as $g) {
            // Setup checky pokrývá karta z fáze 0 — nesmí se objevit podruhé.
            if (in_array((string) $g['check_id'], $setupCheckIds, true)) {
                continue;
            }
            if ((int) $g['cnt'] > self::GROUP_THRESHOLD) {
                $sectionNames ??= self::sectionNames($ctx);
                $cards[] = $this->buildGroupCard($texts, $g, $sectionNames);
            } else {
                $individualCheckIds[(string) $g['check_id']] = true;
            }
        }

        // Fáze 2 — individuální alerty jen pro skupiny pod prahem: okno
        // počítá alerty téže skupiny jako fáze 1, takže check se skupinovou
        // kartou jedné sekce dá individuální karty jen ostatním sekcím.
        // LIMIT je pojistka zdroje (sourceLimit), ne strop feedu — stropuje
        // collector per sekce. Reálně ≤ GROUP_THRESHOLD řádků per skupina.
        if ($individualCheckIds !== []) {
            $rows = $ctx->db->fetchAll(
                'SELECT `id`, `check_id`, `title`, `message`, `severity`, `actions`, `context`,'
                . ' `first_seen_at`, `last_seen_at`'
                . ' FROM ('
                . 'SELECT `id`, `check_id`, `title`, `message`, `severity`, `actions`, `context`,'
                . ' `first_seen_at`, `last_seen_at`,'
                . ' COUNT(*) OVER (PARTITION BY `check_id`, ' . self::NAV_SECTION_SQL . ') AS `group_cnt`'
                . ' FROM `' . self::TABLE . '`'
                . ' WHERE `alert_state` = %i AND `check_id` IN %in'
                . ') AS `a`'
                . ' WHERE `group_cnt` <= %i'
                . ' ORDER BY `severity` DESC, `last_seen_at` DESC, `id` DESC'
                . ' LIMIT %i',
                self::STATE_ACTIVE,
                array_map('strval', array_keys($individualCheckIds)),
                self::GROUP_THRESHOLD,
                $ctx->sourceLimit,
            );
            foreach ($rows as $row) {
                $cards[] = $this->buildCard($row);
            }
        }

        return $cards;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildCard(array $row): array
    {
        $id       = (int) $row['id'];
        $severity = (int) ($row['severity'] ?? self::SEVERITY_WARNING);

        [$kind, $stateStyle, $icon] = $this->severityToPresentation($severity);

        $message  = trim((string) ($row['message'] ?? ''));
        $checkId  = (string) ($row['check_id'] ?? '');
        $subtitle = $message !== '' ? $message : $checkId;

        return [
            'id'          => 'alert:' . $id,
            'source'      => 'alerts',
            'kind'        => $kind,
            'feedSection' => FeedSource::SECTION_ALERTS,
            'icon'       => $icon,
            'stateStyle' => $stateStyle,
            'category'   => FeedSource::CATEGORY_OTHER,
            // Atribuce pro badge sekcí (Fáze 3): sekce z kontextu nálezu
            // (`context.navSection` — check společný víc agendám, #175 U4)
            // má přednost před sekcí checku z module.jsonc; bez obojího
            // null = karta se do badge nepočítá.
            'navSection' => self::contextNavSection($row['context'] ?? null)
                ?? $this->registry?->get($checkId)?->navSection,
            'title'      => (string) ($row['title'] ?? ''),
            'subtitle'   => $subtitle,
            'timestamp'  => $this->toAtom($row['last_seen_at'] ?? null) ?? $this->toAtom($row['first_seen_at'] ?? null),
            'context'    => [
                'alertId'  => $id,
                'checkId'  => $checkId,
                'severity' => $severity,
            ],
            'actions'    => $this->passthroughActions($row['actions'] ?? null),
        ];
    }

    /**
     * Skupinová karta za skupinu (check × sekce z nálezu) nad prahem —
     * obyčejná karta dle kontraktu (title/subtitle fallback, bez headline),
     * frontend beze změny. Sekce karty = sekce z nálezu, jinak sekce checku.
     * Dvě karty téhož checku rozliší název sekce v podtitulku (jen u sekce
     * z nálezu a jen když ji navigace zná).
     *
     * @param array<string,mixed> $g  agregátní řádek (check_id, nav_section, cnt, max_severity, last_at, first_at)
     * @param array<string,string> $sectionNames  id sekce navigace → název
     * @return array<string,mixed>
     */
    private function buildGroupCard(FeedTexts $texts, array $g, array $sectionNames): array
    {
        $checkId  = (string) $g['check_id'];
        $count    = (int) $g['cnt'];
        $severity = (int) ($g['max_severity'] ?? self::SEVERITY_WARNING);
        $section  = self::sectionOrNull($g['nav_section'] ?? null);

        [$kind, $stateStyle, $icon] = $this->severityToPresentation($severity);

        $title = $this->registry?->get($checkId)?->name ?? $checkId;

        $sectionName = $section !== null ? ($sectionNames[$section] ?? null) : null;
        $subtitle = $sectionName !== null
            ? $texts->t('group.subtitleSection', '{n} alerts · {section}', ['n' => $count, 'section' => $sectionName])
            : $texts->t('group.subtitle', '{n} alerts', ['n' => $count]);

        return [
            'id'          => 'alert-group:' . $checkId . ($section !== null ? ':' . $section : ''),
            'source'      => 'alerts',
            'kind'        => $kind,
            'feedSection' => FeedSource::SECTION_ALERTS,
            'icon'       => $icon,
            'stateStyle' => $stateStyle,
            'category'   => FeedSource::CATEGORY_OTHER,
            'navSection' => $section ?? $this->registry?->get($checkId)?->navSection,
            'title'      => $title,
            'subtitle'   => $subtitle,
            'timestamp'  => $this->toAtom($g['last_at'] ?? null) ?? $this->toAtom($g['first_at'] ?? null),
            'context'    => [
                'checkId'  => $checkId,
                'count'    => $count,
                'severity' => $severity,
                'group'    => true,
            ],
            'actions'    => [[
                'id'      => 'open_alerts',
                'label'   => $texts->t('group.action', 'Open alerts'),
                'kind'    => 'open_viewer',
                'target'  => ['viewerId' => 'core.alerts.alerts'],
                'primary' => true,
            ]],
        ];
    }

    /** `context.navSection` nálezu; kontext bez klíče nebo nečitelný = null. */
    private static function contextNavSection(mixed $raw): ?string
    {
        $context = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        return self::sectionOrNull(is_array($context) ? ($context['navSection'] ?? null) : null);
    }

    /** Sekce jako neprázdný řetězec, jinak null. */
    private static function sectionOrNull(mixed $section): ?string
    {
        return is_string($section) && $section !== '' ? $section : null;
    }

    /**
     * Názvy sekcí navigace v jazyce feedu (`global.navSections`, vč. sekcí
     * modulů). Bez compiled configu prázdná mapa — karta pak název nenese.
     *
     * @return array<string,string> id sekce → název
     */
    private static function sectionNames(FeedContext $ctx): array
    {
        $raw = $ctx->config?->cfgItem(self::NAV_SECTIONS_CFG_ITEM);
        $sections = is_array($raw) ? ($raw['sections'] ?? null) : null;
        if (!is_array($sections)) {
            return [];
        }
        $names = [];
        foreach ($sections as $section) {
            if (!is_array($section) || !is_string($section['id'] ?? null)) {
                continue;
            }
            $name = $section['name:' . $ctx->language] ?? $section['name:en'] ?? $section['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[$section['id']] = $name;
            }
        }
        return $names;
    }

    /**
     * `check_id` všech checků s tagem `setup` — checky mohou nést i další
     * tagy, proto in_array, ne rovnost. Bez registry tagy neznáme → [].
     *
     * @return list<string>
     */
    private function setupCheckIds(): array
    {
        if ($this->registry === null) {
            return [];
        }
        $ids = [];
        foreach ($this->registry->getAll() as $def) {
            if (in_array('setup', $def->tags, true)) {
                $ids[] = $def->id;
            }
        }
        return $ids;
    }

    /**
     * Jedna karta za všechny aktivní setup alerty (ds-setup.md §5.5).
     * Podtitulek: jediná položka → její title (u posledního zbývajícího
     * kroku říká konkrétně, co chybí), víc položek → počet. Primární akce
     * `open_panel` vede do panelu dsSetup v Nastavení.
     *
     * @param array<string,mixed> $agg  agregátní řádek (cnt, max_severity, last_at, first_at)
     * @param list<string> $setupCheckIds
     * @return array<string,mixed>
     */
    private function buildSetupCard(FeedContext $ctx, FeedTexts $texts, array $agg, array $setupCheckIds): array
    {
        $count    = (int) $agg['cnt'];
        $severity = (int) ($agg['max_severity'] ?? self::SEVERITY_WARNING);

        [$kind, $stateStyle, $icon] = $this->severityToPresentation($severity);

        if ($count === 1) {
            // Druhý dotaz jen v téhle větvi — čitelnější než MIN(title) trik.
            $subtitle = (string) $ctx->db->fetchSingle(
                'SELECT `title` FROM `' . self::TABLE . '`'
                . ' WHERE `alert_state` = %i AND `check_id` IN %in'
                . ' LIMIT 1',
                self::STATE_ACTIVE,
                $setupCheckIds,
            );
        } else {
            // Tři české tvary (1 položka / 2–4 položky / 5+ položek) řeší ICU.
            $subtitle = $texts->t('setup.items', '{n, plural, one {# item} other {# items}}', ['n' => $count]);
        }

        return [
            'id'          => 'alert-group:setup',
            'source'      => 'alerts',
            'kind'        => $kind,
            // Položky k založení (#101 D5): nastavení odblokuje ostatní práci.
            'feedSection' => FeedSource::SECTION_NEW_ITEMS,
            'icon'       => $icon,
            'stateStyle' => $stateStyle,
            'category'   => FeedSource::CATEGORY_OTHER,
            'title'      => $texts->t('setup.title', 'Finish setup'),
            'subtitle'   => $subtitle,
            'timestamp'  => $this->toAtom($agg['last_at'] ?? null) ?? $this->toAtom($agg['first_at'] ?? null),
            'context'    => [
                'tag'      => 'setup',
                'count'    => $count,
                'severity' => $severity,
                'group'    => true,
            ],
            'actions'    => [[
                'id'      => 'open_setup_panel',
                'label'   => $texts->t('setup.action', 'Open setup'),
                'kind'    => 'open_panel',
                'target'  => ['panelId' => 'dsSetup'],
                'primary' => true,
            ]],
        ];
    }

    /**
     * `severity` → `[kind, stateStyle, icon]` — sdílené individuální i
     * skupinovou kartou (skupina mapuje `MAX(severity)` stejně).
     *
     * @return array{0:string, 1:string, 2:string}
     */
    private function severityToPresentation(int $severity): array
    {
        return match ($severity) {
            self::SEVERITY_ERROR => ['urgent', 'error', 'alert'],
            self::SEVERITY_INFO  => ['info', 'concept', 'info'],
            default              => ['review', 'edit', 'warning'],
        };
    }

    /**
     * Dekóduje `actions` sloupec alertu a propíše ho beze změny (jen sanitizace
     * na list of objektů). Actions jsou už `open_form`/`open_viewer` a
     * lokalizované; feed je jen renderuje.
     *
     * @return list<array<string,mixed>>
     */
    private function passthroughActions(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $action) {
            if (is_array($action) && isset($action['kind'])) {
                $out[] = $action;
            }
        }
        return $out;
    }

    /** DB datetime → ATOM; null/prázdné → null. */
    private function toAtom(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
