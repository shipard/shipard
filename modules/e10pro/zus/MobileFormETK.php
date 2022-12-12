<?php

namespace e10pro\zus;

use function E10\searchArray;
use \lib\ui\FormDocumentSimple;


/**
 * Class MobileFormETK
 * @package e10pro\zus
 */
class MobileFormETK extends FormDocumentSimple
{
	var $recDataVyuka;

	public function init()
	{
		parent::init();
		$this->classId = 'e10pro.zus.MobileFormETK';
		$this->setTable('e10pro.zus.hodiny');
	}

	public function createForm ()
	{
		$this->recDataVyuka = $this->app->loadItem($this->recData['vyuka'], 'e10pro.zus.vyuky');

		$this->addColumnInput('probiranaLatka');

		if ($this->recDataVyuka['typ'] == 1)
		{
			$this->addColumnInput('pritomnost');
			$this->addColumnInput('klasifikaceZnamka');
			$this->addColumnInput('klasifikacePoznamka');
		}

		$this->addColumnInput('vyuka', ['hidden' => 1]);
		$this->addColumnInput('rozvrh', ['hidden' => 1]);
		$this->addColumnInput('ucebna', ['hidden' => 1]);
		$this->addColumnInput('pobocka', ['hidden' => 1]);
		$this->addColumnInput('ucitel', ['hidden' => 1]);
		$this->addColumnInput('zacatek', ['hidden' => 1]);
		$this->addColumnInput('konec', ['hidden' => 1]);
		$this->addColumnInput('datum', ['hidden' => 1]);

		if (isset($this->recData['datum']) && $this->recData['datum'] instanceof \DateTime)
			$this->recData['datum'] = $this->recData['datum']->format('Y-m-d');

		// -- contacts
		if ($this->recDataVyuka['typ'] == 1)
		{
			$vyuka = $this->app->loadItem($this->recData['vyuka'], 'e10pro.zus.vyuky');
			$tablePersons = $this->app->table ('e10.persons.persons');
			$studentProperties = $tablePersons->loadProperties ($vyuka['student']);
			//$this->addLine(['text' => 'testík => '.json_encode($studentProperties[$vyuka['student']]), 'class' => 'e10-error block padd5']);

			$this->addLine(['text' => 'Kontaktní údaje', 'class' => 'h2 block']);
			$this->addContactInfo ($studentProperties[$vyuka['student']], 'e10-zus-zz-1', 'e10-zus-zz');
			$this->addContactInfo ($studentProperties[$vyuka['student']], 'e10-zus-zz-2', 'e10-zus-zz2');
		}

		if ($this->recDataVyuka['typ'] == 0)
			$this->addStudentsAttendance();

		// -- past hours
		$this->addPastHours();
	}

	protected function addContactInfo ($properties, $type, $base)
	{
		if (!isset($properties[$type]))
			return;

		$line = [];
		$v = searchArray($properties[$type], 'pid',$base.'-jmeno');
		if ($v)
			$line[] = ['text' => $v['text'].': ', 'class' => ''];

		$v = searchArray($properties[$type], 'pid',$base.'-telefon');
		if ($v)
			$line[] = ['text' => $v['text'], 'class' => '', 'icon' => 'icon-phone', 'url' => 'tel:'.$v['text']];

		$v = searchArray($properties[$type], 'pid',$base.'-email');
		if ($v)
			$line[] = ['text' => $v['text'], 'class' => '', 'icon' => 'icon-envelope', 'url' => 'mailto:'.$v['text']];

		if (!count($line) && isset ($properties['contacts']) && $base === 'e10-zus-zz')
			$line = $properties['contacts'];

		$line[] = ['text' => '', 'class' => 'block'];

		$this->addLine($line);
	}

	protected function addContactInfo2 ($properties, $type, $base, &$line)
	{
		if (!isset($properties[$type]))
			return;

//		$v = searchArray($properties[$type], 'pid',$base.'-jmeno');
//		if ($v)
//			$line[] = ['text' => $v['text'].': ', 'class' => ''];

		$v = searchArray($properties[$type], 'pid',$base.'-telefon');
		if ($v)
			$line[] = ['text' => $v['text'], 'class' => 'pull-right', 'icon' => 'icon-phone', 'xx-url' => 'tel:'.$v['text']];

//		$v = searchArray($properties[$type], 'pid',$base.'-email');
//		if ($v)
//			$line[] = ['text' => $v['text'], 'class' => 'pull-right', 'icon' => 'icon-envelope', 'xx-url' => 'mailto:'.$v['text']];

		if (!count($line) && isset ($properties['contacts']) && $base === 'e10-zus-zz')
		{
			foreach ($properties['contacts'] as $cci)
			{
				$cci['class'] = 'pull-right';
				$line[] = $cci;
			}
		}
	}

	protected function addPastHours()
	{
		$this->addLine(['text' => 'Minulá látka', 'class' => 'h2 block']);

		$q[] = 'SELECT hodiny.*, ucitele.firstName, ucitele.lastName ';
		array_push($q, ' FROM e10pro_zus_hodiny AS hodiny');
		array_push($q, ' LEFT JOIN e10_persons_persons AS [ucitele] ON hodiny.ucitel = ucitele.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND hodiny.vyuka = %i', $this->recData['vyuka']);
		array_push($q, ' AND hodiny.stav != %i', 9800);
		array_push($q, ' ORDER BY hodiny.[datum] DESC, hodiny.[zacatek]');
		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['probiranaLatka'] == '')
				continue;

			$line = [
				[
					'text' => $r['probiranaLatka'],
					'action' => 'setInputValue', 'actionClass' => 'e10-trigger-action width100 padd5 block ', 'data-inputid' => 'probiranaLatka',
					'element' => 'span'
				]
			];

			$line[] = ['text' => ' ', 'class' => 'block'];

			$this->addLine ($line);
		}
	}

	protected function addStudentsAttendance()
	{
		$this->addLine (['text' => 'Přítomnost studentů', 'class' => 'h1 block']);

		$tablePersons = $this->app->table ('e10.persons.persons');

		$attEnum = ["0" => "---", "1" => "Přítomen", "2" => "Nepřítomen - omluven", "3" => "Nepřítomen neomluven", "4" => "Státní svátek", "5" => "Prázdniny", "6" => "Ředitelské volno", "7" => "Volno"];

		$datum = $this->recData['datum'];

		// -- create input grid
		$q[] = 'SELECT studenti.*, studia.student AS studentNdx, persons.fullName AS studentName, studia.nazev AS studiumNazev';
		array_push ($q, ' FROM [e10pro_zus_vyukystudenti] AS studenti ');
		array_push ($q, ' LEFT JOIN [e10pro_zus_studium] AS studia ON studenti.studium = studia.ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON studia.student = persons.ndx');
		array_push ($q, ' WHERE studenti.[vyuka] = %i', $this->recData['vyuka']);
		array_push ($q, ' AND (');
		array_push ($q, ' (studia.datumUkonceniSkoly IS NULL OR studia.datumUkonceniSkoly >= %d', $datum, ')');
		array_push ($q, ' AND (studia.datumNastupuDoSkoly IS NULL OR studia.datumNastupuDoSkoly <= %d', $datum, ')');
		array_push ($q, ')');
		array_push($q, ' AND (');
		array_push($q, '(studenti.platnostDo IS NULL OR studenti.platnostDo >= %d', $datum, ')');
		array_push($q, ' AND (studenti.platnostOd IS NULL OR studenti.platnostOd <= %d', $datum, ')');
		array_push($q, ')');

		$studentsRows = $this->db()->query($q);
		foreach ($studentsRows as $s)
		{
			$studentNdx = $s['studentNdx'];
			$colId = 'kolektivni_pritomnost-'.$studentNdx.'-'.$s['studium'];
			$this->recData[$colId] = 1;


			$studentProperties = $tablePersons->loadProperties ($studentNdx);
			$label = [['text' => $s['studentName'/*'studiumNazev'*/], 'icon' => 'icon-user', 'class' => 'e10-bold']];
			$this->addContactInfo2 ($studentProperties[$studentNdx], 'e10-zus-zz-1', 'e10-zus-zz', $label);
			//$this->addContactInfo2 ($studentProperties[$studentNdx], 'e10-zus-zz-2', 'e10-zus-zz2', $label);

			$this->addInputEnum ($colId, $attEnum, ['label' => $label]);
		}

		// -- load
		if (!isset($this->recData['ndx']) || !$this->recData['ndx'])
			return;

		$q = [];
		$q[] = 'SELECT * FROM [e10pro_zus_hodinydochazka]';
		array_push($q, ' WHERE [hodina] = %i', $this->recData['ndx']);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$colId = 'kolektivni_pritomnost-'.$r['student'].'-'.$r['studium'];
			$this->recData[$colId] = $r['pritomnost'];
		}
	}

	public function title1 ()
	{
		$this->recDataVyuka = $this->app->loadItem($this->recData['vyuka'], 'e10pro.zus.vyuky');

		if ($this->recDataVyuka && $this->recDataVyuka['nazev'] !== '')
			return $this->recDataVyuka['nazev'];

		return 'Nová hodina';
	}

	protected function save ()
	{
		$studentsAttendance = [];
		$keysToDelete = [];

		foreach ($this->recData as $key => $value)
		{
			if (substr($key, 0, 21) !== 'kolektivni_pritomnost')
				continue;
			$p = explode('-', $key);
			$studentNdx = intval($p[1]);
			if (!$studentNdx)
				continue;
			$studiumNdx = intval($p[2]);
			if (!$studiumNdx)
				continue;
			$studentsAttendance[$key] = ['attendance' => $value, 'student' => $studentNdx, 'studium' => $studiumNdx];
			$keysToDelete[] = $key;
		}

		foreach ($keysToDelete as $key)
			unset($this->recData[$key]);

		$this->recData['stav'] = 4000;
		$this->recData['stavHlavni'] = 3;

		parent::save();

		foreach ($studentsAttendance as $sa)
		{
			$studentNdx = $sa['student'];
			$studiumNdx = $sa['studium'];
			$attendanceValue = $sa['attendance'];
			$exist = $this->db()->query('SELECT * FROM [e10pro_zus_hodinydochazka] WHERE [hodina] = %i', $this->recData['ndx'],
				' AND [student] = %i', $studentNdx, ' AND [studium] = %i', $studiumNdx)->fetch();

			if ($exist)
			{
				$this->db()->query('UPDATE [e10pro_zus_hodinydochazka] SET [pritomnost] = %i', $attendanceValue, ' WHERE [ndx] = %i', $exist['ndx']);
			}
			else
			{
				$newItem = ['hodina' => $this->recData['ndx'], 'student' => $studentNdx, 'studium' => $studiumNdx, 'pritomnost' => $attendanceValue];
				$this->db()->query ('INSERT INTO [e10pro_zus_hodinydochazka] ', $newItem);
			}
		}
	}
}
