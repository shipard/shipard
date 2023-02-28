<?php


namespace e10\ui;
use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Application\DataModel;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Utils;


/**
 * class TableUIs
 */
class TableUIs extends DbTable
{
  const uitTemplate = 9;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.ui.uis', 'e10_ui_uis', 'Uživatelská rozhraní');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);


		$hdr ['info'][] = ['class' => 'title', 'value' => $recData['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$uis = [];
		$rows = $this->app()->db->query ('SELECT * FROM [e10_ui_uis] WHERE docState != 9800 ORDER BY [urlId]');

		foreach ($rows as $r)
		{
      $uiItem = [
				'ndx' => $r['ndx'],
        'uiType' => $r ['uiType'],
				'fn' => $r ['fullName'],
			];

      $uis [$r['urlId']] = $uiItem;
		}

		// -- save to file
		$cfg ['e10']['ui']['uis'] = $uis;
		file_put_contents(__APP_DIR__ . '/config/_e10.ui.uis.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewUIs
 */
class ViewUIs extends TableView
{
	var $toReports;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = ['text' => $item['urlId'], 'class' => 'label label-default'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10_ui_uis]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%', ' OR [urlId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormUI
 */
class FormUI extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

      $tabs ['tabs'][] = ['text' => 'Šablona', 'icon' => 'formText'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
          $this->addColumnInput ('fullName');
          $this->addColumnInput ('uiType');
          $this->addColumnInput ('urlId');
					$this->addColumnInput ('order');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
          $this->addInputMemo ('template', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}
