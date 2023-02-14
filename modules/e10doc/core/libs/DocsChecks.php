<?php

namespace e10doc\core\libs;
use \e10\TableForm, \e10\utils, \e10\Utility;


/**
 * Class DocsChecks
 * @package e10doc\core\libs
 */
class DocsChecks extends Utility
{
	var $docTypes = NULL;
	var $dateFrom = NULL;
	var $dateTo = NULL;
	var $mode = '';
	var $repair = FALSE;

	var $tableHeads;
	var $tableRows;

	function init()
	{
		$this->tableHeads = $this->app->table ('e10doc.core.heads');
		$this->tableRows = $this->app->table ('e10doc.core.rows');
	}

	function detectArgs()
	{
		$dateFrom = $this->app->arg('date-from');
		if ($dateFrom)
			$this->setDateFrom($dateFrom);
		else
		{
			echo "ERROR: missing param `--date-from`\n";
			return FALSE;
		}

		$dateTo = $this->app->arg('date-to');
		if ($dateTo)
			$this->setDateTo($dateTo);
		else
		{
			echo "ERROR: missing param `--date-to`\n";
			return FALSE;
		}

		$docTypes = $this->app->arg('doc-types');
		if ($docTypes)
			$this->setDocTypes($docTypes);
		/*else
		{
			echo "ERROR: missing param `--doc-types`\n";
			return FALSE;
		}*/

		$mode = $this->app->arg('mode');
		if ($mode && in_array($mode, ['all', 'itemsSuccessors', 'itemTypes', 'sets']))
			$this->mode = $mode;
		else
		{
			echo "ERROR: missing or bad param `--mode=all|itemsSuccessors|itemTypes|sets`\n";
			return FALSE;
		}

		$repair = $this->app->arg('repair');
		if ($repair)
			$this->repair = TRUE;

		return TRUE;
	}

	function setDateFrom($dateFrom)
	{
		$this->dateFrom = utils::createDateTime($dateFrom);
	}

	function setDateTo($dateTo)
	{
		$this->dateTo = utils::createDateTime($dateTo);
	}

	function setDocTypes($docTypes)
	{
		$this->docTypes = explode(',', $docTypes);
	}

	function doAll()
	{
		$q[] = 'SELECT ndx, docNumber, docType FROM [e10doc_core_heads] AS [heads]';
		array_push($q, ' WHERE 1');

		if ($this->docTypes)
			array_push($q, ' AND [docType] IN %in', $this->docTypes);

		$this->doAllQuery($q);

		array_push($q, ' ORDER BY dateAccounting, activateTimeLast, ndx');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$this->doOne($r->toArray());
		}
	}

	protected function doAllQuery(&$q)
	{
		array_push($q, ' AND [docState] = %i', 4000);
		array_push($q, ' AND [dateAccounting] >= %d', $this->dateFrom,
			' AND [dateAccounting] <= %d', $this->dateTo);
	}

	function doOne($doc)
	{
		if ($this->mode === 'itemsSuccessors' || $this->mode === 'all')
			$this->doOne_Successors($doc);

		if ($this->mode === 'itemTypes' || $this->mode === 'all')
			$this->doOne_ItemTypes($doc);

		if ($this->mode === 'sets' || $this->mode === 'all')
			$this->doOne_Sets($doc);
	}

	function doOne_ItemTypes($doc)
	{
		$e = new \e10doc\core\libs\DocCheckItemTypes($this->app());
		$this->doOne_Run($doc, $e);
	}

	function doOne_Sets($doc)
	{
		$e = new \e10doc\core\libs\DocCheckSetsItems($this->app());
		$this->doOne_Run($doc, $e);
	}

	function doOne_Successors($doc)
	{
		$e = new \e10doc\core\libs\DocCheckItemsSuccessors($this->app());
		$this->doOne_Run($doc, $e);
	}

	function doOne_Run($doc, \e10doc\core\libs\DocCheck $e)
	{
		$e->init();
		$e->setDocNdx($doc['ndx']);
		$e->checkDocument($this->repair);

		if ($e->needRecalc)
			$this->recalcDocument($doc['ndx']);

		$e->dumpMessages();
	}

	public function run()
	{
		$this->init();
		$this->doAll();
	}

	function recalcDocument($ndx)
	{
		$f = $this->tableHeads->getTableForm ('edit', $ndx);

		$q = "SELECT * FROM [e10doc_core_rows] WHERE [document] = %i ORDER BY ndx";
		$rows = $this->db()->query ($q, $f->recData ['ndx']);
		forEach ($rows as $row)
		{
			$r = $row->toArray();
			$this->tableRows->dbUpdateRec ($r, $f->recData);
		}

		$this->reAccountingDocument($f->recData);

		if ($f->checkAfterSave())
			$this->tableHeads->dbUpdateRec ($f->recData);
	}

	function recalcDocument2($ndx)
	{
		$this->tableHeads->documentOpen($ndx);
		$this->tableHeads->docsLog ($ndx);

		$f = $this->tableHeads->getTableForm ('edit', $ndx);

		$q = "SELECT * FROM [e10doc_core_rows] WHERE [document] = %i ORDER BY ndx";
		$rows = $this->db()->query ($q, $f->recData ['ndx']);
		forEach ($rows as $row)
		{
			$r = $row->toArray();
			$this->tableRows->dbUpdateRec ($r, $f->recData);
		}

		$f->recData ['docState'] = 4000;
		$f->recData ['docStateMain'] = 2;
		$this->tableHeads->checkDocumentState ($f->recData);
		$f->checkAfterSave();
		$this->tableHeads->dbUpdateRec ($f->recData);
		$this->tableHeads->checkAfterSave2 ($f->recData);

		$this->tableHeads->docsLog ($ndx);
	}

	function reAccountingDocument($recData)
	{
		$this->db()->query ('DELETE FROM [e10doc_debs_journal] WHERE [document] = %i', $recData['ndx']);

		$docAccEngine = new \E10Doc\Debs\docAccounting ($this->app());
		$docAccEngine->setDocument ($recData);
		$docAccEngine->run();
		$docAccEngine->save();

		if ($docAccEngine->messagess() !== FALSE)
		{
			$this->db()->query ("UPDATE [e10doc_core_heads] SET docStateAcc = 9 WHERE ndx = %i", $recData['ndx']);
		}
		else
			$this->db()->query ("UPDATE [e10doc_core_heads] SET docStateAcc = 1 WHERE ndx = %i", $recData['ndx']);
	}
}
