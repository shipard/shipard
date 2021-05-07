<?php

namespace lib\tools\viewer;


use \E10\Utility;


/**
 * Class ViewerToolsAction
 * @package lib\tools\viewer
 */
class ViewerToolsAction extends Utility
{
	/** @var \e10doc\core\TableHeads */
	var $table;
	/** @var \e10doc\core\TableRows */
	var $tableRows;
	protected $params = NULL;

	public function actionParams()
	{
		return FALSE;
	}

	public function init ()
	{
		$this->tableRows = $this->app()->table('e10doc.core.rows');
	}

	public function setParams ($params)
	{
		$this->params = $params;
	}

	public function run ()
	{
	}

	function actionInfo ()
	{
		return [];
	}

	protected function applyRowsSettings($docHead)
	{
		$rowsSettings = new \e10doc\helpers\RowsSettings($this->app());

		$q[] = 'SELECT * FROM [e10doc_core_rows]';
		array_push ($q, ' WHERE [document] = %i', $docHead['ndx'], ' ORDER BY [rowOrder], [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$row = $r->toArray();
			$rowsSettings->run ($row, $docHead);
			$this->tableRows->dbUpdateRec($row, $docHead);
		}
	}

	public function setDocState ($docNdx, $docStateMain, $docState)
	{
		$docStatesDef = $this->app->model()->tableProperty ($this->table, 'states');
		$f = $this->table->getTableForm ('edit', $docNdx);

		$f->recData[$docStatesDef['stateColumn']] = $docState;
		$f->recData[$docStatesDef['mainStateColumn']] = $docStateMain;

		$this->setDocStateFormActionBefore ($f, $docStateMain, $docState);

		if ($f->checkAfterSave())
			$this->table->dbUpdateRec ($f->recData);

		$f->checkAfterSave();
		$this->table->checkDocumentState ($f->recData);
		$this->table->dbUpdateRec ($f->recData);
		$this->table->checkAfterSave2 ($f->recData);

		$this->table->docsLog ($f->recData['ndx']);
	}

	protected function setDocStateFormActionBefore ($form, $docStateMain, $docState)
	{
	}
}
