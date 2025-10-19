<?php

namespace e10\world\libs;
use \Shipard\Base\Utility, \Shipard\Utils\Json;


/**
 * class ImportAdmUnits
 */
class ImportAdmUnits extends Utility
{
  var $url = 'https://data.shipard.app/papi/la-data-sets/adm-units';
  var $country = 60;
  var $data = NULL;

  protected function init()
  {
    $datastr = file_get_contents($this->url);
    $data = json_decode($datastr, TRUE);
    if (!$data || !is_array($data) || !isset($data['object']['data']))
      return;
    $this->data = $data['object']['data'];
  }

  protected function import()
  {
    if (!$this->data)
      return;

    foreach ($this->data as $d)
    {
      $unit = [
        'country' => $this->country,
        'admUnitId' => $d['id'],
        'fullName' => $d['fn'],
        'level' => $d['level'],
      ];

      if (isset($d['owner0']))
      {
        $owner0Ndx = $this->ownerIdNdx($d['owner0'], 0);
        if ($owner0Ndx)
          $unit['admUnitOwner0'] = $owner0Ndx;
      }
      if (isset($d['owner1']))
      {
        $owner1Ndx = $this->ownerIdNdx($d['owner1'], 1);
        if ($owner1Ndx)
          $unit['admUnitOwner1'] = $owner1Ndx;
      }
      if (isset($d['owner2']))
      {
        $owner2Ndx = $this->ownerIdNdx($d['owner2'], 2);
        if ($owner2Ndx)
          $unit['admUnitOwner2'] = $owner2Ndx;
      }

      $exist = $this->app()->db()->query('SELECT ndx FROM [e10_world_admUnits] WHERE country = %i', $this->country,
                                        ' AND admUnitId = %s ', $unit['admUnitId'],
                                        ' AND level = %i', $unit['level'])->fetch();

      if (!$exist)
        $this->app()->db()->query('INSERT INTO [e10_world_admUnits]', $unit);
      else
        $this->app()->db()->query('UPDATE [e10_world_admUnits] SET ', $unit, ' WHERE ndx = %i', $exist['ndx']);
    }
  }

  protected function ownerIdNdx($ownerId, $level)
  {
    $owner = $this->app()->db()->query('SELECT ndx FROM [e10_world_admUnits] WHERE country = %i', $this->country,
                                        ' AND admUnitId = %s ', $ownerId,
                                        ' AND level = %i', $level)->fetch();
    if ($owner)
      return $owner['ndx'];
    return 0;
  }

  public function run()
  {
    $this->init();
    if (!$this->data)
      return;
    $this->import();
  }
}
