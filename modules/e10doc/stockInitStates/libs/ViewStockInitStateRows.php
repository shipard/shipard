<?php

namespace e10doc\stockInitStates\libs;
use \Shipard\Utils\Utils;


class ViewStockInitStateRows extends \E10Doc\Core\ViewDocumentRows
{
	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['text'];
		$listItem ['t2'] = $this->itemsTypes [$item['itemType']]['.text'];
		$listItem ['i1'] = Utils::nfu ($item ['quantity'], 2) . ' ' . $this->itemsUnits[$item['rowUnit']]['shortcut'];

		$listItem ['i2'] = Utils::nf ($item ['priceItem'], 2).' / '.$this->itemsUnits[$item['rowUnit']]['shortcut'] .
											 ' | celkem '.utils::nfu ($item ['priceAll'], 2);

		return $listItem;
	}
}
