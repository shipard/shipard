<?php

namespace e10doc\base;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Json;


/**
 * Class TableWasteOrigins
 */
class TableWasteOrigins extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.wasteOrigins', 'e10doc_base_wasteOrigins', 'Původy odpadů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];
		$list [0] = ['ndx' => 0, 'fn' => '', 'dn' => ''];

		$rows = $this->app()->db->query ('SELECT * FROM [e10doc_base_wasteOrigins] WHERE [docState] != 9800 ORDER BY [fullName]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'],
				'fn' => $r ['fullName'],
				'sn' => $r ['shortName']
			];
			$list [$r['ndx']] = $item;
		}

		// save to file
		$cfg ['e10doc']['base']['wasteOrigins'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.base.wasteOrigins.json', Json::lint ($cfg));
	}
}

/**
 * class ViewWasteOrigins
 */
class ViewWasteOrigins extends TableView
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
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['shortName'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

    $q = [];
    array_push ($q, ' SELECT origins.*');
    array_push ($q, ' FROM e10doc_base_wasteOrigins AS origins');
		array_push ($q, ' WHERE 1');
    array_push ($q, '');

		// -- fulltext
		if ($fts != '')
    {
      array_push ($q, ' AND (');
        array_push ($q, 'origins.[fullName] LIKE %s', '%'.$fts.'%');
			  array_push ($q, ' OR origins.[fullName] LIKE %s', '%'.$fts.'%');
      array_push ($q, ')');
    }
		$this->queryMain ($q, 'origins.', ['origins.[fullName]', 'origins.[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailWateOrigin
 */
class ViewDetailWateOrigin extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * class FormWasteOrigin
 */
class FormWasteOrigin extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

