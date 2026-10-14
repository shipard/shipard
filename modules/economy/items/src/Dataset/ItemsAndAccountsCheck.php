<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items\Dataset;

use Shipard\Module\Core\Exchange\Dataset\Verify\CheckResult;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyCheck;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyContext;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Economy\Items\AccountingItemsOffer;

/**
 * Kontrola 2 — položky a účty (tasks/testing-phase3.md): Použít nezaložilo
 * žádnou položku a každý řádek potvrzeného dokladu má položku s účtem
 * a očekávaným štítkem.
 *
 * Formulace nezávislá na běhu (druhý běh dá totéž): ve zdroji dat není
 * položka mimo nabídku účetních položek osnovy (`AccountingItemsOffer`)
 * a mimo sekci `items/` sady — položku založenou applierem podle řádku
 * poznáme podle kódu. Při apply navíc počet položek před = po. Řádek:
 * položka, její účet (`accounting_account`), štítek řádku z návrhu
 * (`_resolve.contentTag`, výjimky `rowExceptions`) mezi `content_tags`
 * položky; deník dokladu bez chybných řádků (`is_error`).
 */
final class ItemsAndAccountsCheck implements VerifyCheck
{
    public const ID = 'itemsAndAccounts';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Položky a účty (Použít nezakládá položky, řádky mají účet)';
    }

    public function run(VerifyContext $ctx): CheckResult
    {
        $w = $ctx->wiring;
        $db = $w->db;
        $differences = [];
        $notes = [];

        $offer = new AccountingItemsOffer($w->dsConnection, $w->resolver);
        $variant = $offer->variant();
        $seed = $variant !== null ? $offer->loadSeed($variant) : null;
        if ($seed === null) {
            $notes[] = 'nabídka účetních položek osnovy ' . ($variant ?? '—') . ' není k dispozici — položky mimo nabídku se neověřují';
        } else {
            $allowed = array_fill_keys(array_map('strval', array_keys($seed['items'])), true);
            foreach ($ctx->reader->listFiles('items') as $rel) {
                $code = $ctx->reader->readJsonc($rel)['code'] ?? null;
                if (is_string($code) && $code !== '') {
                    $allowed[$code] = true;
                }
            }
            foreach ($db->fetchAll('SELECT [id], [code], [name] FROM [economy_items] WHERE [docState] <> 90 ORDER BY [id]') as $item) {
                if (!isset($allowed[(string) $item['code']])) {
                    $differences[] = "položka mimo nabídku osnovy: {$item['code']} {$item['name']} (#{$item['id']}) — založil ji Použít podle řádku";
                }
            }
        }

        $apply = $ctx->apply;
        if ($apply->ran && $apply->itemsBefore !== null && $apply->itemsAfter !== null && $apply->itemsBefore !== $apply->itemsAfter) {
            $differences[] = "apply změnil počet položek: {$apply->itemsBefore} → {$apply->itemsAfter}";
        }

        $docs = $ctx->appliedDocuments();
        if ($docs === []) {
            if ($differences === []) {
                return CheckResult::skipped(self::ID, $this->label(), 'žádný použitý doklad', $notes);
            }
            return CheckResult::fromDifferences(self::ID, $this->label(), $differences, $notes);
        }

        $journal = $w->hasTable('economy_accounting_journal');
        foreach ($docs as $doc) {
            $rows = $db->fetchAll(
                'SELECT [r.item], [i.code], [i.content_tags], [i.accounting_account]'
                . ' FROM [docs_core_rows] AS [r] LEFT JOIN [economy_items] AS [i] ON [i.id] = [r.item]'
                . ' WHERE [r.doc_head] = %i ORDER BY [r.order_pos], [r.id]',
                $doc->docId,
            );
            foreach ($rows as $i => $row) {
                $label = "{$doc->messageId} ({$doc->docNumber}) řádek " . ($i + 1);
                if ($row['item'] === null) {
                    $differences[] = "{$label}: bez položky";
                    continue;
                }
                if ($row['accounting_account'] === null) {
                    $differences[] = "{$label}: položka {$row['code']} bez účtu";
                }
                $expectedTag = $doc->canonical !== null ? RowEnrichmentPipeline::rowContentTagOf($doc->canonical, $i) : null;
                if ($expectedTag !== null) {
                    $tags = json_decode((string) ($row['content_tags'] ?? ''), true);
                    if (!is_array($tags) || !in_array($expectedTag, $tags, true)) {
                        $differences[] = "{$label}: položka {$row['code']} nenese štítek {$expectedTag}";
                    }
                }
            }
            if ($journal) {
                $errors = (int) $db->fetchSingle(
                    'SELECT COUNT(*) FROM [economy_accounting_journal] WHERE [doc_head] = %i AND [is_error] = 1',
                    $doc->docId,
                );
                if ($errors > 0) {
                    $differences[] = "{$doc->messageId} ({$doc->docNumber}): deník má {$errors} chybných řádků (účet nenalezen)";
                }
            }
        }
        $notes[] = count($docs) . ' dokladů';
        return CheckResult::fromDifferences(self::ID, $this->label(), $differences, $notes);
    }
}
