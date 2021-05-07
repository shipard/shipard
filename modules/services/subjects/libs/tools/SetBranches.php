<?php

namespace services\subjects\libs\tools;

use e10\utils, e10\json, e10\str, e10\Utility;


class SetBranches extends Utility
{
	var $nace2branches;
	var $branches;

	public function init ()
	{
		$this->loadDefinitions();
	}

	public function loadDefinitions ()
	{
		$this->branches = $this->app->cfgItem ('services.subjects.branches.branches');
		$this->nace2branches = $this->app->cfgItem ('services.subjects.branches.nace');
	}

	public function run()
	{
		$allParts = $this->app->cfgItem ('services.subjects.branches.parts');

		$dateStart = new \DateTime();
		echo "START: ".$dateStart->format('Y-m-d H:i:s')."\n";

		$this->init();

		$this->db()->query('DELETE FROM [services_subjects_subjectsBranches]');

		$rowNumber = 0;

		$q = [];
		array_push($q, 'SELECT * FROM [e10_base_nomenc] WHERE 1');
		array_push($q, ' AND [tableId] = %s', 'services.subjects.subjects');
		array_push($q, ' AND [nomencType] = %i', 2);
		array_push($q, ' ORDER BY recId');

		$this->db()->begin();
		$subjects = $this->db()->query($q);
		foreach ($subjects as $sub)
		{
			$subjectNdx = $sub['recId'];
			$naceNdx = $sub ['nomencItem'];

			if (!isset($this->nace2branches[$naceNdx]))
			{
				//echo "nace #{$naceNdx} not found\n";
				continue;
			}

			$naceBranchesParts = $this->nace2branches[$naceNdx];
			//$this->db()->query('DELETE FROM [services_subjects_subjectsBranches] WHERE [subject] = %i', $subjectNdx);

			foreach ($naceBranchesParts as $branchPartNdx)
			{
				if (!isset($allParts[$branchPartNdx]))
				{
					echo "#ERR: NACENDX: $naceNdx \n";
					continue;
				}

				$partDef = $allParts[$branchPartNdx];

				$newRec = ['subject' => $subjectNdx, 'branch' => $partDef['branch'], 'activity' => $partDef['a'], 'commodity' => $partDef['c']];
				$this->db()->query('INSERT INTO [services_subjects_subjectsBranches]', $newRec);
			}

			if ($rowNumber % 2500 === 0)
				echo '.';

			if ($rowNumber % 100 === 0)
			{
				$this->db()->commit();
				$this->db()->begin();
			}

			$rowNumber++;
			$this->db()->commit();
		}

		echo "\n";

		$dateDone = new \DateTime();
		echo "DONE: ".$dateDone->format('Y-m-d H:i:s')."; ";
		echo utils::dateDiffMinutes($dateDone, $dateStart);
		echo " mins, ".$rowNumber." subjects\n";
	}
}
