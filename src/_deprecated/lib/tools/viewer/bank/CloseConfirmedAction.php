<?php

namespace lib\tools\viewer\bank;


/**
 * Class CloseConfirmedAction
 * @package lib\tools\viewer\bank
 */
class CloseConfirmedAction extends \lib\tools\viewer\ViewerToolsAction
{
	public function init ()
	{
		$this->table = $this->app()->table('e10doc.core.heads');
		parent::init();
	}

	function actionInfo ()
	{
		return ['name' => 'Uzavřít potvrzené výpisy', 'icon' => 'icon-check-circle'];
	}

	function doIt ()
	{
		$q[] = 'SELECT * FROM [e10doc_core_heads] AS heads';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND heads.[docType] = %s', 'bank');
		array_push ($q, ' AND heads.[docState] = %i', 1200);
		array_push ($q, ' ORDER BY heads.[docOrderNumber], heads.[ndx]');

		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->setDocState($r['ndx'], 2, 4000);
		}
	}

	public function run ()
	{
		$this->doIt();
	}
}
