<?php

namespace e10pro\purchase\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Utils;
use \e10doc\core\libs\E10Utils;
use \Shipard\Utils\TableRenderer;


/**
 * Class OperationalLogEngine
 */
class OperationalLogEngine extends Utility
{
  var $authors = [];
  var $purchasesPersons = [];
  var $wasteCodes = [];


  public function addLog ($title, $text)
  {
    $table = $this->app()->table('e10pro.purchase.operationalLog');

    $newItem = ['title' => $title, 'text' => $text];
    $table->dbInsertRec($newItem);
  }

  public function createLogRecordForDate($date)
  {
    $this->authors = [];
    $this->purchasesPersons = [];
    $this->wasteCodes = [];

    $q = [];

		array_push($q, 'SELECT heads.*,');
    array_push($q, ' authors.fullName AS authorName, persons.personType AS personPersonType');
    array_push($q, ' FROM [e10doc_core_heads] AS [heads]');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS authors ON heads.author = authors.ndx');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
    array_push($q, ' WHERE 1');
    array_push ($q, ' AND heads.dateAccounting = %d', $date);
    array_push ($q, ' AND heads.docType = %s', 'purchase');
    array_push ($q, ' AND heads.docState = %i', 4000);
		array_push ($q, ' ORDER BY heads.[dateAccounting], heads.activateTimeFirst, heads.[docNumber]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $authorNdx = $r['author'];
      if (!isset($this->authors[$authorNdx]))
      {
        $this->authors[$authorNdx] = [
          'fullName' => $r['authorName'],
          'tsBegin' => $r['activateTimeFirst'],
          'tsEnd' => $r['activateTimeFirst'],
        ];
      }

      if ($r['activateTimeFirst'] < $this->authors[$authorNdx]['tsBegin'])
        $this->authors[$authorNdx]['tsBegin'] = $r['activateTimeFirst'];
      if ($r['activateTimeFirst'] > $this->authors[$authorNdx]['tsEnd'])
        $this->authors[$authorNdx]['tsEnd'] = $r['activateTimeFirst'];



      $this->createLogRecordPurchases($r);
      $this->createLogRecordWastes($r);
    }

    if (count($this->authors) === 0)
      return;

    $logRecord = [
      'date' => $date,
      'author' => key($this->authors),
      'title' => 'Denní přehled: '.Utils::datef($date, '%n %d'),
      'text' => $this->createLogRecordText($date),
      'recordType' => 99, 'docState' => 4000, 'docStateMain' => 2,
    ];

    $existedlogRecord = $this->app()->db()->query('SELECT * FROM [e10pro_purchase_operationalLog] WHERE [date] = %d', $date,
                        ' AND recordType = %i', 99)->fetch();

    if ($existedlogRecord)
    { // update
      $this->app()->db()->query('UPDATE [e10pro_purchase_operationalLog] SET ', $logRecord, ' WHERE [ndx] = %i', $existedlogRecord['ndx']);
    }
    else
    { // new
      $this->app()->db()->query('INSERT INTO [e10pro_purchase_operationalLog] ', $logRecord);
    }
  }

  protected function createLogRecordText($date)
  {
    $text = '## Obsluha informačního systému (výkupy):'."\n";
    foreach ($this->authors as $a)
    {
      $text .= "- ".$a['fullName'].', čas: '.Utils::datef($a['tsBegin'], '%T').' - '.Utils::datef($a['tsEnd'], '%T')."\n";
    }

    $totalPurchases = 0;
    $totalQuantity = 0.0;
    $text .= '## Vykoupené množství odpadu:'."\n";
    $text .= ".[default fullWidth]\n";
    $text .= '|---------------------------------------------------'."\n";
    $text .= "|Občané/firmy".'|'.'Počet výkupů .>'.'|'.'Množství kg .>'."\n";
    $text .= '|---------------------------------------------------'."\n";
    foreach ($this->purchasesPersons as $personType => $a)
    {
      $text .= "|".$a['title'].'|'.$a['count'].' .>|'.Utils::nf($a['quantity'], 3).' kg .>'."\n";
      $totalPurchases += $a['count'];
      $totalQuantity += $a['quantity'];
    }
    $text .= "| **Celkem** |".$totalPurchases.' .>|'.Utils::nf($totalQuantity, 3).' kg .>'."\n";



    $totalQuantity = 0.0;
    $text .= '## Katalogová čísla odpadů:'."\n";
    $text .= ".[default fullWidth]\n";
    $text .= '|---------------------------------------------------'."\n";
    $text .= "|Kat. č. odp.".'|'.'Název'.'|'.'Množství kg .>'."\n";
    $text .= '|---------------------------------------------------'."\n";
    foreach ($this->wasteCodes as $wc => $a)
    {
      $text .= "|".$a['wc'].'|'.$a['fullName'].'|'.Utils::nf($a['quantity'], 3).' kg .>'."\n";
      $totalPurchases += $a['count'];
      $totalQuantity += $a['quantity'];
    }
    $text .= "| **Celkem** ||".Utils::nf($totalQuantity, 3).' kg .>'."\n";

    $text .= "\n\n";

    $this->createAtt14($date, $text);

    return $text;
  }

  protected function createLogRecordPurchases($docRecData)
  {
    $personType = $docRecData['personPersonType'];

    if (!isset($this->purchasesPersons[$personType]))
    {
      $this->purchasesPersons[$personType] = [
        'title' => ($personType === 1) ? 'Občané' : (($personType === 2) ? 'Firmy' : 'Jiné'),
        'count' => 0,
        'quantity' => 0.0,
      ];
    }
    $this->purchasesPersons[$personType]['count']++;

    $q = [];
    array_push($q, 'SELECT [rows].*');
    array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [rows].document = %i', $docRecData['ndx']);
    array_push($q, ' AND [rows].rowType = %i', 0); // item
    array_push($q, ' ORDER BY [rows].[ndx]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $quantity = $this->quantity($r['quantity'], $r['unit'], 'kg');
      $this->purchasesPersons[$personType]['quantity'] += $quantity;
    }
  }

  protected function createLogRecordWastes($docRecData)
  {
    $wasteHandlingCodes = $this->app->cfgItem('e10doc.waster.handlingCodes', []);

    $q = [];
    array_push($q, 'SELECT [wasteRows].*, nomencItems.fullName, nomencItems.itemId');
    array_push($q, ' FROM [e10pro_reports_waste_cz_returnRows] AS [wasteRows]');
    array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [wasteRows].wasteCodeNomenc = nomencItems.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [wasteRows].document = %i', $docRecData['ndx']);
    array_push($q, ' AND [wasteRows].dir = %i', 0); // in
    array_push($q, ' AND [wasteRows].rowSource = %i', 0);
    array_push($q, ' ORDER BY [wasteRows].[ndx]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $whcCfg = $wasteHandlingCodes[$r['wasteHandlingCode']] ?? NULL;
      if (!$whcCfg)
        continue;
      if ($whcCfg['dir'] != 0) // in
        continue;

      $quantity = $r['quantityKG'];
      $wc = 'W'.$r['wasteCodeText'];

      if (!isset($this->wasteCodes[$wc]))
      {
        $this->wasteCodes[$wc] = [
          'wc' => $r['itemId'],
          'fullName' => $r['fullName'],
          'count' => 0,
          'quantity' => 0.0,
        ];
      }

      $this->wasteCodes[$wc]['count']++;
      $this->wasteCodes[$wc]['quantity'] += $quantity;
    }
  }

  protected function createAtt14($date, &$text)
  {
    $wae = new \e10pro\purchase\libs\WasteAtt14Engine($this->app());
    $wae->setPeriod($date, $date);
    $wae->loadData();

    $text .= '## Průběžná evidence odpadů - příloha 14:'."\n";

    $tr = new TableRenderer($wae->dataTable, $wae->dataHeader, ['__tableClass' => 'purchasePriceList'], $this->app());
    $text .= $tr->render();
  }

	protected function quantity ($quantity, $srcUnit, $dstUnit)
	{
    $ucc = E10Utils::unitsConversionCoefficient($this->app(), $srcUnit, $dstUnit);
		return round($quantity * $ucc, 3);
	}

  public function createLogRecords($dateBegin, $dateEnd)
  {
    $date = Utils::createDateTime($dateBegin);
    while ($date <= $dateEnd)
		{
      if ($this->app()->debug)
        echo "* ".$date->format('Y-m-d')."\n";

      $this->createLogRecordForDate($date);
			$date->add (new \DateInterval('P1D'));
		}
  }
}
