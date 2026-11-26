<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Checks;

use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;

/**
 * Zdroje fakturace pro upozornění (#175 D6): registr z konfigurace zdroje
 * dat, jedna instance na běh checku. `withSources()` je šev pro testy.
 * Trait pro `AlertCheck` — používá `$this->db`, `$this->config`
 * a `$this->dsConfig`.
 */
trait UsesInvoicingSources
{
    private ?InvoicingSources $invoicingSources = null;

    public function withSources(InvoicingSources $sources): static
    {
        $this->invoicingSources = $sources;
        return $this;
    }

    protected function sources(): InvoicingSources
    {
        return $this->invoicingSources ??= InvoicingSources::fromConfig(
            $this->config,
            $this->db->getDibiConnection(),
            $this->dsConfig,
        );
    }
}
