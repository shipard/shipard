<?php

namespace services\nomenc\libs;
use \Shipard\Base\Utility;


/**
 * @class NomenclatureExport
 */
class NomenclatureExport extends Utility
{
  var string $nomencId = '';
  var ?array $nomencTypeRecData = NULL;
  var array $exportedData = [];
  var array $exportedDataTree = [];

  function loadNomencType()
  {
    $exist = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', $this->nomencId)->fetch();
    if ($exist)
    {
      $this->nomencTypeRecData = $exist->toArray();
      return TRUE;
    }

    return FALSE;
  }

  public function exportFlat()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [e10_base_nomencItems]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [nomencType] = %i', $this->nomencTypeRecData['ndx']);
    array_push($q, ' ORDER BY [order]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'id' => $r['itemId'],
        'fullName' => $r['fullName'],
        'shortName' => $r['shortName'],
        'order' => $r['order'],
        'level' => $r['level'],
      ];
      
      if ($r['ownerItem'])
      {
        $parent = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [ndx] = %i', $r['ownerItem'])->fetch();
        $item['parentId'] = $parent['itemId'];
      }

      $this->exportedData[$r['itemId']] = $item;
    }
  }

  protected function makeTree()
  {
    foreach($this->exportedData as $itemId => $item)
    {
      if (isset($item['parentId']))
      {
        $this->addToTree($this->exportedDataTree, $item);
      }
      else
      {
        $this->exportedDataTree[$item['id']] = $item;
      }
    }
  }

  protected function addToTree(&$list, $item)
  {
    foreach ($list as $parentItemId => &$parentItem)
    {
      if ($parentItemId === $item['parentId'])
      {
        $parentItem['items'][$item['id']] = $item;
        unset ($parentItem['items'][$item['id']]['level']);
        unset ($parentItem['items'][$item['id']]['parentId']);
        return TRUE;
      }
      if (isset($parentItem['items']))
      {
        $added = $this->addToTree($parentItem['items'], $item);
        if ($added)
          return TRUE;
      }
    }

    return FALSE;
  }

  public function run()
  {
    $this->loadNomencType();
    if (!$this->nomencTypeRecData)
    {

      return;
    }
    $this->exportFlat();
    $this->makeTree();
  }
}
