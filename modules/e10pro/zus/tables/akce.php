<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';


use \E10\Application;
use \E10\TableView;
use \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;
use \E10\FormReport;


/**
 * Tabulka Akce
 *
 */

class TableAkce extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.akce", "e10pro_zus_akce", "Akce", 1214);
	}
}


/**
 * Základní pohled na Akce
 *
 */

class ViewAkce extends TableView
{
	public function init ()
	{
		if (isset ($this->defaultType))
			$this->addAddParam ('type', $this->defaultType);

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'archive', 'title' => 'Archív');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		parent::init();
	} // init

	public function selectRows ()
	{
		$dotaz = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10pro_zus_akce] WHERE 1';

		// -- fulltext
		if ($dotaz != '')
			array_push ($q, " AND [nazev] LIKE %s", '%'.$dotaz.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// -- archive
		if ($mainQuery == 'archive')
			array_push ($q, " AND [docStateMain] = 5");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [datum]' . $this->sqlLimit ());
		elseif ($mainQuery == 'archive')
			array_push ($q, ' ORDER BY [datum] DESC' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY [docStateMain], [datum]' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = "tables/e10pro.zus.akce";
		$listItem ['t1'] = \E10\es ($item['nazev']);
		$listItem ['t2'] = \E10\es ($item ['misto']);
		$listItem ['i1'] = \E10\df ($item ['datum'], '%D');
		$listItem ['i2'] = \E10\es ($item ['cas']);

		return $listItem;
	}
} // class ViewAkce


/**
 * Základní detail Vysvědčení
 *
 */

class ViewDetailAkce extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10pro.zus.programakci', 'e10pro.zus.WidgetProgramAkce', ['akce' => $this->item ['ndx']]);
	}

	public function createHeaderCode ()
	{
		$item = $this->item;
		$info = \E10\df ($item ['datum'], '%D') . ', ' . \E10\es ($item ['cas']) . '<br/>' . \E10\es ($item ['misto']);
		return $this->defaultHedearCode ("e10pro-zus-akce", \E10\es ($item ['nazev']), $info);
	}
}


/*
 * FormAkce
 *
 */

class FormAkce extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('maximize', 1);
		$this->openForm ();

		$this->layoutOpen (TableForm::ltHorizontal);
			$this->layoutOpen (TableForm::ltForm);
				$this->addColumnInput ("nazev");
				$this->addColumnInput ("misto");
			$this->layoutClose ();
			$this->layoutOpen (TableForm::ltForm);
				$this->addColumnInput ("datum");
				$this->addColumnInput ("cas");
			$this->layoutClose ();
		$this->layoutClose ();

		$tabs ['tabs'][] = array ('text' => 'Program', 'icon' => 'system/formHeader');
		$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');
		$this->openTabs ($tabs);
			$this->openTab (TableForm::ltNone);
				$this->addViewerWidget ('e10pro.zus.programakci', 'e10pro.zus.WidgetProgramAkce', array ('akce' => $this->recData ['ndx']));
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer ();
			$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}
/*
	public function formReport ()
	{
		return new VysvedceniReport ($this->table, $this->recData);
	}
*/
	public function createHeaderCode ()
	{
		$item = $this->recData;
		$info = '';
		return $this->defaultHedearCode ("e10pro-zus-akce", $item ['nazev'], $info);
	}
} // class FormAkce


