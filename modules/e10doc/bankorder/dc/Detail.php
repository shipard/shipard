<?php

namespace e10doc\bankorder\dc;
use \e10\utils, e10pro\wkf\TableMessages, wkf\core\TableIssues;


/**
 * Class Detail
 * @package e10doc\bankorder\dc
 */
class Detail extends \e10doc\core\dc\Detail
{
	public function addRows ($operation, $title)
	{
		$item = $this->recData;

		$q[] = 'SELECT [rows].*, persons.fullName as personName';
		array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON [rows].person = persons.ndx');
		array_push($q, ' WHERE [rows].document = %i', $item ['ndx']);
		array_push($q, ' AND [rows].operation = %i', $operation);
		array_push($q, ' ORDER BY [rows].rowOrder, [rows].ndx');

		$rows = $this->table->db()->query($q);

		$list = array ();
		$totalPriceAll = 0.0;
		forEach ($rows as $r)
		{
			$newItem = [
				'text' => $r['text'],
				'symbol1' => $r['symbol1'], 'symbol2' => $r['symbol2'], 'symbol3' => $r['symbol3'],
				'priceItem' => $r['priceItem']
			];
			$newItem ['bankAccount'][] = ['text' => $r['bankAccount'], 'class' => 'block'];
			$newItem ['bankAccount'][] = ['text' => $r['personName'], 'class' => 'e10-off'];
			if ($r['text'] !== '')
				$newItem ['bankAccount'][] = ['text' => $r['text'], 'class' => 'e10-small block'];
			$list[] = $newItem;
			$totalPriceAll += $r['priceItem'];
		}

		if (count ($list))
		{
			$h = [
				'#' => '#', 'bankAccount' => 'Číslo účtu',
				'symbol1' => ' VS', 'symbol2' => ' SS', 'symbol3' => ' KS',
				'priceItem' => ' Částka'
			];
			if (count ($list) > 1)
			{
				$list[] = array ('text' => 'Celkem', 'priceItem' => $totalPriceAll, '_options' => ['class' => 'sum']);
			}

			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'x-properties', 'text' => $title], 'header' => $h, 'table' => $list]);
		}
	}

	public function createContentBody ()
	{
		$this->linkedDocuments();
		$this->addRows(1030101, "Příkaz k úhradě");
		$this->addRows(1030102, "Příkaz k inkasu");
		$this->attachments();
	}
}
