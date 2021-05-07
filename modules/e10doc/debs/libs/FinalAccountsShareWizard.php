<?php

namespace pkgs\accounting\debs;


/**
 * Class FinalAccountsShareWizard
 * @package pkgs\accounting\debs
 */
class FinalAccountsShareWizard extends \lib\docs\DocumentActionWizard
{
	protected function init ()
	{
		$this->actionClass = 'pkgs.accounting.debs.FinalAccountsShareEngine';
		parent::init();
	}
}
