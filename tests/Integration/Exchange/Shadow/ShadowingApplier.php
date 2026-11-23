<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\Shadow;

use Dibi\Connection;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\VatCodeDerivation;
use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Shipard\Module\World\Trade\TradeUnionResolver;
use Shipard\Module\World\Vat\VatRateResolver;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\Shadow\ShadowRunner;

/**
 * Applier, který každý **návrh** nejdřív prožene stínovým porovnáním
 * ({@see ShadowRunner}) a vrátí výsledek dnešní cesty — volající (použití
 * návrhu z pošty) tak pokračuje jako v provozu a stínový test dostane přesně
 * ten canonical, který provoz skládá. Pokyn jde beze změny.
 */
final class ShadowingApplier extends DocumentApplier
{
    public ?ShadowRunner $shadow = null;

    /**
     * Spustí práci a její zápisy vrátí zpět — pro varianty voleb, které se
     * porovnají celé nanečisto (dnešní i nová cesta).
     *
     * @var (\Closure(\Closure(): mixed): mixed)|null
     */
    public ?\Closure $isolated = null;

    /**
     * Varianty `applyOptions`, se kterými se každý návrh porovná nanečisto
     * navíc k volbám volajícího: popis → volby.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $variants = [];

    /** @var array<string, list<string>> popis vstupu → rozdíly */
    public array $differences = [];

    /** Počet porovnaných návrhů. */
    public int $compared = 0;

    private bool $comparing = false;

    /**
     * Stejné zapojení jako `DocumentApplier::create()`, jen s gatewayi
     * dodanými testem (hlavičky zaznamenává {@see RecordingGateway}).
     *
     * @param array<string, TableDefinition> $tables
     */
    public static function build(
        Connection $db,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        array $tables,
        TransactionlessTableGateway $heads,
        TransactionlessTableGateway $persons,
        TransactionlessTableGateway $items,
    ): self {
        $vatRateResolver = new VatRateResolver($config);
        $contentTags = $config->cfgItem('core.exchange.contentTags');
        $itemColumns = array_map(static fn ($column) => $column->id, ($tables['economy_items'] ?? null)?->columns ?? []);

        return new self(
            db: $db,
            config: $config,
            headsGateway: $heads,
            personsGateway: $persons,
            itemsGateway: $items,
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(JournalDimensionSet::fromConfig($config), $tables),
            partyResolver: new PartyResolver($db, new OwnCompanyResolver($db)),
            itemResolver: new ItemResolver($db),
            unitResolver: new UnitResolver($db),
            vatCodeResolver: new VatCodeResolver($vatRateResolver),
            bankAccountResolver: new BankAccountResolver($db),
            accountResolver: new AccountResolver($db),
            vatCodeDerivation: new VatCodeDerivation($vatRateResolver),
            vatPlaceDerivation: new VatPlaceDerivation(new TradeUnionResolver($config)),
            contentTags: is_array($contentTags) ? $contentTags : [],
            dimensionResolver: new DimensionResolver($db, JournalDimensionSet::fromConfig($config), $tables),
            itemsHaveAccountingAccount: in_array('accounting_account', $itemColumns, true),
            exchangeRateResolver: isset($tables[ExchangeRateResolver::TABLE_LISTS])
                ? ExchangeRateResolver::create($db, $config, $dsConfig)
                : null,
            references: new ReferenceResolver($db, $tables),
        );
    }

    public function apply(array $canonical, ApplyMode $mode = ApplyMode::Instruction): ApplyResult
    {
        if ($this->comparing || $this->shadow === null || !$mode->derives()) {
            return parent::apply($canonical, $mode);
        }
        $label = sprintf(
            'zpráva %s, doklad %s',
            (string) ($canonical['source']['message'] ?? '?'),
            (string) ($canonical['docNumber'] ?? '?'),
        );
        $this->comparing = true;
        try {
            foreach ($this->variants as $name => $options) {
                $variant = ['applyOptions' => $options] + $canonical;
                $found = [
                    ...$this->shadow->preview($variant),
                    ...($this->isolated)(fn (): array => $this->shadow->apply($variant)['differences']),
                ];
                if ($found !== []) {
                    $this->differences["{$label} ({$name})"] = $found;
                }
            }
            $differences = $this->shadow->preview($canonical);
            $applied = $this->shadow->apply($canonical);
        } finally {
            $this->comparing = false;
        }
        $this->compared++;
        $differences = [...$differences, ...$applied['differences']];
        if ($differences !== []) {
            $this->differences[$label] = $differences;
        }
        return $applied['result'];
    }
}
