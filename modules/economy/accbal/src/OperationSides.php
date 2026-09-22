<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Operace řádku deníku → strana saldokonta (#69 D17).
 *
 * Generátor ({@see LedgerGenerator::buildDesired}) dává operaci řádku
 * přednost před pravidly nastavení (účet + strana + znaménko): zápočet,
 * oprava salda nebo kurzový rozdíl pohledávky patří do skupiny pohledávek
 * i se zápornou částkou — sign-pravidlo dobropisu na něj nesahá. Platba
 * (`payment.*`) jde do skupiny účtu řádku a předpis/úhradu jí dává strana
 * řádku proti předpisové straně skupiny (#69 D23): bankovní záloha na
 * 324 DAL je předpis přijaté zálohy, vratka přeplatku na 311 MD předpis +;
 * znaménko částky se zachová.
 *
 * Mapa je úplná přes obě konfigurace, které do sloupce
 * `economy_accounting_journal.operation` píší: `docs.core.rowOperations`
 * (řádky dokladů, AccountingEngine) a `economy.bank.txOperations`
 * (bankovní engine). Operace bez vlivu na stranu mají `null` — řádek
 * jde přes nastavení. Test OperationSidesTest hlídá, že žádná operace
 * z konfigurace v mapě nechybí: nová operace bez zařazení = selhání.
 */
final class OperationSides
{
    /** Předpis / úhrada pohledávky — skupina s předpisem na MD. */
    public const SIDE_RECEIVABLE = 0;
    /** Předpis / úhrada závazku — skupina s předpisem na DAL. */
    public const SIDE_PAYABLE = 1;
    /** Skupina účtu řádku; předpis/úhrada podle strany řádku proti předpisu skupiny (D23). */
    public const PAYMENT = 'payment';

    /** @var array<string, int|string|null> id operace → SIDE_* | PAYMENT | null */
    public const MAP = [
        // ── docs.core.rowOperations ──────────────────────────────────────
        'sale.services'             => null,
        'sale.goods'                => null,
        'purchase.goods'            => null,
        'purchase.services'         => null,
        'purchase.other'            => null,
        'payment.receivable'        => self::PAYMENT,
        'payment.payable'           => self::PAYMENT,
        'transfer.in'               => null,
        'transfer.out'              => null,
        'purchase.advanceDeduction' => null,
        'purchase.advanceVat'       => null,
        'purchase.asset'            => null,
        'sale.advanceDeduction'     => null,
        'advance.received'          => null,
        'advance.given'             => null,
        'sale.advanceVat'           => null,
        'acc.entry'                 => null,
        'acc.record'                => null,
        'acc.item'                  => null,
        'acc.balanceReceivable'     => self::SIDE_RECEIVABLE,
        'acc.balancePayable'        => self::SIDE_PAYABLE,
        'acc.fxLossReceivable'      => self::SIDE_RECEIVABLE,
        'acc.fxGainReceivable'      => self::SIDE_RECEIVABLE,
        'acc.fxLossPayable'         => self::SIDE_PAYABLE,
        'acc.fxGainPayable'         => self::SIDE_PAYABLE,
        // ── economy.bank.txOperations (transfer.* sdílí id s řádky) ─────
        'payment.in'                => self::PAYMENT,
        'payment.out'               => self::PAYMENT,
        'fee.out'                   => null,
        'interest.in'               => null,
        'interest.out'              => null,
    ];

    /** Zařazení operace; neznámá / prázdná → null (řádek jde přes nastavení). */
    public static function kindOf(?string $operation): int|string|null
    {
        if ($operation === null || $operation === '') {
            return null;
        }
        return self::MAP[$operation] ?? null;
    }
}
