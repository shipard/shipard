<?php

namespace mac\sw;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TablePublishers
 * @package mac\sw
 */
class TablePublishers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.sw.publishers', 'mac_sw_publishers', 'Vydavatelé', 1356);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['suid']) && $recData['suid'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['suid'] = utils::createRecId($recData, '!05Z');
		}
	}
}


/**
 * Class ViewPublishers
 * @package mac\sw
 */
class ViewPublishers extends TableView
{
	var $swClass;
	var $osFamily;
	var $osEdition;
	var $lifeCycle;

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['suid'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [publishers].*';
		array_push ($q, ' FROM [mac_sw_publishers] AS [publishers]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [publishers].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [publishers].[suid] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'publishers.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormPublisher
 * @package mac\sw
 */
class FormPublisher extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Názvy', 'icon' => 'formNames'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addListViewer ('names', 'default');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailPublisher
 * @package mac\sw
 */
class ViewDetailPublisher extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class ViewDetailPublisherAnnotations
 * @package mac\sw
 */
class ViewDetailPublisherAnnotations extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10pro.kb.annots', 'default',
			['docTableNdx' => $this->table->ndx, 'docRecNdx' => $this->item['ndx']]);
	}
}
