<?php

namespace E10Doc\Base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableTransports
 * @package E10Doc\Base
 */
class TableTransports extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.transports', 'e10doc_base_transports', 'Způsoby dopravy');
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
		$transports = [];
		$rows = $this->app()->db->query ('SELECT * from [e10doc_base_transports] WHERE [docState] != 9800 ORDER BY [order], [id]');

		foreach ($rows as $r)
		{
			$transports [$r['ndx']] = [
				'ndx' => $r['ndx'],
				'transportType' => $r['transportType'],
				'id' => $r['id'],
				'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'vehicleLP' => $r['vehicleLP'], 'askVehicleLP' => $r['askVehicleLP'],
				'vehicleDriver' => $r['vehicleDriver'], 'askVehicleDriver' => $r['askVehicleDriver'],
				'askVehicleWeight' => $r['askVehicleWeight'],
				'pb' => $r['personBalance']
			];
		}
		// save to file
		$cfg ['e10doc']['transports'] = $transports;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.transports.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewCashBoxes
 * @package E10Doc\Base
 */
class ViewTransports extends TableView
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
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		if ($item['vehicleLP'] !== '')
			$props[] = ['text' => $item['vehicleLP'], 'class' => 'label label-info'];
		if ($item['driverName'])
			$props[] = ['text' => $item['driverName'], 'class' => 'label label-default', 'icon' => 'system/iconUser'];

		$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, 'SELECT transports.*, [drivers].fullName AS [driverName]');
		array_push ($q, ' FROM [e10doc_base_transports] AS [transports]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [drivers] ON transports.vehicleDriver = [drivers].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortName] LIKE %s', '%'.$fts.'%',
					' OR [id] LIKE %s', '%'.$fts.'%',
					' OR [vehicleLP] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'transports.', ['[order]', '[id]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormTransport
 * @package E10Doc\Base
 */
class FormTransport extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('transportType');

			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('id');
			$this->addColumnInput ('order');

			$this->addSeparator(self::coH4);
			$this->addColumnInput ('vehicleLP');
			$this->addColumnInput ('askVehicleLP');
			$this->addSeparator(self::coH4);
			$this->addColumnInput ('vehicleDriver');
			$this->addColumnInput ('askVehicleDriver');
			$this->addSeparator(self::coH4);
			$this->addColumnInput ('askVehicleWeight');
			$this->addSeparator(self::coH4);

			if ($this->recData['transportType'] == 0)
			{
				$this->addSeparator(self::coH4);
				$this->addColumnInput ('personBalance');
			}
		$this->closeForm ();
	}
}

