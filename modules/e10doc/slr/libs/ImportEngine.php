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

  var $persons = [];
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
    if (!$data || !isset($data['Shipard']['RozuctPolozka']))
    {
      return;
    }

    $this->db()->query('DELETE [e10doc_slr_personsRecsRows] FROM [e10doc_slr_personsRecsRows] ',
        ' INNER JOIN e10doc_slr_personsRecs ON  e10doc_slr_personsRecs.ndx = e10doc_slr_personsRecsRows.personsRec',
        ' WHERE [e10doc_slr_personsRecs].[import] = %i', $this->importNdx);
    $this->db()->query('DELETE FROM [e10doc_slr_personsRecs] WHERE [import] = %i', $this->importNdx);

    foreach ($data['Shipard']['RozuctPolozka'] as $oneItem)
    {
      if ($this->app()->debug)
        echo "   -- ".json_encode($oneItem)."\n";

      $personRecData = $this->loadPerson($oneItem['OsCislo']);
      $slrItemRecData = $this->loadSlrItem($oneItem['MzPolozka'], $oneItem['Popis']);

      if (!$personRecData || !$slrItemRecData)
        continue;

      $this->addOneItem($personRecData['ndx'], $slrItemRecData['ndx'], $oneItem);
    }
  }

  protected function addOneItem($personNdx, $slrItemNdx, $item)
  {
    $personsRecNdx = 0;
    $personRecRecData = $this->db()->query('SELECT * FROM [e10doc_slr_personsRecs] WHERE',
                                    ' [person] = %i', $personNdx,
                                    ' AND [import] = %i', $this->importNdx)->fetch();
    if ($personRecRecData)
    {
      $personsRecNdx = $personRecRecData['ndx'];
    }
    else
    {
      $newPersonRec = [
         'person' => $personNdx,
         'import' => $this->importNdx,
         'docState' => 1000, 'docStateMain' => 0,
      ];
      $this->db()->query('INSERT INTO [e10doc_slr_personsRecs]', $newPersonRec);

      $personsRecNdx = $this->db()->getInsertId ();
    }

    $newRow = [
      'rowOrder' => 100,
      'personsRec' => $personsRecNdx,
      'slrItem' => $slrItemNdx,
      'amount' => $item['Castka'],
    ];

    $this->db()->query('INSERT INTO [e10doc_slr_personsRecsRows]', $newRow);
  }

  protected function loadPerson($personalId)
  {
    if (array_key_exists($personalId, $this->persons))
      return $this->persons[$personalId];

    $personRecData = NULL;
    $personsRows = $this->db()->query('SELECT * FROM [e10_persons_persons] WHERE [personalId] = %s', $personalId,
                                        ' AND [docState] != %i', 9800);

    $cnt = 0;
    foreach ($personsRows as $r)
    {
      if (!$cnt)
        $personRecData = $r->toArray();
      $cnt++;
    }

    if ($cnt === 1)
    {
      $this->persons[$personalId] = $personRecData;
      return $this->persons[$personalId];
    }

    if ($cnt === 0)
    {
      $this->err('Neznámé osobní číslo `'.$personalId.'`');
      $this->persons[$personalId] = NULL;
      return $this->persons[$personalId];
    }

    $this->err('Existuje více osob s osobním číslem `'.$personalId.'`');

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
