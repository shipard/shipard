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
  var $importRecData = NULL;
  var $allAttachments = [];

  var $emps = [];
  var $slrItems = [];

  public function setImportNdx($importNdx)
  {
    $this->importNdx = $importNdx;
    $this->importRecData = $this->app()->loadItem($this->importNdx, 'e10doc.slr.imports');
  }

  protected function doImport()
  {
    $this->loadAllAttachmnents();
    $this->doAllAttachments();
  }

  protected function loadAllAttachmnents()
  {
    $this->allAttachments = UtilsBase::loadAllRecAttachments($this->app(), 'e10doc.slr.imports', $this->importNdx);
    //if ($this->app()->debug)
    //  print_r($this->allAttachments);
    //doAllAttachments
  }

  protected function doAllAttachments()
  {
		if (!count($this->allAttachments))
			return;

		foreach ($this->allAttachments as $a)
		{
			$srcFullFileName = __APP_DIR__.'/att/'. $a['path'].$a['filename'];

      if ($this->app()->debug)
        echo " --> ".$srcFullFileName."\n";

      $this->importFile($srcFullFileName);
    }
  }

  protected function importFile($fileName)
  {
  }

  protected function loadEmp($personalId, $empName = '')
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
      if ($empRecData['empKind'])
      {
        $empKindRecData = $this->app()->loadItem($empRecData['empKind'], 'e10doc.slr.empsKinds');
        if ($empKindRecData)
          $empRecData['empKindCfg'] = $empKindRecData;
      }

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

        if ($empName !== '')
          $newEmp['fullName'] = $empName;
      }

      $this->db()->query('INSERT INTO [e10doc_slr_emps]', $newEmp);
      $newEmpRecNdx = $this->db()->getInsertId ();
      $this->emps[$personalId] = $this->app()->loadItem($newEmpRecNdx, 'e10doc.slr.emps');

      return $this->emps[$personalId];
    }

    $this->err('Existuje více zaměstnanců s osobním číslem `'.$personalId.'`');

    return NULL;
  }

  protected function loadSlrItem($importId, $slrItemTitle, $slrItemType = 0)
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
        'itemType' => $slrItemType,
        'docState' => 1000, 'docStateMain' => 0,
      ];
      $this->db()->query ('INSERT INTO [e10doc_slr_slrItems] ', $newSlrItem);
      $newSlrNdx = $this->db()->getInsertId ();
      $slrItemRecData = $this->app()->loadItem($newSlrNdx, 'e10doc.slr.slrItems');
      $this->slrItems[$importId] = $slrItemRecData;
      return $slrItemRecData;
    }
  }

  protected function searchCentreById($centreId)
  {
    $centreImportRecData = $this->db()->query('SELECT * FROM [e10doc_slr_centres] WHERE [importId] = %s', $centreId,
                                        ' AND [docState] != %i', 9800)->fetch();
    if ($centreImportRecData)
    {
      $centreRecData = $this->app()->loadItem($centreImportRecData['centre'], 'e10doc.base.centres');
      return $centreRecData;
    }

    $centreRecData = $this->db()->query('SELECT * FROM [e10doc_base_centres] WHERE [id] = %s', $centreId,
                                        ' AND [docState] != %i', 9800)->fetch();
    if ($centreRecData)
      return $centreRecData->toArray();

    return NULL;
  }

  protected function searchCentre($empRecData, $slrItemNdx)
  {
    $empSlrItemCentre = $this->db()->query('SELECT * FROM [e10doc_slr_empsCentres] WHERE ',
                                            '[emp] = %i', $empRecData['ndx'],
                                            ' AND [slrItem] = %i', $slrItemNdx)->fetch();
    if ($empSlrItemCentre && $empSlrItemCentre['centre'])
      return $empSlrItemCentre['centre'];

    if ($empRecData['slrCentre'])
      return $empRecData['slrCentre'];

    return 0;
  }

  public function run()
  {
    $this->doImport();
  }
}
