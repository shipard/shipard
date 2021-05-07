<?php


namespace wkf\core\services;

use e10\Utility;


/**
 * Class UpgradeToNewBody
 * @package wkf\core\services
 */
class UpgradeToNewBody extends Utility
{
	public function upgrade()
	{
		$this->db()->query('UPDATE [wkf_core_issues] SET ',
			'[body] = [text], [text] = NULL, structVersion = 1',
			' WHERE ',
			' [structVersion] = %i', 0,
			' AND [source] > %i', 0
		);

		return;
		/*
		$startRow = 0;
		$blockSize = 100;
		while (1)
		{
			$cnt = 0;

			$q = [];
			array_push($q, 'SELECT * FROM wkf_core_issues');
			array_push($q, ' WHERE 1');
			array_push($q, ' AND [structVersion] = %i', 0);
			array_push($q, ' AND [source] > %i', 0);
			array_push($q, ' ORDER BY [ndx]');
			array_push($q, ' LIMIT ', $startRow, ', ', $blockSize);

			$rows = $this->db()->query($q);
			//echo "\n".\dibi::$sql."\n";
			foreach ($rows as $r)
			{
				$update = [
					'body' => $r['text'],
					'text' => NULL,
					'structVersion' => 1,
				];

//				$this->db()->query('UPDATE [wkf_core_issues] SET ', $update, ' WHERE ndx = %i', $r['ndx']);

				$this->db()->query('UPDATE [wkf_core_issues] SET ',
					'[body] = [text], [text] = NULL, structVersion = 1',
					' WHERE ndx = %i', $r['ndx']);


				//echo "\n".\dibi::$sql."\n";
				$cnt++;
				echo " {$r['ndx']} ";
			}

			if (!$cnt)
				break;

			$startRow += $blockSize;
			echo ".";
		}

		echo "\n";
		*/
	}

	public function run()
	{
		$this->upgrade();
	}
}