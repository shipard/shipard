<?php

namespace e10pro\condo\libs;
use Shipard\Utils\Utils;


/**
 * class FlatInfo
 */
class FlatInfo extends \e10mnf\core\libs\WorkOrderInfo
{
  public function loadInfo()
  {
    parent::loadInfo();
    $this->loadMetersReadings();
  }

	public function loadRows ()
	{
    parent::loadRows();

    if (isset($this->data['rowsContent']))
    {
      $this->data['rowsContent']['title']['text'] = 'Předpis měsíčních plateb';
      $this->data['rowsContent']['title']['icon'] = 'user/moneyBill';
      $this->data['rowsContent']['header'] = ['#' => '#', 'text' => 'Účet platby', 'priceAll' => ' Částka'];
    }
	}

  public function loadMetersReadings ()
	{
    $q = [];
    array_push($q, 'SELECT vals.*,');
    array_push($q, ' [meters].[fullName] AS meterFullName, [meters].[shortName] AS meterShortName, [meters].[unit] AS meterUnit, [meters].[id] AS meterId');
    array_push($q, ' FROM [e10pro_meters_values] AS [vals]');
    array_push($q, ' LEFT JOIN [e10pro_meters_meters] AS [meters] ON [vals].[meter] = [meters].[ndx]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [meters].[workOrder] = %i', $this->recData['ndx']);
    array_push($q, ' ORDER BY [vals].[datetime] DESC, [meters].[id], [vals].[ndx]');

    $units = $this->app->cfgItem ('e10.witems.units');
    $t = [];
    $h = ['date' => 'Datum'];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $rowId = $r['datetime']->format('Y-m-d');
      $meterId = 'M'.$r['meter'];

      if (!isset($h[$meterId]))
      {
        $h[$meterId] = ' '.$r['meterShortName'];
        $h[$meterId.'U'] = 'jed.';
      }

      if (!isset($t[$rowId]))
      {
        $t[$rowId]['date'] = Utils::datef($r['datetime']);
      }
      $t[$rowId][$meterId] = $r['value'];
      $t[$rowId][$meterId.'U'] = $units[$r['meterUnit']]['shortcut'];
    }

		if (count ($t))
		{
      $addBtn = [
        'type' => 'action', 'action' => 'addwizard', 'text' => 'Nový odečet', 'icon' => 'system/actionAdd',
        'data-table' => 'e10.persons.persons', 'data-class' => 'e10pro.meters.libs.AddMetersValuesWorkOrder',
        'data-addparams' => 'workOrder='.$this->recData['ndx'],
        'actionClass' => 'btn-xs', 'class' => 'pull-right',
      ];

      $title = [['icon' => 'tables/e10pro.meters.values', 'text' => 'Poslední odečty', 'class' => 'h3']];
      $title[] = $addBtn;

      $this->data['rowsMetersReadings'] = [
        'pane' => 'e10-pane e10-pane-table',
        'type' => 'table',
        'title' => $title,
        'header' => $h, 'table' => $t
      ];
		}
  }
}
