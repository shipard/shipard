<?php


namespace e10doc\debs;

use \e10doc\debs\SumTableJournalDebs, \e10\utils;


/**
 * Class SumTableJournalDebsBSInt
 * @package e10doc\debs
 */
class SumTableJournalDebsBSInt extends SumTableJournalDebs
{
	public function init()
	{
		parent::init();

		$this->objectClassId = 'e10doc.debs.SumTableJournalDebsBSInt';

		$this->accountKinds = [2, 3, 7, 8];
	}

	function loadData_Rows()
	{
		parent::loadData_Rows();

		if ($this->level !== 0)
			return;

		$total = 0.0;
		if (isset($this->dataSumsByAccKinds[3]))
			$total += $this->dataSumsByAccKinds[3]['sumAmount'];
		if (isset($this->dataSumsByAccKinds[8]))
			$total += $this->dataSumsByAccKinds[8]['sumAmount'];
		if (isset($this->dataSumsByAccKinds[2]))
			$total -= $this->dataSumsByAccKinds[2]['sumAmount'];
		if (isset($this->dataSumsByAccKinds[7]))
			$total -= $this->dataSumsByAccKinds[7]['sumAmount'];

		$item = [
			'id' => ['text' => 'Hospodářský výsledek', 'icontxt' => ' ∑ '],
			'sumAmount' => $total,
			'_options' => ['class' => 'sumtotal', 'colSpan' => ['id' => 2]],
		];

		if ($total > 0.0)
			$item['_options']['class'] .= ' e10-row-plus';
		elseif ($total < 0.0)
			$item['_options']['class'] .= ' e10-row-minus';

		$this->data[] = $item;
	}
}