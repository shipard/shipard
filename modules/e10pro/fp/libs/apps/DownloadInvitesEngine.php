<?php

namespace e10pro\fp\libs\apps;
use \Shipard\Utils\Utils;


/**
 * class DownloadInvitesEngine
 */
class DownloadInvitesEngine extends \Shipard\Base\Utility
{
  /** @var \e10pro\fp\TableDownloadInvites */
  var $tableDownloadInvites = NULL;

  public function init()
  {
    $this->tableDownloadInvites = $this->app()->table('e10pro.fp.downloadInvites');
  }

  public function createInvite($data)
  {
    $storageRecData = $this->db()->query('SELECT * FROM e10pro_fp_storages WHERE uid = %s', $data['storageUId'] ?? '_')->fetch();
    if (!$storageRecData)
      return;

    $inviteData = [
      'storage' => $storageRecData['ndx'],
      'filePath' => $data['activeFolder'],
      'baseFileName' => $data['fileName'],
      'email' => $data['emails'],

      'uid' => '',

      'tsValidTo' => new \DateTime('+7 days'),
      'maxDownloadCnt' => 5,

      'authorUser' => $this->app()->uiUserNdx(),
      'tsCreated' => new \DateTime(),

      'docState' => 4000, 'docStateMain' => 2,
    ];

    $this->tableDownloadInvites->dbInsertRec($inviteData);
  }

	public function sendInvite ($inviteNdx)
	{
    $tableInvites = $this->app()->table('e10pro.fp.downloadInvites');
    $inviteRecData = $tableInvites->loadItem($inviteNdx);
    if (!$inviteRecData)
      return;

    $userRecData = $this->app()->loadItem($inviteRecData['authorUser'], 'e10.users.users');
    if (!$userRecData)
      return;

		$emailsTo = $inviteRecData['email'];
    $report = new \e10pro\fp\libs\ReportDownloadInvite($tableInvites, $inviteRecData);
		$report->init();
		$report->renderReport ();
		$report->createReport ();
		$msgSubject = $report->createReportPart('emailSubject');
		$msgBody = $report->createReportPart('emailBody');

		$msg = new \Shipard\Report\MailMessage($this->app());

		$fromEmail = $userRecData['email'];
		$fromName = $userRecData['fullName'];

		$msg->setFrom ($fromName, $fromEmail);
		$msg->setTo($emailsTo);
		$msg->setSubject($msgSubject);
		$msg->setBody($msgBody);
		$msg->setDocument ('e10pro.fp.downloadInvites', $inviteNdx, $report);

		$attachmentFileName = Utils::safeChars($report->createReportPart ('fileName'));
		if ($attachmentFileName === '')
			$attachmentFileName = 'priloha';

		$report->addMessageAttachments($msg);

		$msg->sendMail();
		//$msg->saveToOutbox();

		$report->reportWasSent($msg);
	}

  public function sendAll()
  {
    $q = [];
    array_push ($q, 'SELECT * FROM [e10pro_fp_downloadInvites]');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [emailSent] = %i', 0);
    array_push ($q, ' AND [docState] = %i', 4000);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->sendInvite($r['ndx']);
    }
  }
}
