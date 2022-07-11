<?php

namespace e10doc\stockOut\libs;
use \Shipard\Utils\Utils;


class StockOutReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10doc.stockOut.stockOut');
	}

	public function loadData ()
	{
		parent::loadData();

		// rows
		$q[] = 'SELECT [rows].*, items.fullName as itemFullName, items.id as itemID,';
		array_push ($q, ' journal.price as invPriceAll, (journal.price / [rows].quantity) as invPriceItem');
		array_push ($q, ' FROM [e10doc_core_rows] as [rows] LEFT JOIN e10_witems_items as items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10doc_inventory_journal as journal ON (journal.docHead = [rows].document AND journal.docRow = [rows].ndx)');
		array_push ($q, ' WHERE [document] = %i ORDER BY [rows].ndx', $this->recData ['ndx']);
		$rows = $this->table->db()->query($q);

		$invPriceTotal = 0.0;
		$tableDocRows = new \e10doc\core\TableRows ($this->app);
		forEach ($rows as $row)
		{
			$r = $row->toArray();
			$r ['print'] = $this->getPrintValues ($tableDocRows, $r);
			$r ['print']['invPriceAll'] = Utils::nf ($r['invPriceAll'], 2);
			$r ['print']['invPriceItem'] = Utils::nf ($r['invPriceItem'], 2);
			$this->data ['invrows'][] = $r;
			$invPriceTotal += $r['invPriceAll'];
		}

		$this->data ['invPriceTotal'] = $invPriceTotal;
		$this->data ['print']['invPriceTotal'] = Utils::nf ($invPriceTotal, 2);
	}
}
