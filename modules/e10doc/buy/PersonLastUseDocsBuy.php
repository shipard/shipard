<?php


namespace e10doc\buy;

use \e10doc\core\PersonLastUseDocs;


/**
 * Class PersonLastUseDocsBuy
 * @package e10doc\buy
 */
class PersonLastUseDocsBuy extends PersonLastUseDocs
{
	protected function init()
	{
		$this->lastUseTypeId = 'e10doc-docs-buy';
		$this->docsTypes = ['invni', 'purchase'];
	}

	protected function doIt ()
	{
		$this->doItDocs(1);
	}
}
