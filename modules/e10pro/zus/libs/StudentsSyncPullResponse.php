<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \e10pro\zus\zusutils;


/**
 * class StudentsSyncPullResponse
 */
class StudentsSyncPullResponse extends \e10pro\bume\libs\PersonsSyncPullResponse
{
  protected function checkPersonItem(&$personItem)
  {
    $schoolYear = zusutils::aktualniSkolniRok();
		$q = [];
		array_push($q, 'SELECT studium.*, teachers.fullName AS teacherFullName');
		array_push($q, ' FROM [e10pro_zus_studium] as studium ');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS teachers ON studium.ucitel = teachers.ndx');
		array_push($q, ' WHERE [stav] = %i', 1200);
		array_push($q, ' AND skolniRok = %s', $schoolYear);
    array_push($q, ' AND student = %i', $personItem['rec']['ndx']);
    array_push($q, ' ORDER BY studium.ndx');
    array_push($q, ' LIMIT 1');

    $existed = $this->db()->query($q)->fetch();

    if ($existed)
    {
      $personItem['other'] = [
        'teacherName' => $existed['teacherFullName'],
      ];
    }
  }
}

