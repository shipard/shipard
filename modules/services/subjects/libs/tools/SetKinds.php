<?php

namespace services\subjects\libs\tools;

use e10\utils, e10\Utility;


class SetKinds extends Utility
{
	var $definitions = [];

	public function init ()
	{
		$this->loadDefinitions();
	}

	public function loadDefinitions ()
	{
		$q = 'SELECT * FROM [services_subjects_kinds] WHERE docState != 9800 ORDER BY [fullName], [ndx]';
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
					'recData' => $r->toArray(),
					'nomenc' => [],
			];

			$qn = [];
			$qn[] = 'SELECT * FROM [e10_base_nomenc] WHERE 1';
			array_push ($qn, ' AND [recId] = %i', $r['ndx']);
			array_push ($qn, ' AND [tableId] = %s', 'services.subjects.kinds');

			$rowsB = $this->db()->query($qn);
			foreach ($rowsB as $rn)
			{
				$item['nomenc'][] = ['type' => $rn['nomencType'], 'item' => $rn['nomencItem']];
			}

			$this->definitions[] = $item;
		}
	}

	public function run()
	{
		$dateStart = new \DateTime();
		echo "START: ".$dateStart->format('Y-m-d H:i:s')."\n";

		$this->init();

		$rowNumber = 0;

		foreach ($this->definitions as $def)
		{
			if (!count($def['nomenc']))
				continue;

			echo "# ".$def['recData']['fullName']." ";

			$q = [];
			array_push($q, 'SELECT recId FROM [e10_base_nomenc] WHERE 1');
			array_push($q, ' AND [tableId] = %s', 'services.subjects.subjects');
			array_push($q, ' AND (');
			$first = 1;
			foreach ($def['nomenc'] as $n)
			{
				if (!$first)
					array_push($q, ' OR ');
				array_push($q, '(', '[nomencType] = %i', $n['type'], ' AND [nomencItem] = %i', $n['item'], ')');
				$first = 0;
			}
			array_push($q, ')');

			$this->db()->begin();
			$subjects = $this->db()->query($q);
			foreach ($subjects as $sub)
			{
				$subjectNdx = $sub['recId'];
				$this->db()->query('UPDATE [services_subjects_subjects] SET [kind] = %i', $def['recData']['ndx'], ' WHERE ndx = %i', $subjectNdx);

				if ($rowNumber % 2500 === 0)
					echo '.';

				if ($rowNumber % 100 === 0)
				{
					$this->db()->commit();
					$this->db()->begin();
				}

				$rowNumber++;
			}
			$this->db()->commit();

			echo "\n";
		}

		$dateDone = new \DateTime();
		echo "DONE: ".$dateDone->format('Y-m-d H:i:s')."; ";
		echo utils::dateDiffMinutes($dateDone, $dateStart);
		echo " mins, ".$rowNumber." subjects\n";
	}
}
