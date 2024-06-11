<?php

namespace e10doc\slr\dc;
use \Shipard\Utils\Json;


/**
 * class DCEmpRec
 */
class DCEmpRec extends \Shipard\Base\DocumentCard
{
	protected function addRows()
	{
    $q = [];
    array_push ($q, 'SELECT [recsRows].*, ');
		array_push ($q, ' slrItems.fullName AS srlItemName');
		array_push ($q, ' FROM [e10doc_slr_empsRecsRows] AS [recsRows]');
		array_push ($q, ' LEFT JOIN [e10doc_slr_slrItems] AS slrItems ON [recsRows].slrItem = slrItems.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND empsRec = %i', $this->recData['ndx']);

    $t = [];

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'slrItem' => $r['srlItemName'],
        'amount' => $r['amount'],
      ];

      $t[] = $item;
    }

    $h = ['#' => '#', 'slrItem' => 'Položka', 'amount' => '+Částka'];

    $this->addContent('body',  [
      'pane' => 'e10-pane e10-pane-table',
      'table' => $t, 'header' => $h,
    ]);
	}

	public function createContentBody ()
	{
		$this->addRows();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
