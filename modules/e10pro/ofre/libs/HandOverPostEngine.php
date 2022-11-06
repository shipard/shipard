<?php

namespace e10pro\ofre\libs;

use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * class HandOverPostEngine
 */
class HandOverPostEngine extends Utility
{
  /** @var \wkf\core\TableIssues $tableIssues */
	var $tableIssues;

	var $workOrderNdx = 0;
	var $workOrderRecData;


  public function setWorkOrderNdx($workOrderNdx)
  {
		$this->tableIssues = $this->app()->table('wkf.core.issues');
		$this->workOrderNdx = $workOrderNdx;
		$this->workOrderRecData = $this->app()->loadItem($this->workOrderNdx, 'e10mnf.core.workOrders');
  }

  public function run()
  {
		$q = [];
    array_push ($q, 'SELECT issues.*');
    array_push ($q, ' FROM [wkf_core_issues] AS issues');
    array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' AND [workOrder] = %i', $this->workOrderNdx);
		array_push ($q, ' ORDER BY [dateCreate] DESC, [ndx] DESC');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $issueState = ['docState' => 9000, 'docStateMain' => 5, 'dateTouch' => Utils::now()];
      $this->db()->query ('UPDATE [wkf_core_issues] SET', $issueState, ' WHERE ndx = %i', $r['ndx']);

      $this->tableIssues->docsLog ($r['ndx']);
    }
  }
}
