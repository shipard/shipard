<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableValuesKinds
 * @package mac\iot
 */
class TableValuesKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.valuesKinds', 'mac_iot_valuesKinds', 'Druhy hodnot');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		$valueType = $this->app()->cfgItem ('mac.iot.values.types.'.$recData['valueType'], NULL);

		if ($valueType)
			return $valueType['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [mac_iot_valuesKinds] WHERE [docState] != 9800 ORDER BY [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'], 'id' => $r ['id'],
				'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'icon' => $r['icon'],
				'valueType' => $r['valueType'],
				'topicPattern' => $r['topicPattern'],
			];

			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg ['mac']['iot']['values']['kinds'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_mac.iot.values.kinds.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewValuesKinds
 * @package mac\iot
 */
class ViewValuesKinds extends TableView
{
	var $valuesTypes;

	public function init ()
	{
		parent::init();
		$this->valuesTypes = $this->app->cfgItem('mac.iot.values.types');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t2'] = [];

		if (isset($this->valuesTypes[$item['valueType']]))
			$listItem ['t2'][] = ['text' => $this->valuesTypes[$item['valueType']]['shortName'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT valuesKinds.*';
		array_push ($q, ' FROM [mac_iot_valuesKinds] AS valuesKinds');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' valuesKinds.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR valuesKinds.[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'valuesKinds.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormValueKind
 * @package mac\iot
 */
class FormValueKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('valueType');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('topicType');
					if ($this->recData['topicType'] == 0)
						$this->addColumnInput ('topicPattern');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailValueKind
 * @package mac\iot
 */
class ViewDetailValueKind extends TableViewDetail
{
}

