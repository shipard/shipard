<?php
namespace e10doc\slr\libs\imports;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Json;


/**
 * class ImportEngineCZPohoda
 */
class ImportEngineCZPohoda extends \e10doc\slr\libs\ImportEngine
{
  var $srcData = [];

  var $slrIds_PrehledMezd = [
    0 => ['id' => 'hr-mzda', 'fn' => 'Hrubá mzda', 'i' => 1],
    1 => ['id' => 'sp-prac', 'fn' => 'Sociální pojištění (odvod za zaměstnance)', 'i' => 0],
    2 => ['id' => 'zp-prac', 'fn' => 'Zdravotní pojištění (odvod za zaměstnance)', 'i' => 0],
    3 => ['id' => 'dan', 'fn' => 'Daň', 'i' => 1],
    4 => ['id' => 'slevy', 'fn' => 'Slevy', 'i' => 0],
    5 => ['id' => 'bonus', 'fn' => 'Bonus', 'i' => 1],
    6 => ['id' => 'rocni-zuct', 'fn' => 'Roční zůčtování', 'i' => 0],
    7 => ['id' => 'nahrady', 'fn' => 'Náhrady', 'i' => 1],
    8 => ['id' => 'nezd-nahrady', 'fn' => 'Nezdaněné náhrady', 'i' => 1],
    9 => ['id' => 'srazky', 'fn' => 'Srážky', 'i' => 0],
    10 => ['id' => 'zaloha', 'fn' => 'Záloha', 'i' => 0],
    11 => ['id' => 'stravne', 'fn' => 'Stravné', 'i' => 1],
    12 => ['id' => 'cista-mzda', 'fn' => 'Čistá mzda', 'i' => 1],
  ];

  var $slrIds_PrehledSocialniho = [
    0 => ['i' => 0],
    1 => ['i' => 0],
    2 => ['id' => 'hr-mzda', 'fn' => 'Hrubá mzda', 'i' => 0],
    3 => ['id' => 'hr-mzda', 'fn' => 'Hrubá mzda Základ', 'i' => 0],
    4 => ['id' => 'sp-prac', 'fn' => 'Sociální pojištění (odvod za zaměstnance)', 'i' => 1],
    5 => ['id' => 'sp-firma', 'fn' => 'Sociální pojištění (odvod za zaměstnavatele)', 'i' => 1],
    6 => ['i' => 0],
  ];

  var $slrIds_PrehledZdravotniho = [
    0 => ['i' => 0],
    1 => ['i' => 0],
    2 => ['id' => 'hr-mzda', 'fn' => 'Hrubá mzda', 'i' => 0],
    3 => ['id' => 'hr-mzda', 'fn' => 'Hrubá mzda Základ', 'i' => 0],
    4 => ['id' => 'zp-prac', 'fn' => 'Zdravotní pojištění (odvod za zaměstnance)', 'i' => 1],
    5 => ['id' => 'zp-firma', 'fn' => 'Zdravotní pojištění (odvod za zaměstnavatele)', 'i' => 1],
    6 => ['i' => 0],
  ];

  protected function doAllAttachments()
  {
		foreach ($this->allAttachments as $a)
		{
      if ($a['filetype'] !== 'xlsx')
        continue;

			$srcFullFileName = __APP_DIR__.'/att/'. $a['path'].$a['filename'];

      if ($this->app()->debug)
        echo " --> ".$srcFullFileName."\n";

      $table = [];
      $excelEngine = new \lib\E10Excel ($this->app);
      $spreadsheet = $excelEngine->load ($srcFullFileName);
      $excelEngine->getSheetAsTable ($spreadsheet, 0, $table, 60);

      if (isset($table[0][1]) && $table[0][1] === 'Přehled mezd')
        $this->srcData['prehled-mezd'] = $table;
      elseif (isset($table[0][1]) && $table[0][1] === 'Soupis sociálního pojištění')
        $this->srcData['soupis-socialniho'] = $table;
      elseif (isset($table[0][1]) && $table[0][1] === 'Soupis zdravotního pojištění')
        $this->srcData['soupis-zdravotniho'] = $table;

      unset($spreadsheet);
      unset($excelEngine);
    }

    if (!isset($this->srcData['prehled-mezd']))
    {
      return;
    }

    $this->importFile_prehledMezd();
    $this->importFile_soupisSocialniho();
    $this->importFile_soupisZdravotniho();
  }

  protected function importFile_prehledMezd()
  {
    $this->db()->query('DELETE [e10doc_slr_empsRecsRows] FROM [e10doc_slr_empsRecsRows] ',
                       ' INNER JOIN e10doc_slr_empsRecs ON e10doc_slr_empsRecs.ndx = e10doc_slr_empsRecsRows.empsRec',
                       ' WHERE [e10doc_slr_empsRecs].[import] = %i', $this->importNdx);
    $this->db()->query('DELETE FROM [e10doc_slr_empsRecs] WHERE [import] = %i', $this->importNdx, ' AND [docAcc] = %i', 0);

    $iid = sprintf('%02d/%04d', $this->importRecData['calendarMonth'], $this->importRecData['calendarYear']);

    $pm = $this->srcData['prehled-mezd'];
    foreach ($pm as $rowId => $row)
    {
      if ($row[1] !== $iid)
        continue;

      $empName = $row[4] ?? NULL;
      $empPersonalId = $row[13] ?? NULL;
      if (!$empName || !$empPersonalId)
        continue;

      $empRecData = $this->loadEmp($empPersonalId, $empName);

      $empAmounts = [];
      foreach ($pm[$rowId + 1] as $valueColId => $value)
      {
        if ($value === NULL)
          continue;

        $empAmounts[] = $value;
      }

      foreach ($empAmounts as $empAmoundNdx => $empAmount)
      {
        $si = $this->slrIds_PrehledMezd[$empAmoundNdx] ?? NULL;
        if (!$si || !$si['i'])
          continue;
        if (!$empAmount)
          continue;

        $slrItemId = $si['id'];
        if (isset($empRecData['empKindCfg']['slrItemIdSuffix']) && $empRecData['empKindCfg']['slrItemIdSuffix'] !== '')
          $slrItemId .= '-'.$empRecData['empKindCfg']['slrItemIdSuffix'];

        $slrItemRecData = $this->loadSlrItem($slrItemId, $si['fn']);

        if ($slrItemRecData['negativeAmount'] ?? 0)
          $empAmount = - $empAmount;

        $oneItem = ['amount' => $empAmount, 'hours' => 0];
        $this->addOneItem($empRecData, $slrItemRecData['ndx'], $oneItem);
      }
    }
  }

  protected function importFile_soupisSocialniho()
  {
    if (!isset($this->srcData['soupis-socialniho']))
      return;

    $ss = $this->srcData['soupis-socialniho'];
    foreach ($ss as $rowId => $row)
    {
      if (!$row[1] || $row[1] == '')
        continue;

      $pid = $row[1];
      if (!isset($this->emps[$pid]))
        continue;

      $empRecData = $this->loadEmp($pid);

      $empAmounts = [];
      foreach ($ss[$rowId] as $valueColId => $value)
      {
        if ($value === NULL)
          continue;

        $empAmounts[] = $value;
      }

      foreach ($empAmounts as $empAmoundNdx => $empAmount)
      {
        $si = $this->slrIds_PrehledSocialniho[$empAmoundNdx] ?? NULL;
        if (!$si || !$si['i'])
          continue;
        if (!$empAmount)
          continue;

        $slrItemRecData = $this->loadSlrItem($si['id'], $si['fn']);
        $oneItem = ['amount' => $empAmount, 'hours' => 0];
        $this->addOneItem($empRecData, $slrItemRecData['ndx'], $oneItem);
      }

      //echo json_encode($empAmounts)."\n";
    }
  }

  protected function importFile_soupisZdravotniho()
  {
    if (!isset($this->srcData['soupis-zdravotniho']))
      return;

    $sz = $this->srcData['soupis-zdravotniho'];
    foreach ($sz as $rowId => $row)
    {
      if (!$row[1] || $row[1] == '')
        continue;

      $pid = $row[1];
      if (!isset($this->emps[$pid]))
        continue;

      $empRecData = $this->loadEmp($pid);

      $empAmounts = [];
      foreach ($sz[$rowId] as $valueColId => $value)
      {
        if ($value === NULL)
          continue;

        $empAmounts[] = $value;
      }

      foreach ($empAmounts as $empAmoundNdx => $empAmount)
      {
        $si = $this->slrIds_PrehledZdravotniho[$empAmoundNdx] ?? NULL;
        if (!$si || !$si['i'])
          continue;
        if (!$empAmount)
          continue;

        $slrItemRecData = $this->loadSlrItem($si['id'], $si['fn']);
        $oneItem = ['amount' => $empAmount, 'hours' => 0];
        $this->addOneItem($empRecData, $slrItemRecData['ndx'], $oneItem);
      }
    }
  }

  protected function addOneItem($empRecData, $slrItemNdx, $item)
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
      'slrItem' => $slrItemNdx,
      'amount' => $item['amount'] ?? 0,
    ];

    $q = $item['hours'] ?? 0;
    if ($q)
    {
      $newRow['quantity'] = $q;
      $newRow['unit'] = 'hr';
    }

    $centreNdx = $this->searchCentre($empRecData, $slrItemNdx);
    if ($centreNdx)
    {
      $newRow['centre'] = $centreNdx;
    }

    $this->db()->query('INSERT INTO [e10doc_slr_empsRecsRows]', $newRow);
  }
}
