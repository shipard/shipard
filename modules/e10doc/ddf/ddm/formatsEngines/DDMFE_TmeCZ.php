<?php

namespace e10doc\ddf\ddm\formatsEngines;

/**
 * class DDMFE_TmeCZ
 */
class DDMFE_TmeCZ extends \e10doc\ddf\ddm\formatsEngines\CoreFE
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

      $nextRowNumber =  intval(trim(substr($this->srcTextRows[$this->rowNdx], 0, 7))); // 18     BLM18KG601SN1D

      if (!$nextRowNumber)
        $this->importRows_SearchPageBegin();
    }
  }

  protected function importRows_SearchPageBegin()
  {
    while (isset($this->srcTextRows[$this->rowNdx]))
    {
      $r = $this->srcTextRows[$this->rowNdx];
      $this->rowNdx++;

      if (!str_contains($r, 'Zboží/Popis'))
      {
        continue;
      }

      $r = $this->srcTextRows[$this->rowNdx];
      if (str_contains($r, 'Hodnota z'))
        $this->rowNdx++;

      if (str_contains($r, 'Cena objednaného zboží:'))
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
    $this->rowNdx++;

    $nextRows = [];
    $nrIdx = 0;

    while(isset($this->srcTextRows[$this->rowNdx]))
    {
      $r = $this->srcTextRows[$this->rowNdx];
      if ($r === '' || str_ends_with($r, '__'))
      {
        $this->rowNdx++;
        continue;
      }

      if (str_contains($r, 'Cena objednaného zboží:'))
      {
        $this->rowNdx = 999999;
      }
      else
      if (str_starts_with($r, '     ') && !str_contains($r, 'K převodu'))
      {
        $nrIdx = count($nextRows) - 1;

        if ($nrIdx < 0)
        {
          $nextRows[] = trim($r);
        }
        elseif (str_starts_with(trim($r), 'Výrobce:'))
        {
          $nextRows[] = trim($r);
        }
        else
          $nextRows[$nrIdx] .= ' '.trim($r);

        $this->rowNdx++;
        continue;
      }

      $this->addImportedRow($firstRow, $nextRows);

      return 1;
    }

    return 1;
  }

  protected function addImportedRow($firstRow, $nextRows)
  {
    $strippedFR = preg_replace('/ \s+/', '  ', $firstRow);
    $frParts = explode('  ', $strippedFR);

    //echo json_encode($frParts)."\n";

    //foreach ($nextRows as $nr)
    //  echo " - ".$nr."\n";

    $docRow = [];


    $docRow['rowNumber'] = $this->docRowNumber;
    $docRow['itemShortName'] = $frParts[1];

    // -- quantity
    $qp = explode(' ', $frParts[2]);
    if (count($qp) > 1)
    {
      $unitOrig = array_pop($qp);
      $docRow['unitOrig'] = $unitOrig;
      if ($unitOrig === 'KS')
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
    $vatPercentStr = str_replace(',', '.', $frParts [4]);
    $docRow['vatPercent'] = floatVal($vatPercentStr);

    // -- next row #1
    if (isset($nextRows[0]))
      $docRow['itemFullName'] = $nextRows[0];
    if (isset($nextRows[1]))
    {
      $docRow['itemProperties']['supplierItemCode'] = $docRow['itemShortName'];
      $docRow['itemProperties']['supplierItemUrl'] = 'https://www.tme.eu/cz/details/'.strtolower($docRow['itemShortName']);
      $propParts = explode(';', $nextRows[1]);
      foreach ($propParts as $oneProp)
      {
        $onePropParts = explode(':', $oneProp);
        //echo "   * ".json_encode($onePropParts)."\n";
        if (count($onePropParts) === 1)
        {
          if(trim($onePropParts[0]) === 'RoHS')
            $docRow['itemProperties']['rohs'] = 1;
        }
        else
        {
          if(trim($onePropParts[0]) === 'Výrobce')
            $docRow['itemProperties']['manufacturer'] = trim($onePropParts[1]);
          elseif(trim($onePropParts[0]) === 'Originální symbol')
            $docRow['itemProperties']['manufacturerItemCode'] = trim($onePropParts[1]);
        }
      }
    }

    // -- delivery
    if ($docRow['itemShortName'] === 'Náklady na dopravu')
    {
      $docRow['itemProperties']['supplierItemCode'] = 'transport';
    }

//    echo "###---------\n";

    $this->docRows[] = $docRow;

    $this->docRowNumber++;
  }
}
