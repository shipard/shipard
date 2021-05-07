<?php

namespace wkf\core\documentCards;

use \e10\utils, \e10\json, wkf\core\TableIssues, \lib\persons\LinkedPersons;


/**
 * Class IssueBody
 * @package wkf\core\documentCards
 */
class IssueBody extends \wkf\core\documentCards\Issue
{
	public function createContentBody ()
	{
//		$this->createContentIssueProperties();
		$this->createContentIssueText('text');
		$this->createContentIssueText('body');
		$this->addContentAttachments ($this->recData ['ndx']);
//		$this->createContentLinkedDoc();
		$this->createSystemInfo();
	}

}
