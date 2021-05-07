<?php

namespace integrations\hooks\in\services;


/**
 * Class DocHookDoc
 * @package integrations\hooks\in\services
 */
class DocHookDoc extends \integrations\hooks\in\services\DocHookCore
{
	public function setHook($hook)
	{
		parent::setHook($hook);
		$this->setTable('e10doc.core.heads');
	}
}
