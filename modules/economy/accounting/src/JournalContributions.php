<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Accounting\JournalContributor;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\JournalLineRequest;
use Shipard\Core\Accounting\JournalLineView;
use Shipard\Core\Accounting\JournalSourceContext;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Krok contributorů deníku sdílený oběma účtovacími enginy (#79 D3b):
 * sběr požadavků od registrovaných contributorů a převod požadavku na
 * účet rozvrhu. Engine si sám staví kontext a pohledy na řádky, doplněné
 * řádky seskupí a zapíše; tady je jen to, co by jinak měl dvakrát.
 *
 * Chyba contributoru účtování neblokuje: výjimka se zaloguje a spolkne,
 * engine dostane varování `contributor_failed` (stav účtování zůstává OK,
 * saldo pak ukáže případ otevřený — bezpečný stav). Nedohledaný účet je
 * chybový řádek jako u masky předpisu (`account_not_found`).
 */
final class JournalContributions
{
    public const WARNING_CODE = 'contributor_failed';

    /** Délka čísla účtu pro chybovou masku (799 → '799???'). */
    private const ACCOUNT_NUMBER_LENGTH = 6;

    /**
     * Zavolá contributory v pořadí sady; výjimka jednoho contributoru
     * neshodí ostatní. `$warn(code, message)` dostane varování per selhání.
     *
     * @param list<JournalLineView> $lines
     * @param callable(string, string): void $warn
     * @return list<JournalLineRequest>
     */
    public static function collect(
        JournalContributorSet $contributors,
        JournalSourceContext $context,
        array $lines,
        callable $warn,
    ): array {
        $requests = [];
        foreach ($contributors as $contributor) {
            try {
                foreach ($contributor->contribute($context, $lines) as $request) {
                    if (!$request instanceof JournalLineRequest) {
                        throw new \LogicException(
                            $contributor::class . '::contribute must return JournalLineRequest instances, '
                            . get_debug_type($request) . ' given',
                        );
                    }
                    $requests[] = $request;
                }
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, sprintf(
                    'JournalContributor %s failed for %s #%d',
                    $contributor::class,
                    $context->sourceKind,
                    $context->sourceId,
                ));
                $warn(self::WARNING_CODE, sprintf(
                    'Příspěvek do deníku (%s) selhal, deník je zapsán bez něj: %s',
                    self::shortName($contributor),
                    $e->getMessage(),
                ));
            }
        }
        return $requests;
    }

    /**
     * Účet pro požadavek: `category` → první maska kategorie v předpisu →
     * `AccountMaskResolver`; `accountNumber` → resolver s plným číslem
     * (ověření v rozvrhu k datu). Nenalezeno → chybový řádek (číslo
     * doplněné '?', is_error) + `$error('account_not_found', message)`.
     *
     * @param array<string, mixed>|null $rules účtovací předpis (AccountingRules::resolve)
     * @param callable(string, string): void $error
     * @return array{id?: int, number: string, is_error?: bool}
     */
    public static function resolveAccount(
        JournalLineRequest $request,
        ?array $rules,
        AccountMaskResolver $resolver,
        string $accountingDate,
        callable $error,
    ): array {
        if ($request->category !== null && $request->category !== '') {
            $mask = AccountingRules::firstMaskForCategory($rules, $request->category);
            if ($mask === '') {
                $error('account_not_found', "Předpis nemá masku pro kategorii '{$request->category}' (příspěvek do deníku)");
                return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
            }
            $account = $resolver->resolve($mask, $accountingDate);
            if ($account === null) {
                $error('account_not_found', "Účet nenalezen pro masku {$mask} (kategorie '{$request->category}', příspěvek do deníku)");
                return ['number' => str_pad($mask, self::ACCOUNT_NUMBER_LENGTH, '?'), 'is_error' => true];
            }
            return $account;
        }

        $number = (string) $request->accountNumber;
        $account = $resolver->resolve($number, $accountingDate);
        if ($account === null || $account['number'] !== $number) {
            $error('account_not_found', "Účet {$number} nenalezen v rozvrhu (příspěvek do deníku)");
            return ['number' => str_pad($number, self::ACCOUNT_NUMBER_LENGTH, '?'), 'is_error' => true];
        }
        return $account;
    }

    private static function shortName(JournalContributor $contributor): string
    {
        $class = $contributor::class;
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
