<?php

namespace wkf\core\viewers;


use \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class ViewDetailIssue
 * @package wkf\core\viewers
 */
class ViewDetailIssue extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('wkf.core.documentCards.Issue');
	}
}
