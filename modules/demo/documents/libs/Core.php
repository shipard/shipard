<?php

namespace demo\documents\libs;


require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\str;


/**
 * Class Document
 */
class Core extends \demo\documents\libs\Document
{
	public function init ($taskDef, $taskTypeDef)
	{
		parent::init($taskDef, $taskTypeDef);

		$this->tableId = 'e10doc.core.heads';
		$this->data['rows'] = [];

		$this->defaultValues['docState'] = 4000;
		$this->defaultValues['docStateMain'] = 2;

		if (isset($taskDef['title']))
			$this->data['rec']['title'] = $taskDef['title'];
	}

	protected function addRow ($row)
	{
		$this->data['rows'][] = $row;
	}

	function addRows ()
	{
		$title = ''; // max 120 chars

		// -- random rows
		$cntRows = $this->cntMinMax ('cntRows');
		if ($cntRows)
		{
			$q[] = 'SELECT * FROM [e10_witems_items] WHERE 1';
			$this->applyTaskQuery('item', $q);

			array_push($q, ' ORDER BY RAND()');
			array_push($q, ' LIMIT ' . $cntRows);

			$items = $this->db()->query($q);
			foreach ($items as $r)
			{
				$quantity = $this->cntMinMax ('itemQuantity');
				if (!$quantity)
					$quantity = mt_rand(1, 5);

				$row = [
					'item' => $r['ndx'], 'text' => $r['fullName'],
					'quantity' => $quantity, 'unit' => $r['defaultUnit'],
					'priceItem' => $r['priceSell']
				];

				if ($row['priceItem'] < 1.0)
				{
					$row['priceItem'] = $this->cntMinMax ('itemPriceSell');
					if ($row['priceItem'] < 1.0)
						$row['priceItem'] = mt_rand(60, 1450) * 1.0;
				}

				$this->addRow($row);

				if (str::strlen($title) + str::strlen($row['text']) < 118)
				{
					if ($title !== '')
						$title .= ', ';
					$title .= $row['text'];
				}
			}
		}

		// -- fixed rows
		if (isset($this->taskDef['rows']))
		{
			foreach ($this->taskDef['rows'] as $r)
			{
				$qItem [] = 'SELECT * FROM [e10_witems_items] WHERE 1';
				$this->applyTaskQuery('item', $qItem, $r);
				array_push($qItem, ' ORDER BY RAND()');
				array_push($qItem, ' LIMIT 1');
				$item = $this->db()->query ($qItem)->fetch();
				if (!$item)
					continue;

				$row = [
					'item' => $item['ndx'], 'text' => $item['fullName'],
					'quantity' => mt_rand(1, 10), 'unit' => $item['defaultUnit'],
					'priceItem' => $item['priceSell']
				];

				if (isset($r['priceItem']))
					$row['priceItem'] = $r['priceItem'];
				if (isset($r['quantity']))
					$row['quantity'] = $r['quantity'];

				if ($row['priceItem'] < 1.0)
					$row['priceItem'] = mt_rand(60, 1450) * 1.0;

				$this->addRow($row);

				if (str::strlen($title) + str::strlen($row['text']) < 118)
				{
					if ($title !== '')
						$title .= ', ';
					$title .= $row['text'];
				}
			}
		}

		if (!isset($this->data['rec']['title']) || $this->data['rec']['title'] === '')
			$this->data['rec']['title'] = $title;
	}

	protected function setPerson ()
	{
		$q[] = 'SELECT * FROM [e10_persons_persons] WHERE 1';

		$this->applyTaskQuery ('person', $q);

		array_push ($q, ' ORDER BY RAND()');
		array_push ($q, ' LIMIT 100');

		$person = $this->db()->query($q)->fetch();
		//echo \dibi::$sql . "\n";
		//echo "PERSON: ".json_encode($person)."\n";

		if ($person)
			$this->data['rec']['person'] = $person['ndx'];
	}

	protected function setAuthor()
	{
		$q[] = 'SELECT * FROM [e10_persons_persons] WHERE 1';
		array_push ($q, ' AND roles != %s', '', ' AND [personType] = %i', 1);
		$this->applyTaskQuery ('author', $q);

		array_push ($q, ' ORDER BY RAND()');
		array_push ($q, ' LIMIT 1');

		$person = $this->db()->query($q)->fetch();
		if ($person)
			$this->data['rec']['author'] = $person['ndx'];
	}

	public function create()
	{
		$this->setAuthor();
		$this->setPerson();
	}

	public function run()
	{
		$this->db()->begin();
		$this->create();
		$this->save();
		$this->db()->commit();

		return TRUE;
	}

}
