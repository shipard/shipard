<?php

namespace services\subjects\libs\imports\czall;

use e10\utils, e10\json, e10\str, e10\Utility;


/**
 * Class ImportCZAllRegions
 * @package services\subjects\libs\imports\czall
 */
class ImportCZAllRegions extends Utility
{
	var $nomencNutsNdx = 0;
	var $nomencEnumRegion1;
	var $nomencEnumRegion2;
	var $nutsConvertTable = [];

	public function init ()
	{
		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nuts')->fetch();
		if ($nomencType)
			$this->nomencNutsNdx = $nomencType['ndx'];

		$this->nomencEnumRegion1 = $this->app()->cfgItem('nomenc.cz-nuts-3');
		$this->nomencEnumRegion2 = $this->app()->cfgItem('nomenc.cz-nuts-4');

		foreach ($this->nomencEnumRegion1 as $key => $value)
		{
			$this->nutsConvertTable[$value['ndx']] = $value;
			$this->nutsConvertTable[$value['ndx']]['enumNdx'] = $key;
			$this->nutsConvertTable[$value['ndx']]['column'] = 'region1';
		}
		foreach ($this->nomencEnumRegion2 as $key => $value)
		{
			$this->nutsConvertTable[$value['ndx']] = $value;
			$this->nutsConvertTable[$value['ndx']]['enumNdx'] = $key;
			$this->nutsConvertTable[$value['ndx']]['column'] = 'region2';
		}
	}

	public function run()
	{
		$dateStart = new \DateTime();
		echo "START: ".$dateStart->format('Y-m-d H:i:s')."\n";


		$this->init();

		$rowNumber = 1;
		$cntRec = $this->db()->query ('SELECT COUNT(*) AS [cnt] FROM [e10_base_nomenc] WHERE [nomencType] = %i', $this->nomencNutsNdx,
				' AND [tableId] = %s', 'services.subjects.subjects')->fetch();
		$fileSize = $cntRec['cnt'];

		$rows = $this->db()->query ('SELECT * FROM [e10_base_nomenc] WHERE [nomencType] = %i', $this->nomencNutsNdx,
				' AND [tableId] = %s', 'services.subjects.subjects');
		$timeStart = time();
		echo "Importing ".utils::nf($fileSize)." recs...\n";
		foreach ($rows as $r)
		{
			$subjectNdx = $r['recId'];

			$cfg = $this->nutsConvertTable[$r['nomencItem']];

//			echo json_encode($cfg)."\n";

			$this->db()->query('UPDATE [services_subjects_subjects] SET ['.$cfg['column'].'] = %i', $cfg['enumNdx'], ' WHERE [ndx] = ', $subjectNdx);

			$rowNumber++;

			if ($rowNumber % 25000 === 0)
			{
				$filePos = $rowNumber;
				$timeNow = time();
				$doneRatio = $filePos / $fileSize;
				$etaSecs = intval(($timeNow - $timeStart) / $doneRatio);
				$etaMinutes = intval($etaSecs / 60);
				$eta = utils::minutesToTime($etaMinutes);
				$timeEnd = $timeStart + $etaSecs + date('Z');
				$etaDate = new \DateTime('@'.$timeEnd);
				$etaDateStr = $etaDate->format ('H:i:s');

				echo "~ ".utils::nf($rowNumber)." recs, ".utils::nf(($doneRatio) * 100, 1)." %, ETA $etaDateStr\n";
			}

			//if ($rowNumber > 10000)
			//	break;
		}

		$dateDone = new \DateTime();
		echo "DONE: ".$dateDone->format('Y-m-d H:i:s')."; ";
		echo utils::dateDiffMinutes($dateDone, $dateStart);
		echo " mins\n";
	}
}
