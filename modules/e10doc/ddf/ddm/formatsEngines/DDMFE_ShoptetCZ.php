<?php

namespace e10doc\ddf\ddm\formatsEngines;

/**
 * class DDMFE_ShoptetCZ
 */
class DDMFE_ShoptetCZ extends \e10doc\ddf\ddm\formatsEngines\CoreFE
{
  var $rowNdx = 0;

  var $docRowNumber = 1;

  public function import(&$coreHeadData)
  {
    $coreHeadData['head']['vat'] = 1;
    $this->importRows();
  }

  protected function importRows()
  {
    $this->rowNdx = 0;

    $this->importRows_OnePage();
  }

  protected function importRows_OnePage()
  {
    $this->importRows_SearchPageBegin();

    while (1)
    {
      $res = $this->importRows_ImportRow();
      if (!isset($this->srcTextRows[$this->rowNdx]))
        break;
    }
  }

  protected function importRows_SearchPageBegin()
  {
    while (isset($this->srcTextRows[$this->rowNdx]))
    {
      $r = $this->srcTextRows[$this->rowNdx];
      $this->rowNdx++;

      if (!str_contains($r, 'Položky objednávky:'))
      {
        continue;
      }

      $r = $this->srcTextRows[$this->rowNdx];
      if (str_contains($r, 'Cena za m.'))
        $this->rowNdx++;
      if (str_contains($r, 'Položky objednávky:'))
        $this->rowNdx++;

      $r = $this->srcTextRows[$this->rowNdx];

      if (str_contains($r, 'Shrnutí                                             '))
      {
        $this->rowNdx = 999999;
        return;
      }

      break;
    }
  }

  protected function importRows_ImportRow()
  {
    if (!isset($this->srcTextRows[$this->rowNdx]))
      return 0;

    $firstRow = $this->srcTextRows[$this->rowNdx];
    //echo ' * '.$firstRow."\n";
    $this->rowNdx++;

    $nextRows = [];

    while(isset($this->srcTextRows[$this->rowNdx]))
    {
      $r = $this->srcTextRows[$this->rowNdx];

      if (str_contains($r, 'Shrnutí                                             '))
      {
        $this->rowNdx = 999999;
        return;
      }
      else
      {
        $strippedFR = preg_replace('/ \s+/', '  ', trim($r));
        $frParts = explode('  ', $strippedFR);
        //echo "    -> ".json_encode($frParts)."\n";
        if (count($frParts) < 4)
        {
          $nextRows[] = $r;
          $this->rowNdx++;

          if (!str_starts_with($r, 'Kód:'))
            continue;
        }
      }

      //foreach ($nextRows as $nr)
      //  echo " - ".$nr."\n";

      $this->addImportedRow($firstRow, $nextRows);

      //echo "-------------\n";

      return 1;
    }

    return 1;
  }

  protected function addImportedRow($firstRow, $nextRows)
  {
    $strippedFR = preg_replace('/ \s+/', '  ', $firstRow);
    $frParts = explode('  ', $strippedFR);

    if (count($frParts) === 7)
    {
      $sp = explode(' ', $frParts[0]);
      $spUnit = array_pop($sp);
      $spQuantity = array_pop($sp);

      $newFRParts = [];
      $newFRParts[] = implode(' ', $sp);
      $newFRParts[] = $spQuantity.' '.$spUnit;
      array_shift($frParts);

      $frParts = array_merge($newFRParts, $frParts);
    }

    //foreach ($nextRows as $nr)
    //  echo " - ".$nr."\n";

    $docRow = [];


    $docRow['rowNumber'] = $this->docRowNumber;
    $docRow['itemFullName'] = trim($frParts[0]);

    // -- quantity
    $qp = explode(' ', $frParts[1]);
    if (count($qp) > 1)
    {
      $unitOrig = array_pop($qp);
      $docRow['unitOrig'] = $unitOrig;
      if ($unitOrig === 'ks')
        $docRow['unit'] = 'pcs';
      $quantityStr = str_replace(',', '.', implode('', $qp));
      $docRow['quantity'] = floatVal($quantityStr);
    }

    // -- price
    $priceStr = str_replace(' ', '', $frParts [5]);
    $priceStr = str_replace(',', '.', $priceStr);
    $docRow['priceAll'] = floatVal($priceStr);
    $docRow['priceSource'] = 1;

    // -- vatPercent
    $vatPercentStr = str_replace(',', '.', $frParts [6]);
    $docRow['vatPercent'] = floatVal($vatPercentStr);

    foreach ($nextRows as $nr)
    {
      //echo "--->".$nr."\n";
      if (str_starts_with(trim($nr), 'Kód:'))
      {
        $docRow['itemProperties']['supplierItemCode'] = trim(substr(trim($nr), 5));
      }
      elseif (str_starts_with($nr, 'Recyklační příspěvek:'))
      {
      }
      else
        $docRow['itemFullName'] .= ' '.$nr;
    }

    //echo "____".json_encode($docRow)."\n";

//    echo "###---------\n";

    $this->docRows[] = $docRow;

    $this->docRowNumber++;
  }
}
