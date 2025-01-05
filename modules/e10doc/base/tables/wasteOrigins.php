<?php

namespace e10doc\base;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Json;
use \e10\base\libs\UtilsBase;


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
		$list [0] = ['ndx' => 0, 'fn' => '', 'dn' => '', 'tfr' => '', 'useForCompanies' => 1, 'useForCitizens' => 1];

		$rows = $this->app()->db->query ('SELECT * FROM [e10doc_base_wasteOrigins] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'],
				'fn' => $r ['fullName'],
				'sn' => $r ['shortName'],
				'tfr' => $r ['textForReport'],
				'useForCompanies' => intval($r ['useForCompanies']),
				'useForCitizens' => intval($r ['useForCitizens']),
			];

			$this->saveConfigList ($item, 'personsGroups', 'e10.persons.groups', 'e10-wasteOrigins-pg', $r ['ndx']);

			$list [$r['ndx']] = $item;
		}

		// save to file
		$cfg ['e10doc']['base']['wasteOrigins'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.base.wasteOrigins.json', Json::lint ($cfg));
	}

	function saveConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
	{
		$list = [];

		$rows = $this->app()->db->query (
			'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', $listId, ' AND dstTableId = %s', $dstTableId,
			' AND doclinks.srcRecId = %i', $activityTypeNdx
		);
		foreach ($rows as $r)
		{
			$list[] = $r['dstRecId'];
		}

		if (count($list))
		{
			$item[$key] = $list;
			return count($list);
		}

		return 0;
	}
}

/**
 * class ViewWasteOrigins
 */
class ViewWasteOrigins extends TableView
{
	var $linkedPersons = NULL;

	public function init ()
	{
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => $item['shortName'], 'class' => 'e10-small'];

		$listItem['t2'] = [];

		if ($item['order'])
			$listItem['i2'] = ['text' => $item['order'], 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if ($item['useForCompanies'])
			$listItem['t2'][] = ['text' => 'Firmy', 'icon' => 'system/personCompany', 'class' => 'label label-default'];
		if ($item['useForCitizens'])
			$listItem['t2'][] = ['text' => 'Občané', 'icon' => 'system/personHuman', 'class' => 'label label-default'];

		if ($item['textForReport'] !== '')
			$listItem['t3'][] = ['text' => $item['textForReport'], 'icon' => 'system/actionPrint', 'class' => ''];


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

		// -- fulltext
		if ($fts != '')
    {
      array_push ($q, ' AND (');
        array_push ($q, 'origins.[fullName] LIKE %s', '%'.$fts.'%');
			  array_push ($q, ' OR origins.[fullName] LIKE %s', '%'.$fts.'%');
      array_push ($q, ')');
    }
		$this->queryMain ($q, 'origins.', ['origins.[order]', 'origins.[fullName]', 'origins.[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks, 'label label-info');
	}

	function decorateRow (&$item)
	{
		if (isset ($this->linkedPersons [$item ['pk']]))
		{
			$item ['t2'] = array_merge($item ['t2'], $this->linkedPersons [$item ['pk']]);
		}
	}
}


/**
 * Class ViewDetailWasteOrigin
 */
class ViewDetailWasteOrigin extends TableViewDetail
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
					$this->addColumnInput ('textForReport');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('useForCompanies');
					$this->addColumnInput ('useForCitizens');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('order');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

