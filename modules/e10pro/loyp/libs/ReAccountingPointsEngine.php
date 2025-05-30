<?php

namespace e10pro\loyp\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;


/**
 * class ReAccountingPointsEngine
 */
class ReAccountingPointsEngine extends Utility
{
  /** @var \e10doc\core\TableHeads */
  var $tableHeads;

  var $loypNdx = 0;
	var $docNdx = 0;

	public function init ()
	{
		$this->tableHeads = $this->app()->table('e10doc.core.heads');
	}

	public function run ()
	{
		$this->doAllDocs();
	}

	public function doAllDocs ()
	{
		$docTypes = ['invno', 'purchase'];
		foreach ($docTypes as $docType)
			$this->doAllDocsOneDocType ($docType);
	}

	public function doAllDocsOneDocType ($docType)
	{
		$q = [];
		array_push($q, 'SELECT * FROM [e10doc_core_heads] as heads');
		array_push($q, ' WHERE docType = %s', $docType);
		array_push($q, ' AND heads.docState = %i', 4000);
    array_push($q, ' AND heads.loyp = %i', $this->loypNdx);

    /*
		if ($this->fiscalYear)
			array_push($q, ' AND heads.fiscalYear = %i', $this->fiscalYear);
		else
		{
			array_push($q, ' AND heads.dateAccounting >= %d', $this->dateFrom);
			array_push($q, ' AND heads.dateAccounting <= %d', $this->dateTo);
		}
    */

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

		$this->db()->query ("DELETE FROM [e10doc_debs_journal] WHERE [document] = %i", $docRecData['ndx'], ' AND [accRing] = %i', 120);

		$docAccEngine = new \e10doc\debs\libs\AccountingDocEngine($this->app());
		$docAccEngine->enabledAccRings = [120];
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

		if ($this->app()->debug)
			echo "\n";
	}
}
