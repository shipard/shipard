<?php

namespace e10pro\loyp\libs;
use \Shipard\Utils\Utils;


/**
 * class DocsPointsRecalc
 */
class DocsPointsRecalc extends \Shipard\Base\Utility
{
  var $dateFrom = NULL;
  var $dateTo = NULL;
  var $docTypes = ['purchase'];

  protected function recalcAll()
  {
    $q = [];
    array_push($q, 'SELECT [docs].*');
    array_push($q, ' FROM [e10doc_core_heads] AS [docs]');
    array_push($q, ' WHERE 1');
		array_push($q, ' AND [docState] = %i', 4000);
		array_push($q, ' AND [dateAccounting] >= %d', $this->dateFrom,
			' AND [dateAccounting] <= %d', $this->dateTo);

		if ($this->docTypes)
			array_push($q, ' AND [docType] IN %in', $this->docTypes);

    array_push($q, ' ORDER BY dateAccounting, activateTimeLast, ndx');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->recalcDocument($r);
    }
  }

  protected function recalcDocument($recData)
  {
    //echo "* ".$recData['docNumber']."\n";

    $dpe = new \e10pro\loyp\libs\DocsPointsEngine($this->app);
		$dpe->doDocument($recData, 1);
  }

  public function run()
  {
    $this->dateFrom = Utils::createDateTime('2024-01-01');
    $this->dateTo = Utils::createDateTime('2024-12-31');

    //$now = new \DateTime();
    //echo "START: ".$now->format('H:i:s')."\n";

    $this->db()->begin();
    $this->recalcAll();
    $this->db()->commit();

    //$end = new \DateTime();
    //echo "DONE: ".$end->format('H:i:s')."\n";
  }
}
