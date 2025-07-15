<?php

namespace e10pro\reports\waste_cz\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/*
 * class WasteReturnEngine
 */
class WasteReturnEngine extends Utility
{
  var $year = 0;
  var $dateBegin;
  var $dateEnd;
  var $wasteSettings = NULL;

  var $onlyCreateData = 0;
  var $wasteReturnRows = NULL;
  var $wasteReturnErrorLabels = [];

  /** @var \e10doc\core\TableHeads */
	var $tableHeads;
	/** @var \e10doc\core\TableRows */
	var $tableRows;

  var $documentNdx = 0;

  var $enabledCodesKinds;

  CONST rowDirIn = 0, rowDirOut = 1;

  CONST whcDirIn = 0, whcDirOut = 1, whcDirInitState = 2, whcDirMove = 3, whcDirProduction = 5;

  CONST personTypeHuman = 1, personTypeCompany = 2;
  var $handlingCodes = NULL;


  protected function init()
  {
    $this->enabledCodesKinds = [];
    $ack = $this->app()->cfgItem('e10.witems.codesKinds');
    foreach ($ack as $ackNdx => $ackDef)
    {
      if ($ackDef['codeType'] !== 31)
        continue;
      $this->enabledCodesKinds[] = $ackNdx;
    }

		$this->wasteSettings = $this->app()->cfgItem('e10doc.waster.settings.'.$this->year, NULL);

    $this->handlingCodes = $this->app()->cfgItem('e10doc.waster.handlingCodes', NULL);
  }

  public function resetYear()
  {
    $this->db()->query('DELETE FROM [e10pro_reports_waste_cz_returnRows] WHERE [calendarYear] = %i', $this->year);

    $this->addAllDocuments();
    $this->addWasteOps();
  }

  public function addAllDocuments()
  {
    $this->addDocuments('purchase', self::rowDirIn);
    $this->addDocuments('stockin', self::rowDirIn);
    $this->addDocuments('invno', self::rowDirOut);
    $this->addDocuments('stockout', self::rowDirOut);
    $this->addDocuments('wastelp', self::rowDirOut);
  }

  public function addDocuments($docType, $rowDir)
  {
		$wasteSettings = $this->app()->cfgItem('e10doc.waster.settings.'.$this->year, NULL);
		if (!$wasteSettings)
			return;

    if (!isset($wasteSettings['docModes'][$docType]) || $wasteSettings['docModes'][$docType] === 0)
			return;

		$q = [];

    array_push ($q, 'SELECT ');

		array_push ($q, ' [rows].item AS item, [rows].unit AS unit, [rows].quantity, [rows].itemType, [rows].taxBase, [rows].document,');
		array_push ($q, ' heads.docNumber as docNumber, heads.dateAccounting as dateAccounting, heads.warehouse as warehouse,');
    array_push ($q, ' heads.docType AS docType, heads.cashBoxDir AS cashBoxDir, heads.personType, heads.person,');
    array_push ($q, ' heads.otherAddress1,  heads.otherAddress1Mode, heads.deliveryAddress, heads.personNomencCity, heads.wasteOrigin');
		array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_personsContacts AS offices ON heads.otherAddress1 = offices.ndx');
		array_push ($q, ' WHERE  1');
    array_push ($q, ' AND [rows].rowType = %i', 0);
    array_push ($q, ' AND [heads].docType = %s', $docType);
    array_push ($q, ' AND [heads].docState = %i', 4000);

    if ($wasteSettings['docModes'][$docType] === 1)
      array_push ($q, ' AND [heads].addToWasteReport = %i', 1);

    if ($this->documentNdx)
      array_push ($q, ' AND [rows].[document] = %i', $this->documentNdx);
    else
    {
      array_push ($q, ' AND [heads].dateAccounting >= %d', $this->dateBegin);
      array_push ($q, ' AND [heads].dateAccounting <= %d', $this->dateEnd);
    }
    array_push ($q, ' ORDER BY [heads].[docNumber], [rows].[ndx]');

    $cnt = 0;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $rowDestData = [];
      $allDestData = [];

      $row = $r->toArray();
			$this->tableHeads->loadDocRowItemsCodes($row, $r['personType'], $row, NULL, $rowDestData, $allDestData);
      $moveCode = $this->loadCodeForMove($row, $row);

      if ($this->onlyCreateData)
      {
        if (isset($rowDestData['rowItemCodesDataErrors']))
        {
          foreach ($rowDestData['rowItemCodesDataErrors'] as $errLbl)
            $this->wasteReturnErrorLabels[] = $errLbl;
        }
      }

      foreach ($this->enabledCodesKinds as $eck)
      {
        if (!isset($rowDestData['rowItemCodesData'][$eck]))
        {
          //echo "\n".'! '.$r['docNumber'].': '.json_encode($rowDestData['rowItemCodesData'])."\n";
          continue;
        }
        //else
        //  echo "\n".'* '.$r['docNumber'].': '.json_encode($rowDestData['rowItemCodesData'][$eck])."\n";

        $newRow = [
          'calendarYear' => intval($r['dateAccounting']->format('Y')),
          'item' => $r['item'],
          'dir' => $rowDir,
          'wasteCodeText' => $rowDestData['rowItemCodesData'][$eck]['itemCodeText'],
          'wasteCodeNomenc' => $rowDestData['rowItemCodesData'][$eck]['itemCodeNomenc'],
          'wasteCodeKind' => $eck,
          'price' => $r['taxBase'],
          'unit' => $r['unit'],
          'quantity' => $r['quantity'],
          'quantityKG' => $this->quantityKG ($r['quantity'], $r['unit']),
          'document' => $r['document'],
          'dateAccounting' => $r['dateAccounting'],

          'person' => $r['person'],
          'personType' => $r['personType'],
          'addressMode' => $r['otherAddress1Mode'],
        ];

        if ($moveCode && $moveCode['itemCodeText'] !== $newRow['wasteCodeText'])
        {
          $newRow['wasteCodeTextMove'] = $moveCode['itemCodeText'];
          $newRow['wasteCodeNomencMove'] = $moveCode['itemCodeNomenc'];

          //$moveCode = ['itemCodeText' => $r['itemCodeText'], 'itemCodeNomenc' => $r['itemCodeNomenc']];
        }

        if ($rowDir === self::rowDirOut)
          $newRow['addressMode'] = 0;

        if ($newRow['personType'] === 1)
        { // human
          $newRow['personOffice'] = intval($r['deliveryAddress']);
          $newRow['natCityId'] = $this->natCityId($newRow);
        }
        else
        { // company
          if ($r['otherAddress1Mode'] == 0)
            $newRow['personOffice'] = intval($r['otherAddress1']); // office
          else
            $newRow['nomencCity'] = intval($r['personNomencCity']); // city
        }

        $handlingCode = $this->handlingCode($newRow);
        $newRow['wasteHandlingCode'] = $handlingCode;

        //if (!$newRow['wasteCodeText'] || $newRow['wasteCodeText'] === '')
        //  echo "\n".'! '.$r['docNumber'].': '.json_encode($rowDestData['rowItemCodesData'])."\n";

        if ($this->onlyCreateData)
        {
          $this->wasteReturnRows[] = $newRow;
        }
        else
          $this->db()->query('INSERT INTO [e10pro_reports_waste_cz_returnRows]', $newRow);
      }
      $cnt++;

      //if ($cnt % 1000 === 0)
      //  echo ". ".$cnt;
      //if ($cnt > 10000)
      //  break;
    }
    //echo "\n".$cnt." rows\n";
  }

  public function loadCodeForMove(array $headRecData, array $rowRecData)
	{
		$codesKinds = $this->app()->cfgItem('e10.witems.codesKinds', []);

    $personGroups = [18];
    $codeKind = 1;

		$docWasteOrigin = $headRecData['wasteOrigin'] ?? 0;


		$usedCodesKinds = [];
		$usedCodesKindRows = $this->db()->query('SELECT * FROM e10_witems_itemCodes WHERE [item] = %i', $rowRecData['item']);
		foreach ($usedCodesKindRows as $ucr)
		{
			if (!in_array($ucr['codeKind'], $usedCodesKinds))
				$usedCodesKinds[] = $ucr['codeKind'];
		}
		//$rowDestData ['rowItemCodesDataUsedKinds'] = $usedCodesKinds;

    $docWasteOrigin = 3; // merchant
		$rowDir = 2; // OUT

		$q = [];
		array_push ($q, 'SELECT [codes].*, [nomencItems].fullName AS nomencName');
		array_push ($q, ' FROM [e10_witems_itemCodes] AS [codes]');
		array_push ($q, ' LEFT JOIN  [e10_base_nomencItems] AS [nomencItems] ON [codes].[itemCodeNomenc] = [nomencItems].[ndx]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [codes].[item] = %i', $rowRecData['item']);
		array_push ($q, ' AND ([codes].[codeDir] = %i', $rowDir, ' OR [codes].[codeDir] = %i)', 0);
		array_push ($q, ' AND ([codes].[person] = %i', $headRecData['person'], ' OR [codes].[person] = %i)', 0);
    array_push ($q, ' AND [codes].[codeKind] = %i', $codeKind);

		/*if ($personType == 1) // human
			array_push ($q, ' AND ([codes].[personType] = %i', 2, ' OR [codes].[personType] = %i)', 0);
		elseif ($personType == 2) // company*/
			array_push ($q, ' AND ([codes].[personType] = %i', 1, ' OR [codes].[personType] = %i)', 0);

		// -- waste origin
		array_push ($q, ' AND ([codes].[wasteOrigin] = %i', $docWasteOrigin, ' OR [codes].[wasteOrigin] = %i)', 0);

		// -- date valid
		array_push ($q, ' AND ([codes].[validFrom] IS NULL', ' OR [codes].[validFrom] <= %d)', $headRecData['dateAccounting']);
		array_push ($q, ' AND ([codes].[validTo] IS NULL', ' OR [codes].[validTo] >= %d)', $headRecData['dateAccounting']);

    /*
    if (count($addressLabels))
		{
			array_push ($q, ' AND ([codes].[addressLabel] IN %in', $addressLabels, ' OR [codes].[addressLabel] = %i)', 0);
		}
		else
			array_push ($q, ' AND ([codes].[addressLabel] = %i)', 0);
    */

		array_push ($q, ' AND (');
		if (count($personGroups))
			array_push ($q, ' [codes].[personsGroup] IN %in', $personGroups, ' OR ');
		array_push ($q, ' [codes].[personsGroup] = %i', 0);
		array_push ($q, ')');

		array_push ($q, ' ORDER BY [codes].systemOrder');

		$codes = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ckNdx = $r['codeKind'];
			$ck = $codesKinds[$ckNdx];

			if (isset($codes[$ckNdx]))
				continue;

      /*
			if (!isset($destData ['itemCodesHeader'][$ckNdx]))
			{
				$destData ['itemCodesHeader'][$ckNdx] = $ck;
			}
      */

			$irc = $r->toArray();
			$irc['itemCodeName'] = $ck['fn'];
			if ($r['nomencName'])
				$irc['itemCodeTitle'] = $r['nomencName'];

			$codes[$ckNdx] = $irc;

//      {"id": "itemCodeText", "name": "Kód", "type": "string", "len": 60},
//      {"id": "itemCodeNomenc", "name": "Kód", "type": "int", "reference": "e10.base.nomencItems", "comboViewer": "combo"},


      $moveCode = ['itemCodeText' => $r['itemCodeText'], 'itemCodeNomenc' => $r['itemCodeNomenc']];
      return $moveCode;
		}

    return NULL;
//		$rowDestData ['rowItemCodesData'] = $codes;
	}

  protected function handlingCode($newRow)
  {
    foreach ($this->handlingCodes as $hcId => $hcCfg)
    {
      if (isset($hcCfg['validFrom']) && $newRow['dateAccounting'] < Utils::createDateTime($hcCfg['validFrom']))
        continue;
      if (isset($hcCfg['validTo']) && $newRow['dateAccounting'] > Utils::createDateTime($hcCfg['validTo']))
        continue;
      if (isset($hcCfg['personType']) && $newRow['personType'] !== $hcCfg['personType'])
        continue;
      if ($newRow['dir'] != $hcCfg['dir'])
        continue;

      return $hcId;
    }

    return '';
  }

  protected function natCityId($newRow)
  {
    $natCityId = 585068;

    $address = $this->app()->loadItem($newRow['personOffice'], 'e10.persons.personsContacts');
    if ($address)
    {
      $nc = $this->db()->query('SELECT * FROM e10_base_nomencItems WHERE shortName = %s', $address['adrCity'], ' AND [level] = %i', 2, ' AND id LIKE %s', 'cz-orp%')->fetch();
      if ($nc)
      {
        $natCityId = intval(substr($nc['itemId'], 2));
        if ($natCityId)
          return $natCityId;
      }
    }

    return $natCityId;
  }

	protected function quantityKG ($quantity, $unit)
	{
		switch ($unit)
		{
			case 'kg': return $quantity;
			case 'g': return $quantity / 1000;
      case 't': return $quantity * 1000;
		}
		return 0;
	}

  public function addWasteOps($wasteOpNdx = 0)
  {
		$q = [];

    array_push ($q, 'SELECT [wo].*');
		array_push ($q, ' FROM e10doc_waster_wasteOps AS [wo]');
		array_push ($q, ' WHERE  1');
    array_push ($q, ' AND [wo].docState = %i', 4000);

    if ($wasteOpNdx)
      array_push ($q, ' AND [wo].[ndx] = %i', $wasteOpNdx);
    else
    {
      array_push ($q, ' AND [wo].date >= %d', $this->dateBegin);
      array_push ($q, ' AND [wo].date <= %d', $this->dateEnd);
    }
    array_push ($q, ' ORDER BY [wo].[date], [wo].[ndx]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $newRow = [
        'rowSource' => 1,
        'calendarYear' => intval($r['date']->format('Y')),
        'unit' => $r['unit'],
        'quantity' => $r['quantity'],
        'quantityKG' => $this->quantityKG ($r['quantity'], $r['unit']),
        'wasteOp' => $r['ndx'],
        'dateAccounting' => $r['date'],
        'wasteHandlingCode' => $r['wasteHandlingCodeSrc'],
        'wasteCodeNomenc' => $r['wasteCodeNomencSrc'],
        'wasteCodeText' => $r['wasteCodeTextSrc'],

        'wasteCodeKind' => 1,
      ];

      if ($r['opType'] == 0)
      { // init state
        $newRow ['dir'] = self::rowDirIn;
        $this->db()->query('INSERT INTO [e10pro_reports_waste_cz_returnRows]', $newRow);
      }
      elseif ($r['opType'] == 1)
      { // end state
        $newRow ['dir'] = self::rowDirOut;
        $this->db()->query('INSERT INTO [e10pro_reports_waste_cz_returnRows]', $newRow);
      }
      elseif ($r['opType'] == 2)
      { // move out / in
        // -- move OUT
        $newRow ['dir'] = self::rowDirOut;
        $this->db()->query('INSERT INTO [e10pro_reports_waste_cz_returnRows]', $newRow);
        // -- move IN
        $newRow ['dir'] = self::rowDirIn;
        $newRow ['wasteHandlingCode'] = $r['wasteHandlingCodeDst'];
        $newRow ['wasteCodeNomenc'] = $r['wasteCodeNomencDst'];
        $newRow ['wasteCodeText'] = $r['wasteCodeTextDst'];
        $this->db()->query('INSERT INTO [e10pro_reports_waste_cz_returnRows]', $newRow);
      }
    }
  }

  public function resetDocument($documentNdx)
  {
    $this->init();

    $this->documentNdx = $documentNdx;

    $this->tableHeads = $this->app->table ('e10doc.core.heads');

    $this->db()->query('DELETE FROM [e10pro_reports_waste_cz_returnRows] WHERE [document] = %i', $this->documentNdx);

    $this->addAllDocuments();
  }

  public function createDataForDocument($documentNdx)
  {
    $this->onlyCreateData = 1;

    $this->init();

    $this->wasteReturnRows = [];
    $this->wasteReturnErrorLabels = [];
    $this->documentNdx = $documentNdx;
    $this->tableHeads = $this->app->table ('e10doc.core.heads');

    $this->addAllDocuments();
  }

  public function resetWasteOp($wasteOpNdx)
  {
    $this->init();
    $this->db()->query('DELETE FROM [e10pro_reports_waste_cz_returnRows] WHERE [wasteOp] = %i', $wasteOpNdx, ' AND [rowSource] = %i', 1);
    $this->addWasteOps($wasteOpNdx);
  }

  public function run()
  {
    $this->init();

    $this->dateBegin = $this->year.'-01-01';
    $this->dateEnd = $this->year.'-12-31';

    $this->tableHeads = $this->app->table ('e10doc.core.heads');

    $this->resetYear();
  }
}
