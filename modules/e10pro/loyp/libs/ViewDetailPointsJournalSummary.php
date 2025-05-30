<?php

namespace e10pro\loyp\libs;

use \Shipard\Viewer\TableViewDetail;


/**
 * class ViewDetailPointsJournalSummary
 */
class ViewDetailPointsJournalSummary extends TableViewDetail
{
  public function createDetailContent ()
	{
		$parts = explode('_', $this->item['pk']);
		$personNdx = intval($parts[0]);
		$loypNdx = intval($parts[1]);

		$rd = ['ndx' => $personNdx, 'loyp' => $loypNdx];
		$this->addDocumentCard('e10pro.loyp.libs.dc.DCPersonPointsSummary', $rd);
	}
}
