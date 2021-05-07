<?php

namespace lib\wkf;


/**
 * Class SendBulkEmailWizard
 * @package lib\wkf
 */
class SendBulkEmailWizard extends \lib\docs\DocumentActionWizard
{
	protected function init ()
	{
		$this->actionClass = 'lib.wkf.SendBulkEmailAction';
		parent::init();
	}

	public function doAction ()
	{
		$update = ['sendingState' => 3, 'docState' => 4000, 'docStateMain' => 2];
		$this->app()->db()->query ('UPDATE [e10pro_wkf_bulkEmails] SET ', $update, ' WHERE ndx = %i', $this->recData['actionPK']);

		parent::doAction();
	}
}
