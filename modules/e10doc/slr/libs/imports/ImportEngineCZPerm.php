<?php
namespace e10doc\slr\libs\imports;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Json;


/**
 * class ImportEngineCZPerm
 */
class ImportEngineCZPerm extends \e10doc\slr\libs\ImportEngine
{
  protected function doAllAttachments()
  {
    $attachments = UtilsBase::loadAttachments ($this->app, [$this->importNdx], 'e10doc.slr.imports');
		if (!count($attachments))
			return;

		foreach ($attachments[$this->importNdx]['files'] as $a)
		{
			$srcFullFileName = __APP_DIR__.'/att/'. $a['path'].$a['filename'];

      if ($this->app()->debug)
        echo " --> ".$srcFullFileName."\n";

      $this->importFile($srcFullFileName);
    }
  }

  protected function importFile($fileName)
  {
    $dataStr = file_get_contents($fileName);
    if (!$dataStr)
    {

      return;
    }

    $data = Json::decode($dataStr);
    if (!$data || !isset($data['Shipard']))
    {
      return;
    }

    //$this->db()->query('DELETE FROM e10doc_slr_slrItems');
    //$this->db()->query('DELETE FROM e10doc_slr_emps');

    $this->db()->query('DELETE [e10doc_slr_empsRecsRows] FROM [e10doc_slr_empsRecsRows] ',
        ' INNER JOIN e10doc_slr_empsRecs ON e10doc_slr_empsRecs.ndx = e10doc_slr_empsRecsRows.empsRec',
        ' WHERE [e10doc_slr_empsRecs].[import] = %i', $this->importNdx);
    $this->db()->query('DELETE FROM [e10doc_slr_empsRecs] WHERE [import] = %i', $this->importNdx, ' AND [docAcc] = %i', 0);

    foreach ($data['Shipard'] as $oneItem)
    {
      if ($this->app()->debug)
        echo "   -- ".json_encode($oneItem)."\n";

      $empRecData = $this->loadEmp($oneItem['OsCislo']);
      $slrItemRecData = $this->loadSlrItem($oneItem['MzPolozka'], $oneItem['Popis']);

      if (!$empRecData || !$slrItemRecData)
        continue;

      $this->addOneItem($empRecData['ndx'], $slrItemRecData['ndx'], $oneItem);
    }
  }

  protected function addOneItem($empNdx, $slrItemNdx, $item)
  {
    $empRecNdx = 0;
    $empRecRecData = $this->db()->query('SELECT * FROM [e10doc_slr_empsRecs] WHERE',
                                    ' [emp] = %i', $empNdx,
                                    ' AND [import] = %i', $this->importNdx)->fetch();
    if ($empRecRecData)
    {
      $empRecNdx = $empRecRecData['ndx'];
    }
    else
    {
      $newEmpRec = [
         'emp' => $empNdx,
         'import' => $this->importNdx,
         'docState' => 1000, 'docStateMain' => 0,
      ];
      $this->db()->query('INSERT INTO [e10doc_slr_empsRecs]', $newEmpRec);

      $empRecNdx = $this->db()->getInsertId ();
    }

    $newRow = [
      'rowOrder' => 100,
      'empsRec' => $empRecNdx,
      'slrItem' => $slrItemNdx,
      'amount' => $item['Castka'],
    ];

    $q = floatval($item['NatUkaz'] ?? 0);
    if ($q)
    {
      $newRow['quantity'] = $q;
      $newRow['unit'] = 'hr';
    }

    if (isset($item['Stredisko']) && $item['Stredisko'] != '')
    {
      $centreRecData = $this->searchCentre($item['Stredisko']);
      if ($centreRecData)
        $newRow['centre'] = $centreRecData['ndx'];
    }

    $this->db()->query('INSERT INTO [e10doc_slr_empsRecsRows]', $newRow);
  }
}
