<?php

namespace E10Pro\Zus;

//require_once __DIR__ . '/../../base/base.php';


use \E10\Application;
use \E10\TableView;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;


/**
 * Tabulka Programy akcí
 *
 */

class TableProgramAkci extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.programakci", "e10pro_zus_programakci", "Programy akcí");
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		$q = "SELECT * FROM [e10pro_zus_programakci] WHERE [akce] = %i ORDER BY [poradi] DESC LIMIT 0, 1";
		$row = $this->db()->query($q, $recData ['akce'])->fetch ();
		if ($row)
			$recData ['poradi'] = $row ['poradi'] + 1;
		else
			$recData ['poradi'] = 1;
	}
}


/**
 * Editační formulář Řádku akce
 *
 */

class FormRadekAkce extends TableForm
{
	public function renderForm ()
	{
		$this->openForm ();
			$this->layoutOpen (TableForm::ltHorizontal);
				$this->addColumnInput ("co");
				$this->addColumnInput ("kdo");
			$this->layoutClose ();
				$this->addColumnInput ("poznamka");
			$this->layoutOpen (TableForm::ltHorizontal);
				$this->addColumnInput ("poradi");
			$this->layoutClose ();
		$this->closeForm ();
	}

	public function createHeaderCode ()
	{
		$item = $this->recData;
		$akce = $this->table->loadItem ($item ['akce'], 'e10pro_zus_akce');
		$info = '';
		return $this->defaultHedearCode ("x-microphone", $akce ['nazev'], $info);
	}

} // class FormRadekAkce


/**
 * Widget s programem akce
 *
 */

class WidgetProgramAkce extends \E10\TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->addAddParam ('akce', $this->queryParam ('akce'));
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem = $item;
		$listItem ['pk'] = $item ['ndx'];
		return $listItem;
	}

  public function rowHtmlContent ($listItem)
	{
		$class = '';
		if ($listItem ['smazano'])
			$class = ' deleted';
		
		$c = '';
		$c .= "<div class='e10-tvw-item$class' data-pk='{$listItem['ndx']}'>";
			$c .= "<div class='e10-tvw-item-head'>";
				$c .= "<span style='display: inline-block; white-space: pre; vertical-align: top;'><b>{$this->lineRowNumber}.</b>&nbsp;</span>";
			$c .= '</div>';
			$c .= "<div style='clear: both;'><span style='width: 47%; display: inline-block; vertical-align: top;border-right: 1px solid #CCC; padding: .5ex; margin: 1ex;'>" . nl2br (\E10\es ($listItem ['co'])) . '</span>';
			$c .= "<span style='width: 47%; display: inline-block; vertical-align: top; padding: .5ex; margin: 1ex;'>" . nl2br (\E10\es ($listItem ['kdo'])) . '</span></div>';

			if ($listItem ['poznamka'] != '')
				$c .= "<div style='clear: both; border-top: 1px solid #CCC; padding: .5ex; margin: 1ex;'>" . nl2br (\E10\es ($listItem ['poznamka'])) . '</div>';
		$c .= '</div>';
		return $c;
	}

	public function selectRows ()
	{
		$q = "SELECT * FROM [e10pro_zus_programakci] WHERE [akce] = %i AND [smazano] = 0 ORDER BY [poradi], [ndx]".$this->sqlLimit();
		$this->runQuery ($q, $this->queryParam ('akce'));
	}
} // class WidgetProgramAkce
