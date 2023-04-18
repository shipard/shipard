<?php

namespace e10doc\ddf\ddm\formatsEngines;

/**
 * class DDMFE_AlzaCZ
 */
class DDMFE_AlzaCZ extends \e10doc\ddf\ddm\formatsEngines\CoreFE
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

      /*
      $nextRowNumber =  intval(trim(substr($this->srcTextRows[$this->rowNdx], 0, 7))); // 18     BLM18KG601SN1D

      if (!$nextRowNumber)
        $this->importRows_SearchPageBegin();
      */
    }
  }

  protected function importRows_SearchPageBegin()
  {
    while (isset($this->srcTextRows[$this->rowNdx]))
    {
      $r = $this->srcTextRows[$this->rowNdx];
      $this->rowNdx++;

      if (!str_contains($r, 'Kód') || !str_contains($r, 'Popis'))
      {
        continue;
      }

      $r = $this->srcTextRows[$this->rowNdx];

      if (str_contains($r, 'Celkem:                                          '))
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

      if (str_contains($r, 'Celkem:                                          '))
      {
        $this->rowNdx = 999999;
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
    $fr = $firstRow;
    $firstSpaceParts = explode(' ', trim($firstRow));
    $fr = $firstSpaceParts[0].'  '.substr($firstRow, strlen($firstSpaceParts[0]) + 1);

    $strippedFR = preg_replace('/ \s+/', '  ', /*$firstRow*/$fr);
    $frParts = explode('  ', $strippedFR);


    foreach ($nextRows as $nr)
    {
      /*
      $strippedNR = preg_replace('/ \s+/', '  ', trim($nr));
      $nrParts = explode('  ', $strippedNR);
      echo "    ___ `$nr`: ".json_encode($nrParts)."\n";

      if (count($nrParts) === 1 && str_starts_with($nr, '    '))
        $frParts[1] .= ' '.$nrParts[0];
      else
      if (count($nrParts) === 1 && !str_starts_with($nr, '    '))
        $frParts[0] .= $nrParts[0];
      if (count($nrParts) === 2)
      {
        $frParts[0] .= $nrParts[0];
        $frParts[1] .= ' '.$nrParts[1];
      }
      */

      $strippedNR = preg_replace('/ \s+/', '  ', trim($nr));
      $nrParts = explode(' ', $strippedNR);
      //echo "    ___ `$nr`: ".json_encode($nrParts)."\n";

      if (str_starts_with($nr, '  '))
      {
        $frParts[1] .= ' '.implode(' ', $nrParts);
      }
      else
      {
        $frParts[0] .= array_shift($nrParts);
        $frParts[1] .= ' '.implode(' ', $nrParts);
      }
    }

    //echo ": ".json_encode($frParts)."\n";

    $docRow = [];

    $docRow['rowNumber'] = $this->docRowNumber;
    $docRow['itemFullName'] = preg_replace('/ \s+/', ' ', trim($frParts[1]));
    $docRow['itemShortName'] = preg_replace('/ \s+/', ' ', trim($frParts[0]));

    // -- quantity
    $quantityStr = str_replace(',', '.', $frParts[2]);
    $docRow['quantity'] = floatVal($quantityStr);

    // -- price
    $priceItemStr = str_replace(' ', '', $frParts [3]);
    $priceItemStr = str_replace(',', '.', $priceItemStr);
    //$docRow['priceItem'] = floatVal($priceItemStr);

    $priceAllStr = str_replace(' ', '', $frParts [4]);
    $priceAllStr = str_replace(',', '.', $priceAllStr);
    $docRow['priceAll'] = floatVal($priceAllStr);
    $docRow['priceSource'] = 1;

    //$docRow['priceSource'] = 1;

    // -- vatPercent
    $vatPercentStr = str_replace(',', '.', $frParts [6]);
    $docRow['vatPercent'] = floatVal($vatPercentStr);

    $docRow['itemProperties']['supplierItemCode'] = $docRow['itemShortName'];

    $this->docRows[] = $docRow;

    $this->docRowNumber++;
  }
}
