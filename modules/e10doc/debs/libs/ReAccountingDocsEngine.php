<?php

namespace e10doc\debs\libs;
use \Shipard\Base\Utility;


/**
 * class ReAccountingDocsEngine
 */
class ReAccountingDocsEngine extends Utility
{
  /** @var \e10doc\core\TableHeads */
  var $tableHeads;

	var $fiscalYear = 0;
	var $dateFrom = NULL;
	var $dateTo = NULL;
	var $docNdx = 0;

	var $doReaccouning = FALSE;
	var $doAccBalances = FALSE;


	public function init ()
	{
		$this->tableHeads = $this->app()->table('e10doc.core.heads');

		$this->doAccBalances = TRUE;
	}

	public function run ()
	{
		$this->db()->query('DELETE FROM [e10doc_accBal_journal] WHERE fiscalYear = %i', $this->fiscalYear);
		$this->doAllDocs();
	}

	public function setFiscalYear ($fiscalYear)
	{
		$this->fiscalYear = $fiscalYear;
		$fy = $this->app->cfgItem('e10doc.acc.periods.'.$fiscalYear, NULL);
		if ($fy)
		{
			$this->dateFrom = $fy['begin'];
			$this->dateTo = $fy['end'];
		}
	}

	public function doAllDocs ()
	{
		$docTypes = ['invni', 'invno', 'cashreg', 'cmnbkp', 'purchase', 'cash', 'bank'];
		foreach ($docTypes as $docType)
			$this->doAllDocsOneDocType ($docType);
	}

	public function doAllDocsOneDocType ($docType)
	{
		$q = [];
		array_push($q, 'SELECT * FROM [e10doc_core_heads] as heads');
		array_push($q, ' WHERE docType = %s', $docType);
		array_push($q, ' AND heads.docState = %i', 4000);

		if ($this->fiscalYear)
			array_push($q, ' AND heads.fiscalYear = %i', $this->fiscalYear);
		else
		{
			array_push($q, ' AND heads.dateAccounting >= %d', $this->dateFrom);
			array_push($q, ' AND heads.dateAccounting <= %d', $this->dateTo);
		}

		if ($this->docNdx)
			array_push($q, ' AND heads.ndx = %i', $this->docNdx);

		array_push($q, ' ORDER BY heads.dateAccounting, heads.activateTimeLast, heads.ndx');

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$this->db()->begin();
			$this->doOneDoc($r);
			$this->db()->commit();
		}
	}

	protected function doOneDoc($docRecData)
	{
		if ($this->app()->debug)
			echo ("# {$docRecData['docNumber']} ({$docRecData['docType']}); {$docRecData['dateAccounting']}");

		if ($this->doReaccouning)
		{
			$docAccEngine = new \E10Doc\Debs\docAccounting ($this->app());
			$docAccEngine->setDocument ($docRecData);
			$docAccEngine->run();
			$docAccEngine->save();

			if ($docAccEngine->messagess() !== FALSE)
			{
				$this->err($docAccEngine->messagess());
				$this->db()->query ('UPDATE [e10doc_core_heads] SET docStateAcc = %i', 9, ' WHERE ndx = %i', $docRecData['ndx']);
			}
			else
				$this->db()->query ("UPDATE [e10doc_core_heads] SET docStateAcc = 1 WHERE ndx = %i", $docRecData['ndx']);
		}

		if ($this->doAccBalances)
		{
			$accBalCreator = new \e10doc\accBal\libs\AccBalanceCreator($this->app());
			$accBalCreator->setDocument($docRecData['ndx']);
			$accBalCreator->run();
		}

		if ($this->app()->debug)
			echo "\n";

		//unset ($docAccEngine);
	}

	protected function setDocStateFormActionBefore ($form, $docStateMain, $docState)
	{
	}
}
