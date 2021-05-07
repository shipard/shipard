<?php

namespace lib\tools\viewer\bank;


/**
 * Class CloseConfirmedWizard
 * @package lib\tools\viewer\bank
 */
class CloseConfirmedWizard extends \lib\tools\viewer\ViewerToolsWizard
{
	protected function init ()
	{
		$this->actionClass = 'lib.tools.viewer.bank.CloseConfirmedAction';
		parent::init();
	}
}
