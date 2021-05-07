<?php

namespace wkf\core\viewers;

use \e10\TableView, e10\TableViewDetail, \e10\utils;


/**
 * Class WkfDiaryDetail
 * @package wkf\core\viewers
 */
class WkfDiaryDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$vid = 'mainListView' . mt_rand() . '_' . TableView::$vidCounter++;
		$tableId = $this->tableId();

		$tableNdx = $this->table->ndx;
		$recNdx = $this->item ['ndx'];

		$this->addContent([
			'type' => 'viewer', 'table' => 'wkf.core.issues', 'viewer' => 'wkf.core.viewers.WkfDiaryViewer',
			'params' => [
				'forceInitViewer' => 1,
				'srcRecNdx' => $recNdx, 'srcTableNdx' => $tableNdx, 'srcTableId' => $tableId,
				'srcDocRecData' => $this->item,
			],
			'vid' => $vid
			]);
	}
}
