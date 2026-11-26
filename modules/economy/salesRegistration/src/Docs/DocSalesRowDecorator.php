<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Docs;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\RowDecorator;

/**
 * Štítek „EET“ v seznamu dokladů (#171 D13, U19) — jen u dokladů, které mají
 * tržbu, s barvou podle nejhoršího stavu jejich tržeb (odmítnutá > čeká na
 * odeslání > zahozená pojistkou > potvrzená); potvrzení z playgroundu je
 * označené „EET test“. Stavy tržeb celé stránky řádků čte jedním dotazem;
 * řádky dokladů ani vyhodnocení nečte vůbec.
 */
class DocSalesRowDecorator implements RowDecorator
{
    public function decorate(string $table, array $rawRows, ModuleExtensionContext $context): array
    {
        $docIds = [];
        foreach ($rawRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $docIds[] = $id;
            }
        }
        if ($docIds === []) {
            return [];
        }

        $sales = $this->docSales($context);
        $spans = [];
        foreach ($this->statesOf($sales, $docIds) as $docId => $doc) {
            $tag = $sales->tag($doc['states'], $doc['test']);
            if ($tag !== null) {
                $spans[$docId] = [['text' => $tag['short'], 'class' => $sales->spanClass($tag['state'])]];
            }
        }
        return $spans;
    }

    protected function docSales(ModuleExtensionContext $context): DocSales
    {
        return new DocSales($context->db->getDibiConnection(), $context->config);
    }

    /**
     * Stavy tržeb dokladů stránky (přepsatelné v testech).
     *
     * @param list<int> $docIds
     * @return array<int, array{states: list<string>, test: bool}>
     */
    protected function statesOf(DocSales $sales, array $docIds): array
    {
        return $sales->statesOf($docIds);
    }
}
