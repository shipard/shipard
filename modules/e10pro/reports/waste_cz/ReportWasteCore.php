<?php

namespace e10pro\reports\waste_cz;

use \E10\utils;


/**
 * Class ReportWasteCore
 * @package e10pro\reports\waste_cz
 */
class ReportWasteCore extends \E10Doc\Core\GlobalReport
{
	var $wasteCodes;
	var $itemWasteCodes = [];
	var $personCids = [];
	public $year = 0;
	protected $inventory;

	public function init ()
	{
		//$this->inventory = ($this->app->model()->table ('e10doc.inventory.journal') !== FALSE);
		$this->inventory = FALSE;

		$fn = __APP_DIR__ . '/e10-modules/e10pro/reports/waste_cz/config/wastecodes.json';
		$this->wasteCodes = utils::loadCfgFile($fn);

		$this->setParams ('fiscalYear');

		parent::init();

		$fyNdx = $this->reportParams ['fiscalYear']['value'];
		if ($this->year === 0)
			$this->year = $this->reportParams ['fiscalYear']['values'][$fyNdx]['calendarYear'];

		$this->setInfo('icontxt', '♻');
		$this->setInfo('param', 'Rok', $this->reportParams ['fiscalYear']['activeTitle']);

	}

	public function itemWasteCode ($itemNdx)
	{
		if (isset($this->itemWasteCodes[$itemNdx]))
			return $this->itemWasteCodes[$itemNdx];

		$this->itemWasteCodes[$itemNdx] = NULL;

		$q = 'SELECT * FROM [e10_base_properties] where [tableid] = %s AND [recid] = %i AND [group] = %s AND [property] = %s';
		$rowCode = $this->app->db()->query ($q, 'e10.witems.items', $itemNdx, 'odpad', 'kododp')->fetch();

		if ($rowCode && isset($rowCode['valueString']) && $rowCode['valueString'] !== '')
		{
			if (isset ($this->wasteCodes[$rowCode['valueString']]))
			{
				$this->itemWasteCodes[$itemNdx] = $this->wasteCodes[$rowCode['valueString']];
				$this->itemWasteCodes[$itemNdx]['code'] = $rowCode['valueString'];
			}
			else
			{
				$this->itemWasteCodes[$itemNdx] = array ('code' => $rowCode['valueString'], 'name' => '---', 'group' => 'other');
				//error_log ("WASTE CODE {$rowCode['valueString']} NOT FOUND!!!");
			}
		}

		return $this->itemWasteCodes[$itemNdx];
	}

	public function personCid ($personNdx)
	{
		if (isset($this->personCids[$personNdx]))
			return $this->personCids[$personNdx];

		$this->personCids[$personNdx] = '';

		$q = 'SELECT * FROM [e10_base_properties] where [tableid] = %s AND [recid] = %i AND [group] = %s AND [property] = %s';
		$rowCode = $this->app->db()->query ($q, 'e10.persons.persons', $personNdx, 'ids', 'oid')->fetch();

		if ($rowCode['valueString'])
			$this->personCids[$personNdx] = $rowCode['valueString'];

		return $this->personCids[$personNdx];
	}

	public function personAddress ($personNdx)
	{
		$addr = $this->db()->query ('SELECT * FROM [e10_persons_address] WHERE tableid = %s', 'e10.persons.persons', ' AND recid = %i', $personNdx)->fetch();
		if ($addr)
			return $addr->toArray();


		return FALSE;
	}

	protected function quantity ($quantity, $unit)
	{
		switch ($unit)
		{
			case 'kg': return $quantity;
			case 'g': return $quantity / 1000;
		}
		return 0;
	}
}

