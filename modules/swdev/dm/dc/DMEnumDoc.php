<?php

namespace swdev\dm\dc;


/**
 * Class DMEnumDoc
 * @package swdev\dm\dc
 */
class DMEnumDoc extends \swdev\dm\dc\DMCoreDoc
{
	public function createContent ()
	{
		$this->wikiPageNdx = $this->recData['dmWikiPage'];
		parent::createContent ();
	}
}
