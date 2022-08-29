<?php

namespace e10pro\meters\libs;
use \Shipard\Utils\Utils;


/**
 * class MetersValues
 */
class MetersValues extends \Shipard\Base\Utility
{
  var $queryParams = [];

  var $data = [];

  public function setQueryParam($paramId, $paramValue)
  {
    $this->queryParams[$paramId] = $paramValue;
  }

  protected function loadMeters()
  {
    $q = [];
		array_push($q, 'SELECT [meters].*,');
		array_push($q, ' [parents].[fullName] AS [parentFullName]');
		array_push($q, ' FROM [e10pro_meters_meters] AS [meters]');
		array_push($q, ' LEFT JOIN [e10pro_meters_meters] AS [parents] ON [meters].[parentMeter] = [parents].[ndx]');
		array_push($q, ' WHERE 1');
    array_push($q, ' AND [meters].[workOrder] = %i', $this->queryParams['workOrder']);
    array_push($q, ' ORDER BY [meters].meterKind, [meters].ndx');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $m = $r->toArray();//['ndx' => $r['ndx'], 'title' => $r['fullName'], 'meterKind' => $r['meterKind']];
      $this->data['meters'][$r['ndx']] = $m;
    }
  }

  protected function loadMetersValues()
  {
    $units = $this->app()->cfgItem ('e10.witems.units');

    $ct = [];
    foreach ($this->data['meters'] as $meterNdx => $meter)
    {
      $mv = [
        'pastDate' => NULL, 'pastValue' => 0.0,
        'currentDate' => NULL, 'currentValue' => 0.0,
        'totalValue' => 0.0
      ];

      // -- last past value
      $q = [];
      array_push($q, 'SELECT vals.*');
      array_push($q, ' FROM [e10pro_meters_values] AS [vals]');
      array_push($q, ' WHERE [meter] = %i', $meterNdx);
      array_push($q, ' AND [datetime] > %d', $this->queryParams['dateBegin']);
      array_push($q, ' ORDER BY [datetime] ASC');
      array_push($q, ' LIMIT 1');
      $last = $this->db()->query($q)->fetch();
      if ($last)
      {
        $mv['pastDate'] = $last['datetime']->format('Y-m-d');
        $mv['pastValue'] = $last['value'];
      }

      // -- current
      $q = [];
      array_push($q, 'SELECT vals.*');
      array_push($q, ' FROM [e10pro_meters_values] AS [vals]');
      array_push($q, ' WHERE [meter] = %i', $meterNdx);
      array_push($q, ' AND [datetime] > %d', $this->queryParams['dateEnd']);
      array_push($q, ' ORDER BY [datetime] DESC');
      array_push($q, ' LIMIT 1');
      $current = $this->db()->query($q)->fetch();
      if ($current)
      {
        $mv['currentDate'] = $current['datetime']->format('Y-m-d');
        $mv['currentValue'] = $current['value'];
      }

      $mv['totalValue'] = round($mv['currentValue'] - $mv['pastValue'], 3);

      $this->data['allMetersValues'][$meterNdx] = $mv;

      $cr = [
        'title' => $meter['fullName'],
        'beginValue' => ['text' => Utils::nf($mv['pastValue'], 3), 'prefix' => Utils::datef($mv['pastDate']), '%d'],
        'beginDate' => $mv['pastDate'],

        'endValue' => ['text' => Utils::nf($mv['currentValue'], 3), 'prefix' => Utils::datef($mv['currentDate']), '%d'],
        'endDate' => $mv['currentDate'],

        'totalValue' => Utils::nf($mv['totalValue'], 3),

        'unit' => $units[$meter['unit']]['shortcut'],
      ];

      $ct[] = $cr;
    }

    $contentTitle = ['text' => 'Odečty měřičů', 'class' => 'h3'];

    $ch = ['title' => 'Měřič', 'beginValue' => ' Poč. stav', 'endValue' => ' Kon. stav', 'totalValue' => ' Odebrané množství', 'unit' => 'Jed.'];
    $mvContent = ['type' => 'table', 'table' => $ct, 'header' => $ch, 'title' => $contentTitle, 'params' => ['precision' => 3]];
    $this->data['contents'][] = $mvContent;
  }

  protected function makeKindsValues()
  {
    foreach ($this->data['meters'] as $meterNdx => $meter)
    {
      $meterKind = $meter['meterKind'];

      if (!isset($this->data['kindMetersValues'][$meterKind]))
      {
        $this->data['kindMetersValues'][$meterKind] = ['totalValue' => 0.0];
      }

      $this->data['kindMetersValues'][$meterKind]['totalValue'] += $this->data['allMetersValues'][$meterNdx]['totalValue'];
    }
  }

  public function load()
  {
    $this->data = [];
    $this->data['meters'] = [];
    $this->data['allMetersValues'] = [];
    $this->data['kindMetersValues'] = [];
    $this->data['contents'] = [];

    $this->loadMeters();
    $this->loadMetersValues();
    $this->makeKindsValues();
  }
}

