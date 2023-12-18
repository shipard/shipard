<?php

namespace e10pro\zus\libs\ezk;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use \Shipard\Utils\Utils, \Shipard\Viewer\TableView;


/**
 * class ViewHours
 */
class ViewHours extends TableView
{
	var $studentNdx = 0;
	var $userContext = NULL;

	var $presences = [
		0 => ["title" => "Nezadáno", "icon" => "system/iconWarning", "class" => "shpd-text-danger"],
		1 => ["title" => "Přítomen", "icon" => "user/check", "class" => "shpd-text-success"],
		2 => ["title" => "Nepřítomen / omluven", "icon" => "user/timesCircle", "class" => "shpd-text-warning"],
		2 => ["title" => "Nepřítomen / NEomluven", "icon" => "user/times", "class" => "shpd-text-danger"],
		4 => ["title" => "Státní svátek", "icon" => "user/happy", "class" => "shpd-text-secondary"],
		5 => ["title" => "Prázdiny", "icon" => "user/happy", "class" => "shpd-text-secondary"],
		6 => ["title" => "Ředitelské volno", "icon" => "user/happy", "class" => "shpd-text-secondary"],
		7 => ["title" => "Volno", "icon" => "user/happy", "class" => "shpd-text-secondary"],
	];

	public function init ()
	{
		$userContexts = $this->app()->uiUserContext ();
		$ac = $userContexts['contexts'][$this->app()->uiUserContextId] ?? NULL;
		if ($ac)
			$this->studentNdx = $ac['studentNdx'] ?? 0;
		$this->userContext = $userContexts['ezk']['students'][$this->studentNdx];

		$this->classes = ['viewerWithCards'];
		$this->enableToolbar = FALSE;

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->uiSubTemplate = 'modules/e10pro/zus/libs/ezk/subtemplates/hoursRow';
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = utils::datef($item ['datum']);
		$listItem ['txt'] = $item['probiranaLatka'];
		$listItem ['i1'] = $item['zacatek'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$listItem['class'] = 'shpd-card p-3';

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

			$listItem['grade'] = $znamka;
		}

		$hourAttendanceTypes = $this->table->columnInfoEnum ('pritomnost');
		$pritomnost = $hourAttendanceTypes[$item['pritomnost']];

		// -----
		$listItem['presence'] = $pritomnost;
		$listItem['presenceCfg'] = $this->presences[$item['pritomnost']];
		$listItem ['date'] = utils::datef($item ['datum']);
		$listItem ['txt'] = trim($item['probiranaLatka']);
		$listItem ['subjectName'] = $item['predmetNazev'];
		$listItem ['homeWork'] = $item['domaciUkol'];
		$listItem ['withHomeWork'] = $item['sDomacimUkolem'];
		// ------

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
		if (isset($this->userContext['vyuky']) && count($this->userContext['vyuky']))
			array_push ($q, ' AND vyuka IN %in', $this->userContext['vyuky']);
		else
			array_push ($q, ' AND vyuka = %i', -1);
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

	public function createToolbar()
	{
		return [];
	}
}
