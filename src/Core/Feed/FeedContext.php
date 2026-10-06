<?php

declare(strict_types=1);

namespace Shipard\Core\Feed;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Kontext předaný feed zdroji při sběru karet. Nese vše, co zdroj potřebuje z
 * requestu/DS — DataSource je už resolvnutý (DS-scoped), `config` může být null
 * (compiled config nedoběhl → zdroj degraduje, ne crashne).
 *
 * `sourceLimit` je **pojistka proti neomezenému SELECTu** (`LIMIT` dotazů
 * zdroje), ne strop feedu — strop dělá `FeedCollector` per sekce až nad
 * posbíranými kartami (#101 D3a). Zdroj proto nesmí limit používat jako
 * hranici toho, co uživatel uvidí: návrhy ready + review chodí jedním
 * dotazem a pásmo se počítá až v PHP.
 *
 * Analog `McpInvocationContext` — bezstavové, readonly.
 */
final readonly class FeedContext
{
    public function __construct(
        public DataSourceConnection $db,
        public ?ConfigRuntime $config,
        public string $language,
        public int $sourceLimit,
    ) {}
}
