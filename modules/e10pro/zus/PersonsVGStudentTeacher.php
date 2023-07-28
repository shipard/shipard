<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';


use e10pro\zus\zusutils, e10\utils;


/**
 * Class PersonsVGStudentTeacher
 * @package e10pro\zus
 */
class PersonsVGStudentTeacher extends \lib\persons\PersonsVirtualGroup
{
	public function enumItems($columnId, $recData)
	{
		return zusutils::ucitele($this->app(), FALSE);
	}

	public function addPosts($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $vgRecData)
	{
		$academicYear = \E10Pro\Zus\aktualniSkolniRok ();
		$today = utils::today('', $this->app());

		$q[] = 'SELECT studium.student AS studentNdx FROM [e10pro_zus_studiumpre] AS predmety';
		array_push ($q,' LEFT JOIN [e10pro_zus_studium] AS studium ON predmety.studium = studium.ndx');
		array_push ($q,' LEFT JOIN [e10_persons_persons] AS student ON studium.student = student.ndx ');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND studium.skolniRok = %i', $academicYear);
		array_push ($q,' AND predmety.ucitel = %i', $vgRecData['virtualGroupItem']);
		array_push ($q,' AND studium.stavHlavni = %i', 1);
		array_push ($q,' AND (studium.datumUkonceniSkoly IS NULL OR studium.datumUkonceniSkoly > %t)', $today);
		array_push ($q,' AND (studium.datumNastupuDoSkoly IS NULL OR studium.datumNastupuDoSkoly <= %t)', $today);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$emails = $this->personsEmails($r['studentNdx']);

			foreach ($emails as $email)
			{
				$this->addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['studentNdx'], $email);
			}
		}
	}
}