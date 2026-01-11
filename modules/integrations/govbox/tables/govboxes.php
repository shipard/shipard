<?php

namespace integrations\govbox;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Json;


/**
 * Class TableGovboxes
 */
class TableGovboxes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.govbox.govboxes', 'integrations_govbox_govboxes', 'Datové schránky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['govBoxId'] . ' - ' . $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [integrations_govbox_govboxes] WHERE [docState] != 9800 ORDER BY [order], [ndx]');

		foreach ($rows as $r)
    {
			$list [$r['ndx']] = [
        'ndx' => $r ['ndx'],
        'fn' => $r ['fullName'],
        'govBoxId' => $r ['govBoxId'],
        'login' => $r ['login'],
        'password' => $r ['password'],
        'testMode' => $r ['testMode'],
      ];
    }

		// save to file
		$cfg ['integrations']['govboxes'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_integrations.govboxes.json', Json::lint ($cfg));
	}
}


/**
 * class ViewGovboxes
 */
class ViewGovboxes extends TableView
{
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
		$listItem ['t1'] = $item['govBoxId'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
    $listItem ['t2'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push($q, 'SELECT * FROM [integrations_govbox_govboxes]');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [govBoxId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[govBoxId]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormGovbox
 */
class FormGovbox extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
      $this->addColumnInput ('govBoxId');
      $this->addColumnInput ('fullName');
      $this->addColumnInput ('login');
      $this->addColumnInput ('password');
      $this->addSeparator(self::coH2);
      $this->addColumnInput ('order');
      $this->addColumnInput ('testMode');
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailGovbox
 */
class ViewDetailGovbox extends TableViewDetail
{
}
