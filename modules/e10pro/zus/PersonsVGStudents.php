<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';


use e10pro\zus\zusutils, e10\utils;


/**
 * Class PersonsVGStudents
 * @package e10pro\zus
 */
class PersonsVGStudents extends \lib\persons\PersonsVirtualGroup
{
	public function enumItems($columnId, $recData)
	{
		if ($columnId === 'virtualGroupItem')
			return \E10Pro\Zus\zusutils::pobocky($this->app(), TRUE);
		if ($columnId === 'virtualGroupItem2')
			return zusutils::uciteleNaPobocce($this->app(), $recData['virtualGroupItem'], TRUE);
		if ($columnId === 'virtualGroupItem3')
			return zusutils::obory($this->app());
		if ($columnId === 'virtualGroupItem4')
			return zusutils::rocniky($this->app());
		if ($columnId === 'virtualGroupItem5')
			return zusutils::predmety($this->app());

		return [];
	}

	public function addPosts($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $vgRecData)
	{
		$academicYear = \E10Pro\Zus\aktualniSkolniRok ();
		$today = utils::today('', $this->app());

		// -- individuální
		$q[] = 'SELECT vyuky.student AS studentNdx FROM [e10pro_zus_vyukyrozvrh] AS rozvrh';
		array_push ($q,' LEFT JOIN [e10pro_zus_vyuky] AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q,' LEFT JOIN [e10pro_zus_studium] AS studia ON vyuky.studium = studia.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND vyuky.skolniRok = %i', $academicYear);
		if ($vgRecData['virtualGroupItem'])
			array_push ($q,' AND rozvrh.pobocka = %i', $vgRecData['virtualGroupItem']);
		if ($vgRecData['virtualGroupItem2'])
			array_push ($q,' AND rozvrh.ucitel = %i', $vgRecData['virtualGroupItem2']);
		if ($vgRecData['virtualGroupItem3'])
			array_push ($q,' AND vyuky.svpObor = %i', $vgRecData['virtualGroupItem3']);
		if ($vgRecData['virtualGroupItem4'])
			array_push ($q,' AND vyuky.rocnik = %i', $vgRecData['virtualGroupItem4']);
		if ($vgRecData['virtualGroupItem5'])
			array_push ($q,' AND vyuky.svpPredmet = %i', $vgRecData['virtualGroupItem5']);

		array_push ($q, ' AND vyuky.stavHlavni = %i', 2);
		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

		array_push ($q, ' AND studia.stavHlavni = %i', 1);
		array_push ($q, ' AND (studia.datumUkonceniSkoly IS NULL OR studia.datumUkonceniSkoly > %t)', $today);
		array_push ($q, ' AND (studia.datumNastupuDoSkoly IS NULL OR studia.datumNastupuDoSkoly <= %t)', $today);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($this->recipientsMode === self::rmPersonsMsgs)
			{
				$this->addRecipientPerson ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['studentNdx']);
				continue;
			}

			$emails = $this->personsEmails($r['studentNdx']);
			foreach ($emails as $email)
			{
				$this->addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['studentNdx'], $email);
			}
		}

		// -- kolektivní
		$q = [];
		$q[] = 'SELECT studia.student AS studentNdx FROM [e10pro_zus_vyukyrozvrh] AS rozvrh';
		array_push ($q,' LEFT JOIN [e10pro_zus_vyuky] AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q,' LEFT JOIN [e10pro_zus_vyukystudenti] AS studenti ON rozvrh.vyuka = studenti.vyuka');
		array_push ($q,' LEFT JOIN [e10pro_zus_studium] AS studia ON studenti.studium = studia.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND vyuky.skolniRok = %i', $academicYear);
		if ($vgRecData['virtualGroupItem'])
			array_push ($q,' AND rozvrh.pobocka = %i', $vgRecData['virtualGroupItem']);
		if ($vgRecData['virtualGroupItem2'])
			array_push ($q,' AND rozvrh.ucitel = %i', $vgRecData['virtualGroupItem2']);
		if ($vgRecData['virtualGroupItem3'])
			array_push ($q,' AND vyuky.svpObor = %i', $vgRecData['virtualGroupItem3']);
		if ($vgRecData['virtualGroupItem4'])
			array_push ($q,' AND vyuky.rocnik = %i', $vgRecData['virtualGroupItem4']);
		if ($vgRecData['virtualGroupItem5'])
			array_push ($q,' AND vyuky.svpPredmet = %i', $vgRecData['virtualGroupItem5']);

		array_push ($q, ' AND vyuky.stavHlavni = %i', 2);
		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

		array_push ($q, ' AND studia.stavHlavni = %i', 1);
		array_push ($q, ' AND (studia.datumUkonceniSkoly IS NULL OR studia.datumUkonceniSkoly > %t)', $today);
		array_push ($q, ' AND (studia.datumNastupuDoSkoly IS NULL OR studia.datumNastupuDoSkoly <= %t)', $today);
		array_push ($q, ' AND (studenti.platnostDo IS NULL OR studenti.platnostDo >= %d', $today, ')');
		array_push ($q, ' AND (studenti.platnostOd IS NULL OR studenti.platnostOd <= %d', $today, ')');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($this->recipientsMode === self::rmPersonsMsgs)
			{
				$this->addRecipientPerson ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['studentNdx']);
				continue;
			}

			$emails = $this->personsEmails($r['studentNdx']);
			foreach ($emails as $email)
			{
				$this->addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['studentNdx'], $email);
			}
		}
	}
}
