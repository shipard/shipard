<?php

namespace e10doc\contracts\sale;


/**
 * Class View
 * @package E10Doc\Contracts\Sale
 */
class View extends \E10Doc\Contracts\Core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'sale';
		parent::init();
	}
}


/**
 * Class ViewDetail
 * @package E10Doc\Contracts\Sale
 */
class ViewDetail extends \E10Doc\Contracts\Core\ViewDetailHead
{
}


/**
 * Class Form
 * @package E10Doc\Contracts\Sale
 */
class Form extends \E10Doc\Contracts\Core\FormHead
{
}


/**
 * Class FormRow
 * @package E10Doc\Contracts\Sale
 */
class FormRow extends \E10Doc\Contracts\Core\FormRow
{
}
