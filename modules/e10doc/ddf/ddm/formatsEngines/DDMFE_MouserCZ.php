<?php

namespace e10doc\ddf\ddm\formatsEngines;

/**
 * class DDMFE_MouserCZ
 */
class DDMFE_MouserCZ extends \e10doc\ddf\ddm\formatsEngines\CoreFE
{
  var $rowNdx = 0;

  var $docRowNumber = 1;

  public function import(&$coreHeadData)
  {
    $coreHeadData['head']['vat'] = 1;
    $coreHeadData['head']['taxType'] = 1;
    $coreHeadData['head']['documentId'] = $coreHeadData['head']['symbol1'];
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

      if (!str_contains($r, 'Line No.') && !str_contains($r, 'Popis'))
      {
        continue;
      }

      $r = $this->srcTextRows[$this->rowNdx];

      if (str_contains($r, 'Zboží') && str_contains($r, 'Manipulace'))
      {
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

      if (trim($r) === '')
      {
        $this->rowNdx++;
        continue;
      }

      if (str_contains($r, 'Zboží') && str_contains($r, 'Manipulace'))
      {
        $this->rowNdx++;
      }
      else
      if (str_starts_with($r, '          '))
      {
        $nrIdx = count($nextRows) - 1;

        if ($nrIdx < 1)
        {
          $nextRows[] = trim($r);
        }
        elseif (str_starts_with(trim($r), 'Číslo dílu výrobce:') || str_starts_with(trim($r), 'TARIC:'))
        {
          $nextRows[] = trim($r);
        }
        else
          $nextRows[0] .= ' '.trim($r);

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

    //echo "frParts: ".json_encode($frParts)."\n";

    //foreach ($nextRows as $nr)
    //  echo " - ".$nr."\n";
    //echo "\n";

    $docRow = [];


    $docRow['rowNumber'] = $this->docRowNumber;
    $docRow['itemShortName'] = $frParts[2];

    // -- quantity
    $docRow['unit'] = 'pcs';
    $quantityStr = str_replace(' ', '', $frParts [4]);
    $quantityStr = str_replace(',', '.', $quantityStr);
    $docRow['quantity'] = floatVal($quantityStr);

    // -- price
    $priceStr = str_replace(' ', '', $frParts [7]);
    $priceStr = str_replace(',', '.', $priceStr);
    $docRow['priceAll'] = floatVal($priceStr);
    $docRow['priceSource'] = 1;

    // -- vatPercent
    $docRow['vatPercent'] = 0;

    // -- next row #1
    if (isset($nextRows[0]))
      $docRow['itemFullName'] = $nextRows[1];
    if (isset($nextRows[1]))
    {
      $docRow['itemProperties']['supplierItemCode'] = $docRow['itemShortName'];
      $docRow['itemProperties']['supplierItemUrl'] = 'https://cz.mouser.com/c/?q='.$docRow['itemShortName'];
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
      }
    }

    //echo json_encode($docRow)."\n";
    //echo "###---------\n";

    $this->docRows[] = $docRow;

    $this->docRowNumber++;
  }
}
