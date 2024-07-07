<?php
namespace e10doc\slr\libs\imports;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Json;
use \Shipard\Utils\Str;


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

      $this->addOneItem($empRecData, $slrItemRecData, $oneItem);
    }
  }

  protected function addOneItem($empRecData, $slrItemRecData, $item)
  {
    $empRecNdx = 0;
    $empRecRecData = $this->db()->query('SELECT * FROM [e10doc_slr_empsRecs] WHERE',
                                    ' [emp] = %i', $empRecData['ndx'],
                                    ' AND [import] = %i', $this->importNdx)->fetch();
    if ($empRecRecData)
    {
      $empRecNdx = $empRecRecData['ndx'];
    }
    else
    {
      $newEmpRec = [
         'emp' => $empRecData['ndx'],
         'import' => $this->importNdx,
         'docState' => 1000, 'docStateMain' => 0,
      ];
      $this->db()->query('INSERT INTO [e10doc_slr_empsRecs]', $newEmpRec);

      $empRecNdx = $this->db()->getInsertId ();
    }

    $newRow = [
      'rowOrder' => 100,
      'empsRec' => $empRecNdx,
      'slrItem' => $slrItemRecData['ndx'],
      'amount' => $item['Castka'],
      'symbol1' => $item['VS'] ?? '',
      'symbol2' => $item['SS'] ?? '',
      'symbol3' => $item['KS'] ?? '',
    ];

    if (isset($item['CisloUctu']) && $item['CisloUctu'] !== '')
      $newRow['bankAccount'] = $item['CisloUctu'].'/'.$item['SmerKod'];

    if ($slrItemRecData['negativeAmount'] ?? 0)
      $newRow['amount']  = - $newRow['amount'];

    $q = floatval($item['NatUkaz'] ?? 0);
    if ($q)
    {
      $newRow['quantity'] = $q;
      $newRow['unit'] = 'hr';
    }

    $centreNdx = $this->searchCentre($empRecData, $slrItemRecData['ndx']);
    if ($centreNdx)
    {
      $newRow['centre'] = $centreNdx;
    }
    else
    if (isset($item['Stredisko']) && $item['Stredisko'] != '')
    {
      $centreRecData = $this->searchCentreById($item['Stredisko']);
      if ($centreRecData)
        $newRow['centre'] = $centreRecData['ndx'];
    }

    $this->db()->query('INSERT INTO [e10doc_slr_empsRecsRows]', $newRow);

    $sit = $this->app()->cfgItem('e10doc.slr.slrItemTypes.'.$slrItemRecData['itemType']);
    if ($sit['payee'] === 3)
      $this->checkDeduction($empRecData, $slrItemRecData['itemType'], $newRow);
  }

  protected function checkDeduction($empRecData, $slrItemType, $empRecRow)
  {
    $empNdx = $empRecData['ndx'];

    $q = [];
    array_push($q, 'SELECT * FROM [e10doc_slr_deductions]');
    array_push($q, ' WHERE [emp] = %i', $empNdx);
    array_push($q, ' AND [slrItem] = %i', $empRecRow['slrItem']);
    array_push($q, ' AND [bankAccount] = %s', $empRecRow['bankAccount']);
    array_push($q, ' AND [symbol1] = %s', $empRecRow['symbol1']);
    array_push($q, ' AND [symbol2] = %s', $empRecRow['symbol2']);
    array_push($q, ' AND [symbol3] = %s', $empRecRow['symbol3']);
    array_push($q, ' AND [docState] != %i', 9800);

    $deduction = $this->db()->query($q)->fetch();
    if ($deduction)
    {
      return;
    }

    $sit = $this->app()->cfgItem('e10doc.slr.slrItemTypes.'.$slrItemType);
    $newD = [
      'fullName' => Str::upToLen(($sit['sn'] ?? '!!!').': '.$empRecData['fullName'], 140),
      'emp' => $empNdx,
      'slrItem' => $empRecRow['slrItem'],
      'bankAccount' => $empRecRow['bankAccount'],
      'symbol1' => $empRecRow['symbol1'],
      'symbol2' => $empRecRow['symbol2'],
      'symbol3' => $empRecRow['symbol3'],
      'docState' => 1000, 'docStateMain' => 0,
    ];

    $this->db()->query('INSERT INTO [e10doc_slr_deductions]', $newD);
  }
}
