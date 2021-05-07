<?php

namespace e10doc\core\libs;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail;


/**
 * Class FormDocRows
 * @package e10doc\core\libs
 */
class FormDocRows extends TableForm
{
	protected $testNewDocRowsEdit  = 0;

	protected function initForm ()
	{
		$this->testNewDocRowsEdit = $this->app()->cfgItem ('options.experimental.testNewDocRowsEdit', 0);

		if ($this->testNewDocRowsEdit)
		{
			$ord = $this->option('ownerRecData', NULL);
			if (!$ord)
			{
				$ownerRecData = $this->app()->db()->query ('SELECT * FROM [e10doc_core_heads] WHERE ndx = %i', $this->recData['document'])->fetch();
				if ($ownerRecData)
					$this->setOption('ownerRecData', $ownerRecData->toArray());
			}
		}
	}
}