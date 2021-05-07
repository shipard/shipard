<?php

namespace lib\tools\viewer\bank;


/**
 * Class ConfirmNewWizard
 * @package lib\tools\viewer\bank
 */
class ConfirmNewWizard extends \lib\tools\viewer\ViewerToolsWizard
{
	protected function init ()
	{
		$this->actionClass = 'lib.tools.viewer.bank.ConfirmNewAction';
		parent::init();
	}
}
