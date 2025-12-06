<?php

namespace integrations\eds;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableServices
 */
class TableSets extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.eds.sets', 'integrations_eds_sets', 'Sady externích dat', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewSets
 */
class ViewSets extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t2'] = [];

		$edsTypeCfg = $this->app()->cfgItem('integrations.eds.types.'.$item['edsType'], NULL);
		if ($edsTypeCfg)
		{
			$listItem ['t2'][] = ['text' => $edsTypeCfg['fn'], 'class' => 'label label-default'];
		}

		if (isset($item['dateUpdated']) && $item['dateUpdated'] !== NULL)
			$listItem ['t2'][] = ['text' => Utils::datef($item['dateUpdated'], '%k, %T'), 'icon' => 'system/actionDownload', 'class' => 'label label-default'];

		if (isset($item['dateNextUpdate']) && $item['dateNextUpdate'] !== NULL)
			$listItem ['t2'][] = ['text' => Utils::datef($item['dateNextUpdate'], '%k, %T'), 'icon' => 'user/hourglass', 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, 'SELECT [sets].*, [data].dateUpdated AS dateUpdated, [data].dateNextUpdate AS dateNextUpdate');
		array_push ($q, ' FROM [integrations_eds_sets] AS [sets]');
		array_push ($q, ' LEFT JOIN [integrations_eds_data] AS [data] ON [sets].ndx = [data].[edsSet]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormSet
 */
class FormSet extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', TRUE);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Služba', 'icon' => 'tables/integrations.eds.sets'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('edsType');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('apiUrl');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSet
 */
class ViewDetailSet extends TableViewDetail
{
	public function createDetailContent ()
	{
		$data = $this->table->app()->db()->query('SELECT * FROM [integrations_eds_data] WHERE [edsSet] = %i', $this->item['ndx'])->fetch();
		if (!$data)
			return;
		$this->addContent(['type' => 'text', 'subtype' => 'code', 'text' => $data['data']]);
	}
}
