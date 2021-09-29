<?php

namespace terminals\store;
use e10doc\core\libs\E10Utils;


/**
 * Class ItemsViewer
 * @package terminals\store
 */
class ItemsViewer extends \e10\witems\ViewItems
{
	var $taxCalc = 2;
	var $taxRegCfg;

	public function init()
	{
		parent::init();

		$this->taxRegCfg = E10Utils::primaryTaxRegCfg($this->app());
		$this->taxCalc = intval($this->app->cfgItem ('options.e10doc-sale.cashRegSalePricesType', 2));
	}

	public function renderRow ($item)
	{
		$listItem = parent::renderRow($item);

		$listItem ['data-cc']['title'] = $item['shortName'];
		$listItem ['data-cc']['name'] = $item['fullName'];
		$listItem ['data-cc']['price'] = E10Utils::itemPriceSell($this->app(), $this->taxRegCfg, $this->taxCalc, $item);
		$listItem ['data-cc']['unit'] = $item['defaultUnit'];
		$listItem ['data-cc']['unitName'] = $this->units[$item['defaultUnit']]['shortcut'];

		return $listItem;
	}

	public function qryCommon (array &$q)
	{
		array_push ($q, ' AND ([items].[itemKind] IN (0, 1) OR ([items].[itemKind] = 3 AND [items].[useFor] = 2))');
	}
}
