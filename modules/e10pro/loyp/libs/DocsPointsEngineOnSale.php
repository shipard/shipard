<?php

namespace e10pro\loyp\libs;
use \Shipard\Utils\Utils;


/**
 * class DocsPointsEngineOnSale
 */
class DocsPointsEngineOnSale extends \Shipard\Base\Utility
{
  var $documentNdx = 0;
  var $documentRecData = 0;
  var $doSave = 0;

  protected function createPoints()
  {
    $totalPts = $this->documentRecData['lpPriceAll'];

    if ($this->doSave)
    {
      $this->db()->query('DELETE FROM [e10pro_loyp_pointsJournal] WHERE [document] = %i', $this->documentNdx);

      // -- add to journal
      if ($totalPts != 0)
      {
        $journalItem = [
          'rowType' => 2,

          'loyp' => $this->documentRecData['loyp'],
          'document' => $this->documentNdx,
          'person' => $this->documentRecData['person'],
          'cntPoints' => - $totalPts,
        ];

        $this->db()->query('INSERT INTO [e10pro_loyp_pointsJournal] ', $journalItem);
      }
    }
  }

  public function doDocument(&$docRecData, $doSave = 0)
  {
    $this->doSave = $doSave;
    $this->documentNdx = $docRecData['ndx'];
    $this->documentRecData = $docRecData;

    $this->createPoints();
  }
}
