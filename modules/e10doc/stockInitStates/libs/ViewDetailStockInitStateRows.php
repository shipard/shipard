<?php

namespace e10doc\stockInitStates\libs;


class ViewDetailStockInitStateRows extends \e10Doc\Core\ViewDetailDocRows
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10doc.core.rows', 'e10doc.stockInitStates.libs.ViewStockInitStateRows',
														 array ('document' => $this->item ['ndx']));
	}
}
