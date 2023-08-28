<?php

namespace e10pro\zus\libs\ezk;


/**
 * class UserContext
 */
class UserContext extends \e10\users\libs\UserContext
{
  var $schoolYear = 0;
  var $students = [];

  protected function loadStudents()
  {
    $q = [];
    array_push($q, 'SELECT contacts.*,');
    array_push($q, ' persons.fullName AS personFullName, persons.id AS personId,');
    array_push($q, ' persons.firstName AS personFirstName, persons.lastName AS personLastName');
    array_push($q, ' FROM e10_persons_personsContacts AS [contacts]');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [contacts].person = [persons].ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [contactEmail] = %s', $this->contextCreator->userRecData['login']);
    array_push($q, ' ORDER BY persons.ndx');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if (!isset($this->students[$r['person']]))
      {
        $this->students[$r['person']] = [
          'fullName' => $r['personFullName'],
          'firstName' => $r['personFirstName'],
          'lastName' => $r['personLastName'],
        ];
      }
    }
  }

  protected function loadAll()
  {
    $this->schoolYear = 2022; //zusutils::aktualniSkolniRok($this->app());
    $this->loadStudents();

    foreach($this->students as $studentNdx => $studentInfo)
    {
      $this->getStudentInfo($studentNdx);
    }

    $this->contextCreator->contextData['ezk']['students'] = $this->students;

    foreach ($this->students as $studentNdx => $studentInfo)
    {
      if (!isset($studentInfo['studia']) || !count($studentInfo['studia']))
        continue;

      $cid = 'ezk-s-'.$studentNdx;
      $uc = [
        'id' => $cid,
        'title' => $studentInfo['fullName'],
        'shortTitle' => $studentInfo['firstName'],
        'studentNdx' => $studentNdx,
      ];

      $this->contextCreator->contextData['contexts'][$cid] = $uc;
    }
  }

	public function getStudentInfo($studentNdx)
	{
    $this->students[$studentNdx]['studia'] = [];
    $this->students[$studentNdx]['vyuky'] = [];

		// -- studium
		$q = [];
		array_push($q, 'SELECT studium.*');
		array_push($q, ' FROM [e10pro_zus_studium] AS studium');
		array_push($q, ' WHERE 1');
		array_push ($q, ' AND [stavHlavni] < %i', 4);
		array_push ($q, ' AND [skolniRok] = %s', $this->schoolYear);
		array_push ($q, ' AND studium.[student] = %i', $studentNdx);
    array_push ($q, ' AND studium.[stav] = %i', 1200);
		array_push ($q, ' ORDER BY [ndx]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->students[$studentNdx]['studia'][] = $r['ndx'];
		}

		// -- vyuky
    if (count($this->students[$studentNdx]['studia']))
    {
      $q = [];

      array_push($q, 'SELECT vyuky.*');
      array_push($q, ' FROM [e10pro_zus_vyuky] AS vyuky');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND (');
        array_push($q, '([vyuky].[typ] = %i', 1, ' AND [studium] IN %in)', $this->students[$studentNdx]['studia']);

        array_push($q, ' OR ');
        array_push ($q, '([vyuky].[typ] = %i', 0, ' AND EXISTS (',
                        'SELECT vyuka FROM e10pro_zus_vyukystudenti AS vyukyStudenti',
                        ' WHERE vyukyStudenti.[studium] IN %in', $this->students[$studentNdx]['studia'],
                        ' AND vyukyStudenti.vyuka = vyuky.ndx',
                        '))');

      array_push($q, ')');
      array_push($q, ' AND [vyuky].[stav] != %i', 9800);
      array_push($q, ' AND [vyuky].[skolniRok] = %i', $this->schoolYear);

      $rows = $this->db()->query($q);
      foreach ($rows as $r)
      {
        $this->students[$studentNdx]['vyuky'][] = $r['ndx'];
      }
    }
	}

  public function run()
  {
    $this->loadAll();
  }
}


