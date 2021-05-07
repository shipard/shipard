<?php

namespace terminals\store;

require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';
use e10doc\core\e10utils;


/**
 * Class ItemsViewer
 * @package terminals\store
 */
class ItemsViewer extends \E10\Witems\ViewItems
{
	var $taxCalc = 2;

	public function init()
	{
		parent::init();
		$this->taxCalc = intval($this->app->cfgItem ('options.e10doc-sale.cashRegSalePricesType', 2));
	}

	public function renderRow ($item)
	{
		$listItem = parent::renderRow($item);

		$listItem ['data-cc']['title'] = $item['shortName'];
		$listItem ['data-cc']['name'] = $item['fullName'];
		$listItem ['data-cc']['price'] = e10utils::itemPriceSell($this->app(), $this->taxCalc, $item);
		$listItem ['data-cc']['unit'] = $item['defaultUnit'];
		$listItem ['data-cc']['unitName'] = $this->units[$item['defaultUnit']]['shortcut'];

		return $listItem;
	}

	public function qryCommon (array &$q)
	{
		array_push ($q, ' AND ([items].[itemKind] IN (0, 1) OR ([items].[itemKind] = 3 AND [items].[useFor] = 2))');
	}
}
