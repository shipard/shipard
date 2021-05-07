<?php

namespace lib\tools\viewer\bank;


/**
 * Class ConfirmNewAction
 * @package lib\tools\viewer\bank
 */
class ConfirmNewAction extends \lib\tools\viewer\ViewerToolsAction
{
	public function init ()
	{
		$this->table = $this->app()->table('e10doc.core.heads');
		parent::init();
	}

	function actionInfo ()
	{
		return ['name' => 'Potvrdit nové výpisy', 'icon' => 'icon-star'];
	}

	function doIt ()
	{
		$q[] = 'SELECT * FROM [e10doc_core_heads] AS heads';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND heads.[docType] = %s', 'bank');
		array_push ($q, ' AND heads.[docState] = %i', 1000);
		array_push ($q, ' ORDER BY heads.[docOrderNumber], heads.[ndx]');

		$lastDocOrderNumber = 0;
		$lastBalance = FALSE;

		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($lastDocOrderNumber && $lastDocOrderNumber !== ($r['docOrderNumber'] - 1))
			{
				break;
			}

			if ($lastBalance !== FALSE && $lastBalance != $r['initBalance'])
			{
				break;
			}

			$this->setDocState($r['ndx'], 1, 1200);

			$lastDocOrderNumber = $r['docOrderNumber'];
			$lastBalance = $r['balance'];
		}
	}

	public function run ()
	{
		$this->doIt();
	}

	protected function setDocStateFormActionBefore ($form, $docStateMain, $docState)
	{
		$useDocRowsSettings = $this->app()->cfgItem ('options.experimental.testDocRowsSettings', 0);
		if ($useDocRowsSettings)
			$this->applyRowsSettings($form->recData);
	}
}
