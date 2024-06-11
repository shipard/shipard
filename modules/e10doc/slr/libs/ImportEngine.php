<?php
namespace e10doc\slr\libs;
use \Shipard\Base\Utility;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Json;


/**
 * class ImportEngine
 */
class ImportEngine extends Utility
{
  var $importNdx = 0;

  var $emps = [];
  var $slrItems = [];


  public function setImportNdx($importNdx)
  {
    $this->importNdx = $importNdx;
  }

  protected function doImport()
  {
    $this->doAllAttachments();
  }

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
        ' INNER JOIN e10doc_slr_empsRecs ON  e10doc_slr_empsRecs.ndx = e10doc_slr_empsRecsRows.empsRec',
        ' WHERE [e10doc_slr_empsRecs].[import] = %i', $this->importNdx);
    $this->db()->query('DELETE FROM [e10doc_slr_empsRecs] WHERE [import] = %i', $this->importNdx);

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

    $this->db()->query('INSERT INTO [e10doc_slr_empsRecsRows]', $newRow);
  }

  protected function loadEmp($personalId)
  {
    if (array_key_exists($personalId, $this->emps))
      return $this->emps[$personalId];

    $empRecData = NULL;
    $empsRows = $this->db()->query('SELECT * FROM [e10doc_slr_emps] WHERE [personalId] = %s', $personalId,
                                       ' AND [docState] != %i', 9800);

    $cnt = 0;
    foreach ($empsRows as $r)
    {
      if (!$cnt)
        $empRecData = $r->toArray();
      $cnt++;
    }

    if ($cnt === 1)
    {
      $this->emps[$personalId] = $empRecData;
      return $this->emps[$personalId];
    }

    if ($cnt === 0)
    {
      $personRecData = NULL;
      $personRecData = $this->db()->query('SELECT * FROM [e10_persons_persons] WHERE [personalId] = %s', $personalId,
                                          ' AND [docState] != %i', 9800)->fetch();
      if ($personRecData)
      {
        $newEmp = [
          'fullName' => $personRecData['fullName'],
          'personalId' => $personalId,
          'docState' => 1000, 'docStateMain' => 0,
        ];
      }
      else
      {
        $newEmp = [
          'fullName' => 'Zaměstnanec č. `'.$personalId.'`',
          'personalId' => $personalId,
          'docState' => 1000, 'docStateMain' => 0,
        ];
      }

      $this->db()->query('INSERT INTO [e10doc_slr_emps]', $newEmp);
      $newEmpRecNdx = $this->db()->getInsertId ();
      $this->emps[$personalId] = $this->app()->loadItem($newEmpRecNdx, 'e10doc.slr.emps');

      return $this->emps[$personalId];
    }

    $this->err('Existuje více zaměstnanců s osobním číslem `'.$personalId.'`');

    return NULL;
  }

  protected function loadSlrItem($importId, $slrItemTitle)
  {
    if (isset($this->slrItems[$importId]))
      return $this->slrItems[$importId];

    $slrItemRecData = NULL;
    $slrItemsRows = $this->db()->query('SELECT * FROM [e10doc_slr_slrItems] WHERE [importId] = %s', $importId,
                                        ' AND [docState] != %i', 9800);

    $cnt = 0;
    foreach ($slrItemsRows as $r)
    {
      if (!$cnt)
        $slrItemRecData = $r->toArray();
      $cnt++;
    }

    if ($cnt === 1)
    {
      $this->slrItems[$importId] = $slrItemRecData;
      return $slrItemRecData;
    }

    if ($cnt === 0)
    {
      $newSlrItem = [
        'importId' => $importId,
        'fullName' => $slrItemTitle, 'shortName' => $slrItemTitle,
        'docState' => 1000, 'docStateMain' => 0,
      ];
      $this->db()->query ('INSERT INTO [e10doc_slr_slrItems] ', $newSlrItem);
      $newSlrNdx = $this->db()->getInsertId ();
      $slrItemRecData = $this->app()->loadItem($newSlrNdx, 'e10doc.slr.slrItems');
      $this->slrItems[$importId] = $slrItemRecData;
      return $slrItemRecData;
    }
  }

  public function run()
  {

    $this->doImport();
  }
}
