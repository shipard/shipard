<?php

namespace e10pro\reports\waste_cz\libs;
use \Shipard\Base\Utility;


/*
 * class WasteReturnEngine
 */
class WasteReturnEngine extends Utility
{
  var $year = 0;
  var $dateBegin;
  var $dateEnd;

  /** @var \e10doc\core\TableHeads */
	var $tableHeads;
	/** @var \e10doc\core\TableRows */
	var $tableRows;

  var $documentNdx = 0;

  var $enabledCodesKinds;

  CONST rowDirIn = 0, rowDirOut = 1;
  CONST personTypeHuman = 1, personTypeCompany = 2;


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
  }

  public function resetYear()
  {
    $this->db()->query('DELETE FROM [e10pro_reports_waste_cz_returnRows] WHERE [calendarYear] = %i', $this->year);

    $this->addPurchases();
    $this->addInvoicesOut();
  }

  public function addDocuments($docType, $rowDir)
  {
		$q = [];

    array_push ($q, 'SELECT ');

		array_push ($q, ' [rows].item AS item, [rows].unit AS unit, [rows].quantity, [rows].itemType, [rows].taxBase, [rows].document,');
		array_push ($q, ' heads.docNumber as docNumber, heads.dateAccounting as dateAccounting, heads.warehouse as warehouse,');
    array_push ($q, ' heads.docType AS docType, heads.cashBoxDir AS cashBoxDir, heads.personType, heads.person,');
    array_push ($q, ' heads.otherAddress1,  heads.otherAddress1Mode, heads.deliveryAddress, heads.personNomencCity');
		array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_personsContacts AS offices ON heads.otherAddress1 = offices.ndx');
		array_push ($q, ' WHERE  1');
    array_push ($q, ' AND [rows].rowType = %i', 0);
    array_push ($q, ' AND [heads].docType = %s', $docType);
    array_push ($q, ' AND [heads].docState = %i', 4000);

    if ($this->documentNdx)
      array_push ($q, ' AND [rows].[document] = %i', $this->documentNdx);
    else
    {
      array_push ($q, ' AND [heads].dateAccounting >= %d', $this->dateBegin);
      array_push ($q, ' AND [heads].dateAccounting <= %d', $this->dateEnd);
    }
    array_push ($q, ' ORDER BY [heads].[docNumber]');

    $cnt = 0;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $rowDestData = [];
      $allDestData = [];

      $row = $r->toArray();
			$this->tableHeads->loadDocRowItemsCodes($row, $r['personType'], $row, NULL, $rowDestData, $allDestData);

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

        if ($rowDir === self::rowDirOut)
          $newRow['addressMode'] = 0;

        if ($newRow['personType'] === 1)
        { // human
          $newRow['personOffice'] = intval($r['deliveryAddress']);
        }
        else
        { // company
          if ($r['otherAddress1Mode'] == 0)
            $newRow['personOffice'] = intval($r['otherAddress1']); // office
          else
            $newRow['nomencCity'] = intval($r['personNomencCity']); // city
        }

        //if (!$newRow['wasteCodeText'] || $newRow['wasteCodeText'] === '')
        //  echo "\n".'! '.$r['docNumber'].': '.json_encode($rowDestData['rowItemCodesData'])."\n";

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

  public function addPurchases()
  {
    $this->addDocuments('purchase', self::rowDirIn);
  }

  public function addInvoicesOut()
  {
    $this->addDocuments('invno', self::rowDirOut);
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

  public function resetDocument($documentNdx)
  {
    $this->init();

    $this->documentNdx = $documentNdx;

    $this->tableHeads = $this->app->table ('e10doc.core.heads');

    $this->db()->query('DELETE FROM [e10pro_reports_waste_cz_returnRows] WHERE [document] = %i', $this->documentNdx);

    $this->addPurchases();
    $this->addInvoicesOut();
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
