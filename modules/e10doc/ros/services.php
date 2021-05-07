<?php

namespace e10doc\ros;


/**
 * Class ModuleServices
 * @package e10doc\ros
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function resendFailedRosRecords ()
	{
		$tableHeads = $this->app->table ('e10doc.core.heads');

		$maxTime = new \DateTime();
		$maxTime->sub (new \DateInterval('PT1H'));

		$minTime = new \DateTime();
		$minTime->sub (new \DateInterval('PT48H'));

		$q [] = 'SELECT heads.ndx as docNdx, heads.docNumber, rosJournal.resultCode1, rosJournal.resultCode2, rosJournal.dateSent ';
		array_push ($q, ' FROM e10doc_core_heads as heads');
		array_push ($q, '	LEFT JOIN e10doc_ros_journal as rosJournal ON heads.rosRecord = rosJournal.ndx');
		array_push ($q, ' WHERE heads.docState IN %in', [4000, 4100]);
		array_push ($q, ' AND heads.rosReg != 0');
		array_push ($q, ' AND (heads.activateTimeLast < %t', $maxTime, ' AND heads.activateTimeLast > %t)', $minTime);
		array_push ($q, ' AND heads.rosState > 1');
		array_push ($q, ' ORDER BY activateTimeLast');

		$rows = $this->app->db()->query ($q);

		foreach ($rows as $r)
		{
			$recData = $tableHeads->loadItem($r['docNdx']);
			$tableHeads->doRos($recData);
		}
	}

	public function onCronHourly ()
	{
		$this->resendFailedRosRecords();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'hourly': $this->onCronHourly(); break;
		}
		return TRUE;
	}
}
