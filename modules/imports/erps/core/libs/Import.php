<?php

namespace imports\erps\core\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Utils;
use \e10doc\core\libs\E10Utils;


/**
 * class Import
 */
class Import extends Utility
{
	function checkVat($percents, $head, &$row)
	{
		$dateTax = Utils::createDateTime ($head['dateTax']);
		$percSettings = $this->app->cfgItem ('e10doc.taxes.'.'eu'.'.'.'cz'.'.taxPercents', NULL);
		forEach ($percSettings as $itm)
		{
			if ($itm['value'] != $percents)
				continue;

			$dateFrom = Utils::createDateTime ($itm ['from']);
			$dateTo = Utils::createDateTime ($itm ['to']);

			if (($dateFrom) && ($dateFrom > $dateTax))
				continue;
			if (($dateTo) && ($dateTo < $dateTax))
				continue;

			$taxCodeCfg = E10Utils::taxCodeCfg($this->app(), $itm['code']);
			if (!$taxCodeCfg)
				continue;

			if (isset($taxCodeCfg['dir']) && $taxCodeCfg['dir'] != 0)
				continue;

			$row['taxCode'] = $itm['code'];
			$row['taxRate'] = $taxCodeCfg['rate'];
			$row['taxPercents'] = $itm['value'];
			$row['_fixTaxCode'] = 1;

			return;
		}
	}
}

