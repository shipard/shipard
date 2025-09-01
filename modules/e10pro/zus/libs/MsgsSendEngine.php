<?php

namespace e10pro\zus\libs;
use \Shipard\Base\Utility;


/**
 * class MsgsSendEngine
 */
class MsgsSendEngine extends Utility
{
	var $msgNdx = 0;
  var $maxCount = 50;

  public function setMsg($msNdx)
  {
    $this->msgNdx = $msNdx;
  }

	public function sendOne ($onePost)
	{
		$msg = new \Shipard\Report\MailMessage($this->app);
		$msg->setFrom ($onePost['authorEmail'], $onePost['authorName']);
		$msg->setTo($onePost['contactEmail']);

		$msg->setSubject($onePost['title']);
		$msg->setBody($onePost['text'], FALSE);
		$msg->addDocAttachments('e10pro.zus.msgs', $this->msgNdx);

		//$msg->sendMail();
		//$msg->saveToOutbox();

		if ($this->app()->debug)
			echo "# ".json_encode($onePost)."\n";

		$update = ['sentDate' => new \DateTime(), 'sent' => 1];
		$this->db()->query ('UPDATE [e10pro_zus_msgsRecipients] SET ', $update, ' WHERE ndx = %i', $onePost['ndx']);
	}

	public function run ()
	{
		$q = [];
		array_push($q, 'SELECT recps.*,');
    array_push($q, ' contacts.contactEmail AS contactEmail, contacts.contactName AS contactName,');
    array_push($q, ' authors.fullName AS authorName, authors.login AS authorEmail,');
    array_push($q, ' msgs.title, msgs.text');
		array_push($q, ' FROM [e10pro_zus_msgsRecipients] AS recps');
    array_push($q, ' LEFT JOIN [e10pro_zus_msgs] AS msgs ON recps.msg = msgs.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS authors ON msgs.author = authors.ndx');
    array_push($q, ' LEFT JOIN [e10_persons_personsContacts] AS contacts ON recps.personContact = contacts.ndx');
		array_push($q, ' WHERE 1');
    if ($this->msgNdx)
		  array_push($q, ' AND msg = %i', $this->msgNdx);
		array_push($q, ' AND sent = %i', 0);
    array_push($q, ' AND msgs.docState = %i', 4000);
		array_push($q, ' ORDER BY ndx');
    array_push($q, ' LIMIT %i', $this->maxCount);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->sendOne($r->toArray());
			sleep(1);
		}
	}
}
