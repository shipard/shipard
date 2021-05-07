<?php

namespace swdev\dm\dc;


/**
 * Class DMTableDoc
 * @package swdev\dm\dc
 */
class DMTableDoc extends \swdev\dm\dc\DMCoreDoc
{
	public function createContent ()
	{
		$this->wikiPageNdx = $this->recData['dmWikiPage'];
		parent::createContent ();
	}
}
