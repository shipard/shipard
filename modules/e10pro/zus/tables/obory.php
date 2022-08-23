<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';


use \E10\TableView, \e10\TableForm, \e10\DbTable, e10\utils;


/**
 * Class TableObory
 * @package E10Pro\Zus
 */
class TableObory extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.obory', 'e10pro_zus_obory', 'Obory');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['nazev']];

		return $hdr;
	}

  public function saveConfig ()
	{
		$ca = [];

		$rows = $this->app()->db->query ('SELECT * from [e10pro_zus_obory] WHERE docState != 9800 ORDER BY [pos], [nazev]');
		forEach ($rows as $r)
		{
			$ca [$r['ndx']] = [
					'id' => $r['ndx'], 'nazev' => $r ['nazev'], 'zkratka' => $r ['id'], 'pojmenovani' => $r ['pojmenovani'],
					'svp' => $r ['svp'], 'skolne1p' => $r['skolne1p'], 'typVyuky' => $r['typVyuky']
			];
		}

		// -- save to file
		$cfg ['e10pro']['zus']['obory'] = $ca;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.zus.obory.json', json_encode ($cfg));
	}
}


/**
 * Class ViewObory
 * @package E10Pro\Zus
 */
class ViewObory extends TableView
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
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item ['nazev'];
		$listItem ['i1'] = ['icon' => 'icon-money', 'text' => utils::nf ($item ['skolne1p'], 2)];

		if ($item ['svp'])
			$listItem ['t2'][] = ['text' => $this->app()->cfgItem ("e10pro.zus.svp.{$item ['svp']}.nazev")];

		if ($item['pos'])
			$listItem['i2'] = ['icon' => 'icon-sort', 'text' => strval ($item['pos'])];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT * from [e10pro_zus_obory] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([nazev] LIKE %s OR [id] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[pos]', '[nazev]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormObory
 * @package E10Pro\Zus
 */
class FormObory extends TableForm
{
	public function renderForm ()
	{
    $this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

		$this->layoutOpen (TableForm::ltHorizontal);
			$this->layoutOpen (TableForm::ltForm);
				$this->addColumnInput ('svp');
				$this->addColumnInput ('nazev');
				$this->addColumnInput ('pojmenovani');
				$this->addColumnInput ('id');
				$this->addColumnInput ('pos');
				$this->addColumnInput ('typVyuky');
				$this->addColumnInput ('skolne1p');
			$this->layoutClose ();
		$this->layoutClose ();

		$this->closeForm ();
	}
}
