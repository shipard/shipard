<?php

namespace e10pro\purchase\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \e10doc\core\libs\E10Utils;


/**
 * Class WasteInfoEngine
 */
class WasteInfoEngine extends Utility
{
  var $docNdx = 0;
  var $docRecData = NULL;

  var $wasteCodes = [];

  public function setDocument($docNdx)
  {
    $this->docNdx = $docNdx;
    $this->docRecData = $this->app()->loadItem($this->docNdx, 'e10doc.core.heads');
  }

  public function loadData()
  {
    $this->loadData_wasteCodes($this->docRecData);
  }

  protected function loadData_wasteCodes($docRecData)
  {
    $wasteHandlingCodes = $this->app->cfgItem('e10doc.waster.handlingCodes', []);

    $q = [];
    array_push($q, 'SELECT [wasteRows].*, nomencItems.fullName, nomencItems.itemId');
    array_push($q, ' FROM [e10pro_reports_waste_cz_returnRows] AS [wasteRows]');
    array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [wasteRows].wasteCodeNomenc = nomencItems.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [wasteRows].document = %i', $docRecData['ndx']);
    //array_push($q, ' AND [wasteRows].dir = %i', 0); // in
    array_push($q, ' AND [wasteRows].rowSource = %i', 0);
    array_push($q, ' ORDER BY [wasteRows].[ndx]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $whcCfg = $wasteHandlingCodes[$r['wasteHandlingCode']] ?? NULL;
      if (!$whcCfg)
        continue;
      //if ($whcCfg['dir'] != 0) // in
      //  continue;

      $quantity = $r['quantityKG'];
      $wc = 'W'.$r['wasteCodeText'];

      if (!isset($this->wasteCodes[$wc]))
      {
        $this->wasteCodes[$wc] = [
          'wc' => $r['itemId'],
          'fullName' => $r['fullName'],
          'count' => 0,
          'quantity' => 0.0,
          'wasteNotes' => [],
        ];
      }

      $this->wasteCodes[$wc]['count']++;
      $this->wasteCodes[$wc]['quantity'] += $quantity;
    }

    foreach ($this->wasteCodes as $wc => &$wcData)
    {
      $wcData['quantityPrint'] = Utils::nf($wcData['quantity'], 3);
    }
  }
}

