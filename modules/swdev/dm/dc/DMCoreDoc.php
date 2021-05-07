<?php

namespace swdev\dm\dc;

use e10\utils, e10\json;


/**
 * Class DMCoreDoc
 * @package swdev\dm\dc
 */
class DMCoreDoc extends \e10\DocumentCard
{
	var $wikiPageNdx = 0;
	var $wikiNdx = 6;

	public function createContent ()
	{
		if ($this->wikiPageNdx)
		{
			$url = $this->app()->urlRoot . '/app/wiki-' . $this->wikiNdx . '/' . $this->wikiPageNdx;
			$this->addContent('body', ['type' => 'url', 'url' => $url, 'fullsize' => 1]);
		}
	}
}
