<?php

namespace lib\docs\utils;

require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';

use e10\utils, e10\Utility, e10Doc\Core\e10utils;


/**
 * Class DocNumbersRepair
 * @package lib\docs\utils
 */
class DocNumbersRepair extends Utility
{
	var $tableHeads;

	var $segmentDocType = '';
	var $segmentFiscalYear = 0;
	var $segmentDbCounter = 0;

	var $doRepair = FALSE;

	public function init()
	{
		$this->tableHeads = $this->app()->table('e10doc.core.heads');




		$this->segmentDocType = 'invno';
		$this->segmentFiscalYear = 5;
		$this->segmentDbCounter = 3;

	}

	public function makeDocNumberFormula ($recData)
	{
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $recData['docType']);
		$formula = $this->app()->cfgItem ('e10.options.docNumbers.' . $recData['docType'], '');
		if ($formula == '')
			$formula = $this->app()->cfgItem ('e10.docs.types.' . $recData['docType'].'.docNumber', '');
		if ($formula == '')
			$formula = '%D%r%C%4';

		return $formula;
	}

	function checkSegment ()
	{
		$q[] = 'SELECT * FROM [e10doc_core_heads] WHERE 1';

		array_push ($q, ' AND [docType] = %s', $this->segmentDocType);
		array_push ($q, ' AND [fiscalYear] = %s', $this->segmentFiscalYear);
		array_push ($q, ' AND [dbCounter] = %s', $this->segmentDbCounter);

		array_push ($q, ' AND [docStateMain] < %i', 4);

		array_push ($q, ' ORDER BY [docNumber], ndx');


		$docNumberOderLen = 0;
		$formula = '';
		$wantedDocNumberOrder = 1;

		$cntWrong = 0;
		$wrongNumbers = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!$docNumberOderLen)
			{
				$formula = $this->makeDocNumberFormula($r);
				$docNumberOderLen = intval(substr(strrchr($formula, "%"), 1));
				echo " * docNumberOrderLen is $docNumberOderLen\n";
			}


			$thisDocNumber = $r['docNumber'];

			$thisDocNumberOrder = intval(substr($thisDocNumber, -$docNumberOderLen));

			if ($thisDocNumberOrder !== $wantedDocNumberOrder)
			{
				$wantedDocNumber = substr($thisDocNumber, 0, -$docNumberOderLen) . sprintf ("%0{$docNumberOderLen}d", $wantedDocNumberOrder);

				//echo " - {$r['docNumber']} --> $wantedDocNumber | ($thisDocNumberOrder --> $wantedDocNumberOrder)\n";


				$wrongNumbers[] = ['ndx' => $r['ndx'], 'oldDN' => $thisDocNumber, 'newDN' => $wantedDocNumber, 'newDO' => $wantedDocNumberOrder];

				$cntWrong++;
			}

			$wantedDocNumberOrder++;
		}

		if ($cntWrong)
		{
			echo " !!! $cntWrong bad numbers...\n";
			if ($this->doRepair)
				$this->repairSegment($wrongNumbers);
		}
	}

	function repairSegment ($wrongNumbers)
	{
		echo " ! repairing...\n";
		foreach ($wrongNumbers as $wn)
		{
			$this->db()->query ('UPDATE [e10doc_core_heads] SET docNumber = %s', $wn['newDN'], ' WHERE [ndx] = %i', $wn['ndx']);
			$this->db()->query ('UPDATE [e10doc_debs_journal] SET docNumber = %s', $wn['newDN'], ' WHERE [document] = %i', $wn['ndx']);
		}
	}

	public function run ()
	{
		$this->init();
		$this->checkSegment();
	}
}