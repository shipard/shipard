<?php

namespace lib\wkf;

use \Shipard\Base\DocumentAction;


/**
 * Class SendBulkEmailAction
 * @package lib\wkf
 */
class SendBulkEmailAction extends DocumentAction
{
	var $bulkEmailNdx;
	var $bulkEmailRecData;
	var $tableBulkEmails;
	var $tableBulkPosts;

	public function init ()
	{
		parent::init();

		$this->tableBulkEmails = $this->app->table ('e10pro.bume.bulkEmails');
		$this->tableBulkPosts = $this->app->table ('e10pro.bume.bulkPosts');
		$this->bulkEmailNdx = intval($this->params['actionPK']);
		$this->bulkEmailRecData = $this->tableBulkEmails->loadItem ($this->bulkEmailNdx);
	}

	public function actionName ()
	{
		return 'Rozeslat hromadný email';
	}

	public function sendOne ($onePost)
	{
		$msg = new \Shipard\Report\MailMessage($this->app);
		$msg->setFrom ($this->bulkEmailRecData['senderEmail'], $this->bulkEmailRecData['senderEmail']);
		$msg->setTo($onePost['email']);

		$msg->setSubject($this->bulkEmailRecData['subject']);
		$msg->setBody($this->bulkEmailRecData['text'], FALSE);
		$msg->addDocAttachments('e10pro.bume.bulkEmails', $this->bulkEmailNdx);

		$msg->sendMail();
		//$msg->saveToOutbox();

		if ($this->app()->debug)
			echo "# ".json_encode($onePost)."\n";

		$update = ['sentDate' => new \DateTime(), 'sent' => 1];
		$this->db()->query ('UPDATE [e10pro_wkf_bulkPosts] SET ', $update, ' WHERE ndx = %i', $onePost['ndx']);
	}

	public function run ()
	{
		$q[] = 'SELECT * FROM [e10pro_wkf_bulkPosts]';
		array_push($q, ' WHERE bulkMail = %i', $this->bulkEmailNdx);
		array_push($q, ' AND sent = %i', 0);
		array_push($q, ' ORDER BY ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->sendOne($r->toArray());

			sleep(1);
		}

		$update = ['sendingState' => 4, 'dateSent' => new \DateTime()];
		$this->db()->query ('UPDATE [e10pro_wkf_bulkEmails] SET ', $update, ' WHERE ndx = %i', $this->bulkEmailNdx);
	}
}
