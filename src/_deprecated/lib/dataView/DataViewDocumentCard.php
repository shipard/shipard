<?php

namespace lib\dataView;

use e10\ContentRenderer, e10\utils;


/**
 * Class DataViewDocumentCard
 * @package lib\dataView
 */
class DataViewDocumentCard extends \lib\dataView\DataView
{
	/** @var \e10\DbTable */
	var $table = NULL;
	var $recData = NULL;
	var $recNdx = 0;

	/** @var \e10\DocumentCard */
	var $documentCard = NULL;


	protected function createDocumentCard()
	{
		$tableId = $this->requestParam('table');
		if ($tableId !== '')
		{
			$this->table = $this->app()->table($tableId);
			if (!$this->table)
				$this->addMessage("Invalid param `table`: table `$tableId` not exist.");
		}
		else
		{
			$this->addMessage('Param `table` not found');
		}

		if (!$this->table)
			return;

		$this->recNdx = $this->table->primaryKey($this->requestParam('pk'));
		if (!$this->recNdx)
		{
			$this->addMessage('Param `pk` not found / is blank.');
			return;
		}
		$this->recData = $this->table->loadItem($this->recNdx);
		if (!$this->recData)
		{
			$this->addMessage("Invalid param `pk`: record `{$this->recNdx}` not exist in table `{$tableId}`.");
			return;
		}

		$this->documentCard = $this->table->documentCard ($this->recData, 0);
		if (!$this->documentCard)
		{
			$this->addMessage("Cannot create DocumentCard for table `{$tableId}`.");
			return;
		}

		$this->documentCard->setDocument ($this->table, $this->recData);
	}

	protected function renderData()
	{
		$this->data['html'] ='';

		$this->createDocumentCard();

		if (!$this->documentCard)
		{
			return;
		}

		$this->documentCard->createContent();

		$cr = new ContentRenderer($this->app);
		$cr->setDocumentCard($this->documentCard);

		$this->data['html'] .= $cr->createCode('body');
	}
}

