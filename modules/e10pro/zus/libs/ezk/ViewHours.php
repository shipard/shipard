<?php

namespace e10pro\zus\libs\ezk;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use \Shipard\Utils\Utils, \Shipard\Viewer\TableView;



class ViewHours extends TableView
{
	var $studentNdx = 0;
	var $studentInfo = NULL;

	public function init ()
	{
		$userContext = $this->app()->uiUserContext ();
		$ac = $userContext['contexts'][$this->app()->uiUserContextId] ?? NULL;
		if ($ac)
			$this->studentNdx = $ac['studentNdx'] ?? 0;

		parent::init();
		$this->studentInfo = $this->getStudentInfo($this->studentNdx);

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = FALSE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = utils::datef($item ['datum']);
		$listItem ['txt'] = $item['probiranaLatka'];
		$listItem ['i1'] = $item['zacatek'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$listItem['class'] = 'card';

		$listItem['card'] = [];

		$listItem['card']['header'] = [
			'class' => 'card-header',
			'values' => [
				[
					['text' => utils::datef($item ['datum'], '%S'), 'class' => 'h3'],
					['text' => ($item['vyukaTyp'] == 1) ? $item['predmetNazev'] : $item['predmetNazev'].' ('.$item['vyukaNazev'].')', 'class' => '']
				],
			]
		];

		if ($item['vyukaTyp'] === 0)
		{
			$kd = $this->db()->query('SELECT * FROM e10pro_zus_hodinydochazka WHERE hodina = %i', $item['ndx'], ' AND student = %i', $this->studentNdx)->fetch();
			if ($kd)
			{
				$item['pritomnost'] = $kd['pritomnost'];
				$item['klasifikaceZnamka'] = $kd['klasifikaceZnamka'];
			}
		}

		if ($item['klasifikaceZnamka'] !== '')
		{
			$znamkyHodnoceni = $this->app->cfgItem ('zus.znamkyHodnoceni');
			$znamka = $znamkyHodnoceni[$item['klasifikaceZnamka']]['sc'];
			$listItem['card']['header']['values'][] = ['text' => $znamka, 'class' => 'badge text-bg-info'];
		}

		$hourAttendanceTypes = $this->table->columnInfoEnum ('pritomnost');
		$pritomnost = $hourAttendanceTypes[$item['pritomnost']];
		$listItem['card']['header']['values'][] = ['text' => $pritomnost, 'class' => 'badge text-bg-secondary'];

		$listItem['card']['body'] = [
			'class' => 'card-body',
			'content' => [
				['type' => 'text', 'subtype' => 'plain', 'text' => $item['probiranaLatka']]
			]
		];

		return $listItem;
	}

	public function selectRows ()
	{
		$q = [];

		array_push ($q, 'SELECT hodiny.*, ');
		array_push ($q, ' vyuky.nazev AS vyukaNazev, vyuky.typ AS vyukaTyp, predmety.nazev AS predmetNazev');
		array_push ($q, ' FROM [e10pro_zus_hodiny] AS [hodiny]');
		array_push ($q, ' LEFT JOIN [e10pro_zus_vyuky] AS vyuky ON hodiny.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN [e10pro_zus_predmety] AS predmety ON vyuky.svpPredmet = predmety.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND hodiny.stavHlavni = %i', 3);
		array_push ($q, ' AND vyuka IN %in', $this->studentInfo['vyuky']);
		array_push($q, ' AND (');
    	array_push($q, '([vyuky].[typ] = %i', 1, ')');
			array_push($q, ' OR ');
			array_push ($q, '([vyuky].[typ] = %i', 0, ' AND EXISTS (',
											'SELECT hodina FROM e10pro_zus_hodinydochazka AS hodinyDochazka',
											' WHERE hodinyDochazka.student = %i', $this->studentNdx,
											' AND hodinyDochazka.hodina = hodiny.ndx',
											'))');
		array_push($q, ')');
		array_push ($q, ' ORDER BY [datum] DESC, [zacatek] DESC', $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function getStudentInfo($studentNdx)
	{
		$schoolYear = 2022;//zusutils::aktualniSkolniRok($this->app());
		$si = [
			'schoolYear' => $schoolYear,
			'studies' => [], 'vyuky' => []
		];

		// -- studium
		$q = [];
		array_push($q, 'SELECT studium.*');
		array_push($q, ' FROM [e10pro_zus_studium] AS studium');
		array_push($q, ' WHERE 1');
		array_push ($q, ' AND [stavHlavni] < %i', 4);
		array_push ($q, ' AND [skolniRok] = %s', $schoolYear);
		array_push ($q, ' AND studium.[student] = %i', $studentNdx);
		array_push ($q, ' ORDER BY [ndx]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$si['studies'][] = $r['ndx'];
		}

		// -- vyuky
		$q = [];
    array_push($q, 'SELECT vyuky.*');
    array_push($q, ' FROM [e10pro_zus_vyuky] AS vyuky');
    array_push($q, ' WHERE 1');
		array_push($q, ' AND (');
    	array_push($q, '([vyuky].[typ] = %i', 1, ' AND [studium] IN %in)', $si['studies']);

			array_push($q, ' OR ');
			array_push ($q, '([vyuky].[typ] = %i', 0, ' AND EXISTS (',
											'SELECT vyuka FROM e10pro_zus_vyukystudenti AS vyukyStudenti',
											' WHERE vyukyStudenti.[studium] IN %in', $si['studies'],
											' AND vyukyStudenti.vyuka = vyuky.ndx',
											'))');

		array_push($q, ')');
    array_push($q, ' AND [vyuky].[stav] != %i', 9800);
    array_push($q, ' AND [vyuky].[skolniRok] = %i', $schoolYear);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$si['vyuky'][] = $r['ndx'];
		}

		return $si;
	}

	public function createToolbar()
	{
		return [];
	}
}
