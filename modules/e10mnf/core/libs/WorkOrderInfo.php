<?php

namespace e10mnf\core\libs;

use \Shipard\Base\Utility;
use Shipard\Utils\Utils;
use \Shipard\UI\Core\UIUtils;


class WorkOrderInfo extends Utility
{
  var $recData = NULL;
	var $forPrint = 0;

  var $vdsData;
  var $scDef;
  var $data = [];

  /** @var \e10mnf\core\TableWorkOrders $tableWorkOrders */
  var $tableWorkOrders;

  public function setWorkOrder($workOrderNdx)
  {
    $this->tableWorkOrders = $this->app()->table('e10mnf.core.workOrders');
    $this->recData = $this->tableWorkOrders->loadItem($workOrderNdx);
  }

  public function loadInfo()
  {
    $this->vdsData = json_decode($this->recData['vdsData'], TRUE);
    $this->scDef = $this->tableWorkOrders->subColumnsInfo($this->recData, 'vdsData');

    $scContent = UIUtils::renderSubColumns ($this->app(), $this->vdsData, $this->scDef, TRUE);
    $this->data['vdsContent'] = $scContent;


    $this->loadRows();
		$this->loadPersonsList ();
  }

	public function loadRows ()
	{
		$q[] = 'SELECT [rows].[text], [rows].quantity, [rows].unit, ';
		array_push($q, ' [rows].priceItem, [rows].priceAll');
		array_push($q, ' FROM [e10mnf_core_workOrdersRows] AS [rows]');
		array_push($q, ' WHERE [rows].workOrder = %i', $this->recData ['ndx']);
		array_push($q, ' ORDER BY rowOrder, ndx');

		$cfgUnits = $this->app->cfgItem ('e10.witems.units');
		$rows = $this->db()->query($q);
		$list = [];
		$totalPriceAll = 0.0;
		forEach ($rows as $r)
		{
			$unit = (isset($cfgUnits[$r['unit']])) ? $cfgUnits[$r['unit']]['shortcut'] : '';
			$list[] = ['text' => $r['text'], 'quantity' => $r['quantity'], 'unit' => $unit, 'priceItem' => $r['priceItem'], 'priceAll' => $r['priceAll']];
			$totalPriceAll += $r['priceAll'];
		}

		if (count ($list))
		{
			$h = ['#' => '#', 'text' => 'Text řádku', 'quantity' => ' Množství', 'unit' => 'Jedn.', 'priceItem' => ' Cena/Jedn.', 'priceAll' => ' Cena celkem'];
			if (count ($list) > 1)
			{
				$list[] = ['text' => 'Celkem', 'priceAll' => $totalPriceAll, '_options' => ['class' => 'sum']];
			}

			$this->data['rowsContent'] = [
        'pane' => 'e10-pane e10-pane-table',
        'type' => 'table',
        'title' => ['icon' => 'x-properties', 'text' => 'Řádky dokladu'],
        'header' => $h, 'table' => $list
      ];
		}
	}

	public function loadPersonsList ()
	{
		$h = ['#' => '#', 'personName' => 'Jméno'];

		$q[] = 'SELECT [rowsPersons].*,';
		array_push($q, ' [persons].fullName AS personFullName');
		array_push($q, ' FROM [e10mnf_core_workOrdersPersons] AS [rowsPersons]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [rowsPersons].[person] = [persons].[ndx]');
		array_push($q, ' WHERE [rowsPersons].workOrder = %i', $this->recData ['ndx']);
		array_push($q, ' ORDER BY rowOrder, rowsPersons.ndx');

		$rows = $this->db()->query($q);
		$list = [];
		forEach ($rows as $r)
		{
			$item = [];
			if ($this->forPrint)
				$item['personName'] = $r['personFullName'];
			else
				$item['personName'] = ['text' => $r['personFullName'], 'docAction' => 'edit', 'pk' => $r['person'], 'table' => 'e10.persons.persons'];

			$this->loadProperties ('e10.persons.persons', $r['person'], $item, $h);
			$list[] = $item;
		}

		if (count ($list))
		{
			$this->data['personsList'] = [
        'pane' => 'e10-pane e10-pane-table',
        'type' => 'table',
        'title' => ['icon' => 'system/iconUser', 'text' => 'Osoby'],
        'header' => $h, 'table' => $list
      ];
		}
	}

	protected function loadProperties ($tableId, $recNdx, &$dstRow, &$tableHeader)
	{
		$props = \e10\base\getPropertiesTable ($this->app, $tableId, $recNdx);

		foreach ($props as $groupId => $properties)
		{
			foreach ($properties as $propertyId => $propertyValues)
			{
				$values = [];
				foreach ($propertyValues as $pv)
				{
					$values[] = $pv['value'];
					if (!isset($tableHeader[$propertyId]))
						$tableHeader[$propertyId] = $pv['name'];
				}

				$dstRow[$propertyId] = implode(', ', $values);
			}
		}
	}
}
