<?php

namespace integrations\hooks\in\services\wooCommerce;
use e10\utils, e10Doc\core\e10utils;


/**
 * Class WooCommerceOrder
 * @package integrations\hooks\in\services\wooCommerce
 */
class WooCommerceOrder extends \integrations\hooks\in\services\DocHookDoc
{
	var $srcData = NULL;
	var $personId = '';
	var $personNdx = 0;

	public function run()
	{
		$this->srcData = $this->hook->inPayload['data'];

		$impId = 'E-'.$this->hook->inRecData['hook'].'-'.$this->srcData['number'];

		$this->dstDoc['rec'] = [
			'impId' => $impId,
			'docType' => 'orderin',
			'dateIssue' => utils::createDateTime(substr($this->srcData['date_created'], 0, 10)),
			'dateTax' => utils::createDateTime(substr($this->srcData['date_created'], 0, 10)),
			'dateAccounting' => utils::createDateTime(substr($this->srcData['date_created'], 0, 10)),
			'dbCounter' => $this->hook->hookSettings['dbCounterOrders'],
			'paymentMethod' => 0,
			'docKind' => 0,
			'symbol1' => $this->srcData['number'],
			'centre' => $this->hook->hookSettings['centre'],
		];

		$this->dstDoc['rec']['homeCurrency'] = utils::homeCurrency ($this->app(), $this->dstDoc['rec']['dateIssue']);

		$this->checkPersons();

		$this->dstDoc['rec']['person'] = $this->personNdx;

		if ($this->srcData['status'] === 'processing')
		{
			//$this->dstDoc['rec']['docState'] = 1200;
			//$this->dstDoc['rec']['docStateMain'] = 1;
		}
		elseif ($this->srcData['status'] === 'completed')
		{
			$this->dstDoc['rec']['docState'] = 1200;
			$this->dstDoc['rec']['docStateMain'] = 1;
		}
		elseif ($this->srcData['status'] === 'cancelled')
		{
			$this->dstDoc['rec']['docState'] = 4100;
			$this->dstDoc['rec']['docStateMain'] = 2;
		}

		// -- currency
		$this->dstDoc['rec']['currency'] = strtolower($this->srcData['currency']);
		$this->dstDoc['rec']['exchangeRate'] = 1;

		if ($this->dstDoc['rec']['homeCurrency'] != $this->dstDoc['rec']['currency'])
			$this->dstDoc['rec']['exchangeRate'] = e10utils::exchangeRate($this->app(), $this->dstDoc['rec']['dateAccounting'], $this->dstDoc['rec']['homeCurrency'], $this->dstDoc['rec']['currency']);

		// -- rows
		foreach ($this->srcData['line_items'] as $r)
		{
			$row = [];
			$row['item'] = '@id:'.$r['sku'];
			$row['text'] = $r['name'];
			$row['quantity'] = $r['quantity'];
			$row['priceItem'] = $r['price'];

			$this->dstDoc['lists']['rows'][] = $row;
		}

		// -- shipping
		foreach ($this->srcData['shipping_lines'] as $r)
		{
			$row = [];
			$row['item'] = $this->hook->hookSettings['witemPostage'];
			$row['text'] = $r['method_title'];
			$row['quantity'] = 1;
			$row['priceItem'] = $r['total'];

			$this->dstDoc['lists']['rows'][] = $row;
		}

		// -- save
		$this->saveDocument();
	}

	function checkPersons()
	{
		if ($this->srcData['customer_id'] === 0)
		{
			$personId = 'X'.$this->srcData['id'];
			$e = new \integrations\hooks\in\services\wooCommerce\WooCommercePerson($this->app());
			$e->setHook($this->hook);

			$e->createPerson($this->srcData['billing'], $personId, TRUE);
			$this->personNdx = $e->saveDocument(FALSE);
		}
		else
		{
			$this->personNdx = '@id:'.'E'.$this->hook->inRecData['hook'].$this->srcData['customer_id'];
		}
	}
}

