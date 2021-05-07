<?php

namespace e10doc\cmnbkp;

use E10\TableForm;

/**
 * Class ShareVatReturnWizard
 * @package e10doc\cmnbkp
 */
class ShareVatReturnWizard extends \lib\docs\DocumentActionWizard
{
	public function __construct($app, $options = NULL)
	{
		parent::__construct($app, $options);
		$this->dirtyColsReferences['vatPeriod'] = 'e10doc.base.taxperiods';
	}

	protected function init ()
	{
		$this->actionClass = 'e10doc.cmnbkp.ShareVatReturn';
		parent::init();
	}

	public function renderFormWelcome ()
	{
		$this->table = $this->app->table('e10doc.base.taxperiods');

		$this->setFlag('formStyle', 'e10-formStyleSimple');
		$this->setFlag('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		parent::renderFormWelcome();
	}
}
