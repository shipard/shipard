<?php

namespace e10pro\loyp\libs;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class ViewDetailPointsJournalSummary
 */
class ViewDetailPointsJournalSummary extends TableViewDetail
{
  public function createDetailContent ()
	{
		$rd = ['ndx' => $this->ndx];
		$this->addDocumentCard('e10pro.loyp.libs.dc.DCPersonPointsSummary', $rd);
	}
}
