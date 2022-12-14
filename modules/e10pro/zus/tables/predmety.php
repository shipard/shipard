<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';
use \e10\TableView, \e10\TableForm, \e10\DbTable, \e10\json;


/**
 * Class TablePredmety
 * @package E10Pro\Zus
 */
class TablePredmety extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.predmety', 'e10pro_zus_predmety', 'Předměty');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['nazev']];
		$hdr ['icon'] = ($recData['typVyuky'] === 0) ? 'iconGroupClass' : 'system/iconUser';;

		return $hdr;
	}

	public function saveConfig ()
	{
		$ca = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10pro_zus_predmety] WHERE docState != 9800 ORDER BY [nazev], [pos]');
		forEach ($rows as $r)
		{
			$ca [$r['ndx']] = ['id' => $r['ndx'], 'nazev' => $r ['nazev'], 'svp' => $r ['svp'], 'obor' => $r ['obor'], 'oddeleni' => $r ['oddeleni']];
		}

		forEach ($ca as $predmetNdx => &$predmet)
		{
			$ppRows = $this->app()->db->query (
				'SELECT doclinks.dstRecId FROM [e10_base_doclinks] as doclinks',
				' WHERE doclinks.linkId = %s', 'zus-predmety-podobne',
				' AND doclinks.srcRecId = %i', $predmetNdx
			);

			foreach ($ppRows as $pl)
			{
				$predmet ['podobne'][] = $pl['dstRecId'];
				//$ca [$pl['dstRecId']]['podobne'][] = $predmetNdx;
			}
		}

		// -- save to file
		$cfg ['e10pro']['zus']['predmety'] = $ca;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.zus.predmety.json', json::lint ($cfg));
	}
}


/**
 * Class ViewPredmety
 * @package E10Pro\Zus
 */
class ViewPredmety extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['nazev'];
		$listItem ['icon'] = ($item['typVyuky'] === 0) ? 'iconGroupClass' : 'system/iconUser';;

		if ($item ['svp'])
			$listItem ['t2'][] = ['text' => $this->app()->cfgItem ("e10pro.zus.svp.{$item ['svp']}.nazev")];

		if ($item ['obor'])
			$listItem ['t2'][] = ['text' => 'obor '.$this->app()->cfgItem ("e10pro.zus.obory.{$item ['obor']}.nazev")];

		if ($item ['oddeleni'])
			$listItem ['t2'][] = ['text' => $this->app()->cfgItem ("e10pro.zus.oddeleni.{$item ['oddeleni']}.nazev")];

		if ($item['pos'])
			$listItem['i2'] = ['icon' => 'icon-sort', 'text' => strval ($item['pos'])];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10pro_zus_predmety] WHERE 1';

		$this->defaultQuery($q);

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([nazev] LIKE %s OR [nazevZkraceny] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[nazev]', '[pos]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormPredmety
 * @package E10Pro\Zus
 */
class FormPredmety extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('maximize', 1);
    $this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

		$this->layoutOpen (TableForm::ltHorizontal);
			$this->layoutOpen (TableForm::ltForm);
				$this->addColumnInput ('nazev');
				$this->addColumnInput ('typVyuky');

				$this->addColumnInput ('svp');
				$this->addColumnInput ('obor');
				$this->addColumnInput ('oddeleni');
				$this->addColumnInput ('id');
				$this->addColumnInput ('pos');
				$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
			$this->layoutClose ();
		$this->layoutClose ();

		$this->closeForm ();
	}
}

/**
 * Class ViewPredmetyCombo
 * @package E10Pro\Zus
 */
class ViewPredmetyCombo extends ViewPredmety
{
	public function defaultQuery (&$q)
	{
		$typVyuky = intval($this->queryParam('typVyuky'));
		if ($typVyuky != 2)
			array_push ($q, ' AND [typVyuky] = %i ', $typVyuky);
		array_push ($q, ' AND [obor] = %i ', intval($this->queryParam('obor')));
	}
}
