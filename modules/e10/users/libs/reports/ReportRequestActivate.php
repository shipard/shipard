<?php


namespace e10\users\libs\reports;

/**
 * class ReportRequestActivate
 */
class ReportRequestActivate extends \Shipard\Report\FormReport
{
	function init ()
	{
		parent::init();
		$reportId = 'reports.modern.e10.users.activateAccount';

		$this->reportId = $reportId;
		$this->reportTemplate = $reportId;
	}

	public function loadData ()
	{
		parent::loadData();

    $this->sendReportNdx = 101;


    $sendRequestEngine = new \e10\users\libs\SendRequestEngine($this->app());
    $sendRequestEngine->setRequestNdx($this->recData['ndx']);

    $this->data['request']['url'] = $sendRequestEngine->requestUrl();
    $this->data['request']['title'] = $sendRequestEngine->uiRecData['fullName'];
    $this->data['request']['userFullName'] = $sendRequestEngine->userRecData['fullName'];
    $this->data['request']['userEmail'] = $sendRequestEngine->userRecData['email'];
    $this->data['request']['userLogin'] = $sendRequestEngine->userRecData['login'];
	}

	public function reportWasSent(\Shipard\Report\MailMessage $msg)
	{
		$update = [
			'requestState' => 2,
			'tsSent' => new \DateTime(),
		];

		$this->db()->query('UPDATE [e10_users_requests] SET ', $update, ' WHERE ndx = %i', $this->recData['ndx']);
	}
}

