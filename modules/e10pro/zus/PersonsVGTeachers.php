<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';


use e10pro\zus\zusutils;


/**
 * Class PersonsVGTeachers
 * @package e10pro\zus
 */
class PersonsVGTeachers extends \lib\persons\PersonsVirtualGroup
{
	public function enumItems($columnId, $recData)
	{
		if ($columnId === 'virtualGroupItem')
			return \E10Pro\Zus\zusutils::pobocky($this->app(), TRUE);
		if ($columnId === 'virtualGroupItem2')
			return zusutils::obory($this->app());
		if ($columnId === 'virtualGroupItem3')
			return zusutils::rocniky($this->app());
		if ($columnId === 'virtualGroupItem4')
			return zusutils::predmety($this->app());

		return [];
	}

	public function addPosts($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $vgRecData)
	{
		$academicYear = zusutils::aktualniSkolniRok ($this->app());

		// -- individuální
		$q[] = 'SELECT vyuky.ucitel AS ucitelNdx FROM [e10pro_zus_vyukyrozvrh] AS rozvrh';
		array_push ($q,' LEFT JOIN [e10pro_zus_vyuky] AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND vyuky.skolniRok = %i', $academicYear);
		if ($vgRecData['virtualGroupItem'])
			array_push ($q,' AND rozvrh.pobocka = %i', $vgRecData['virtualGroupItem']);
		if ($vgRecData['virtualGroupItem2'])
			array_push ($q,' AND vyuky.svpObor = %i', $vgRecData['virtualGroupItem2']);
		if ($vgRecData['virtualGroupItem3'])
			array_push ($q,' AND vyuky.rocnik = %i', $vgRecData['virtualGroupItem3']);
		if ($vgRecData['virtualGroupItem4'])
			array_push ($q,' AND vyuky.svpPredmet = %i', $vgRecData['virtualGroupItem4']);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$emails = $this->personsEmails($r['ucitelNdx']);

			foreach ($emails as $email)
			{
				$this->addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['ucitelNdx'], $email);
			}
		}
	}
}