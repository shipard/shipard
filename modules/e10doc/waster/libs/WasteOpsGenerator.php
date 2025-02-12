<?php
namespace e10doc\waster\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * class WasteOpsGenerator
 */
class WasteOpsGenerator extends Utility
{
  var $year = 0;

  /** @var \e10doc\waster\TableWasteOps */
  var $tableWasteOps;

  protected function resetYear()
  {
    foreach (range(1, 12) as $month)
    {
      $periodBegin = $this->year.'-'.$month.'-01';
      $periodEnd = $this->year.'-'.$month.'-'.date('t', strtotime($periodBegin));

      $this->resetPeriod($periodBegin, $periodEnd);
    }
  }

  protected function resetPeriod($periodBegin, $periodEnd)
  {
    echo "###### ".Utils::datef($periodBegin).' - '.Utils::datef($periodEnd)."\n";

    $q = [];
    array_push ($q, 'SELECT [rows].wasteCodeNomenc, [rows].wasteCodeText, [rows].wasteHandlingCode,');
    array_push($q, ' [rows].wasteCodeNomencMove, [rows].wasteCodeTextMove,');
    array_push($q, ' SUM([rows].quantityKG) as quantityKG');
    array_push($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [rows].wasteCodeNomencMove != %i', 0);
    array_push($q, ' AND [rows].[dateAccounting] >= %d', $periodBegin);
    array_push($q, ' AND [rows].[dateAccounting] <= %d', $periodEnd);
    array_push($q, ' GROUP BY [rows].wasteCodeNomenc, [rows].wasteCodeText, [rows].wasteHandlingCode, [rows].wasteCodeNomencMove, [rows].wasteCodeTextMove');

    $rows = $this->db()->query($q);
    foreach($rows as $r)
    {
     // $this->checkWasteOp($r);
      echo "# ".$r['wasteCodeText'].' --> '.$r['wasteCodeTextMove'].' - '.$r['quantityKG']."\n";

      $hcSrc = 'BR12';
      $hcDst = 'A00';

      $newOp = [
        'generated' => 1,
        'opType' => 2, // MOVE
        'quantity' => $r['quantityKG'],
        'unit' => 'kg',
        'date' => $periodEnd,

        'wasteCodeTextSrc' => $r['wasteCodeText'],
        'wasteCodeNomencSrc' => $r['wasteCodeNomenc'],

        'wasteCodeTextDst' => $r['wasteCodeTextMove'],
        'wasteCodeNomencDst' => $r['wasteCodeNomencMove'],

        'wasteHandlingCodeSrc' => $hcSrc,
        'wasteHandlingCodeDst' => $hcDst,

        'docState' => 4000, 'docStateMain' => 2,
      ];

      $newNdx = $this->tableWasteOps->dbInsertRec($newOp);
      $recData = $this->tableWasteOps->loadItem($newNdx);
      $this->tableWasteOps->checkAfterSave2 ($recData);
    }
  }

  public function run()
  {
    $this->tableWasteOps = $this->app()->table('e10doc.waster.wasteOps');
    $this->resetYear();
  }
}