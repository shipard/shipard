<?php

namespace e10pro\reports\waste_cz\libs;
use \Shipard\Base\Utility;



class WasteReturnEngine extends Utility
{
  var $year = 0;
  var $dateBegin;
  var $dateEnd;

  /** @var \e10doc\core\TableHeads */
	var $tableHeads;
	/** @var \e10doc\core\TableRows */
	var $tableRows;

  var $wasteItemCodeNdx = 1;


  public function resetYear()
  {
    $this->db()->query('DELETE FROM [e10pro_reports_waste_cz_returnRows] WHERE [calendarYear] = %i', $this->year);


    $this->addPurchases();
  }

  public function addPurchases()
  {
		$q = [];

    array_push ($q, 'SELECT ');

		array_push ($q, ' [rows].item AS item, [rows].unit AS unit, [rows].quantity, [rows].itemType, [rows].taxBase, [rows].document,');
		array_push ($q, ' heads.docNumber as docNumber, heads.dateAccounting as dateAccounting, heads.warehouse as warehouse,');
    array_push ($q, ' heads.docType AS docType, heads.cashBoxDir AS cashBoxDir, heads.personType, heads.person, heads.otherAddress1, heads.deliveryAddress');
		array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_personsContacts AS offices ON heads.otherAddress1 = offices.ndx');
		array_push ($q, ' WHERE  1');

    array_push ($q, ' AND [rows].rowType = %i', 0);
    array_push ($q, ' AND [heads].docType = %s', 'purchase');
    array_push ($q, ' AND [heads].docState = %i', 4000);
    array_push ($q, ' AND [heads].dateAccounting >= %d', $this->dateBegin);
    array_push ($q, ' AND [heads].dateAccounting <= %d', $this->dateEnd);

    array_push ($q, ' ORDER BY [heads].[docNumber]');

    $cnt = 0;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $rowDestData = [];
      $allDestData = [];

      $row = $r->toArray();
			$this->tableHeads->loadDocRowItemsCodes($row, $r['personType'], $row, NULL, $rowDestData, $allDestData);

      if (!isset($rowDestData['rowItemCodesData'][$this->wasteItemCodeNdx]))
      {
        // echo '! '.$r['docNumber'].': '.json_encode($rowDestData['rowItemCodesData'])."\n";
        continue;
      }
      //else
      //  echo '* '.$r['docNumber'].': '.json_encode($rowDestData['rowItemCodesData'][$this->wasteItemCodeNdx])."\n";

      $newRow = [
        'calendarYear' => $this->year,
        'item' => $r['item'],
        'dir' => 0,
        'wasteCodeText' => $rowDestData['rowItemCodesData'][$this->wasteItemCodeNdx]['itemCodeText'],
        'wasteCodeNomenc' => $rowDestData['rowItemCodesData'][$this->wasteItemCodeNdx]['itemCodeNomenc'],
        'price' => $r['taxBase'],
        'unit' => $r['unit'],
        'quantity' => $r['quantity'],
        'quantityKG' => $this->quantityKG ($r['quantity'], $r['unit']),
        'document' => $r['document'],
        'dateAccounting' => $r['dateAccounting'],

        'person' => $r['person'],
        'personType' => $r['personType'],
        'personOffice' => intval($r['otherAddress1']),
      ];

      if ($newRow['personType'] === 1)
      { // human
        $newRow['personOffice'] = intval($r['deliveryAddress']);
      }

      $this->db()->query('INSERT INTO [e10pro_reports_waste_cz_returnRows]', $newRow);

      $cnt++;

      if ($cnt % 1000 === 0)
        echo ". ".$cnt;
      //if ($cnt > 10000)
      //  break;
    }
    echo "\n".$cnt." rows\n";
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

  public function run()
  {
    $this->dateBegin = $this->year.'-01-01';
    $this->dateEnd = $this->year.'-12-31';

    $this->tableHeads = $this->app->table ('e10doc.core.heads');


    $this->resetYear();
  }
}
