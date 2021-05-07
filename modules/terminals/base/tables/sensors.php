<?php

namespace terminals\base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableSensors
 * @package terminals\base
 */
class TableSensors extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('terminals.base.sensors', 'terminals_base_sensors', 'Senzory');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function saveConfig ()
	{
		/*		$cashBoxes = array ();
				$rows = $this->app()->db->query ('SELECT * from [e10doc_base_cashboxes] WHERE [docState] != 9800 ORDER BY [order], [id]');

				foreach ($rows as $r)
					$cashBoxes [$r['ndx']] = [
							'ndx' => $r['ndx'], 'id' => $r['id'], 'curr' => $r['currency'],
							'debsAccountId' => isset ($r['debsAccountId']) ? $r['debsAccountId'] : '',
							'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
							'warehouseCashreg' => $r ['warehouseCashreg'], 'warehousePurchase' => $r ['warehousePurchase'],
							'efd' => $r['exclFromDashboard']
					];

				// save to file
				$cfg ['e10doc']['cashBoxes'] = $cashBoxes;
				file_put_contents(__APP_DIR__ . '/config/_e10doc.cashBoxes.json', utils::json_lint (json_encode ($cfg)));
		*/
	}
}


/**
 * Class ViewSensors
 * @package terminals\base
 */
class ViewSensors extends TableView
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
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [terminals_base_sensors]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [name] LIKE %s', '%'.$fts.'%',
					' OR [id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormSensor
 * @package terminals\base
 */
class FormSensor extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
		$this->addColumnInput ('name');
		$this->addColumnInput ('localServer');
		$this->addColumnInput ('sensorClass');
		$this->addColumnInput ('icon');
		$this->addColumnInput ('manual');
		$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}

