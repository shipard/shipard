<?php

namespace Shipard\Report;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \Shipard\Utils\Utils;
use \Shipard\Utils\Str;


class MailMessage extends \Shipard\Base\Utility
{
	var $body = '';
	var $bodyMimeType = 'text/plain';
	var $subject = '';

	var $messageId;

	var $documentTable;
	var $documentTableId = FALSE;
	var $documentNdx = 0;
	var $documentInfo;

	var $newMsgNdx = 0;
	var $attachments = [];

	var $emailsTo;
	var $fromName;
	var $fromEmail;

	var $emailSent = FALSE;
	var $reportPrinted = FALSE;

	var $outboxLinkId = '';


	public function addAttachment ($fullFileName, $baseFileName, $mimetype)
	{
		$this->attachments[] = ['fullFileName' => $fullFileName, 'baseFileName' => $baseFileName, 'mimetype' => $mimetype];
	}

	public function addDocAttachments ($tableId, $ndx)
	{
		$attachments = \e10\base\getAttachments($this->app(), $tableId, $ndx, TRUE);
		foreach ($attachments as $a)
		{
			$fullFileName = __APP_DIR__.'/att/'.$a['path'].$a['filename'];
			$baseFileName = $a['filename'];
			$mimeType = mime_content_type($fullFileName);

			$this->addAttachment($fullFileName, $baseFileName, $mimeType);
		}
	}

	public function setBody ($text, $html = FALSE)
	{
		$this->body = $text;
		if ($html)
			$this->bodyMimeType = 'text/html';
	}

	public function setDocument ($tableId, $docNdx, $report = NULL)
	{
		$this->documentTableId = $tableId;
		$this->documentNdx = $docNdx;

		$this->documentTable = $this->app->table ($this->documentTableId);
		$item = $this->documentTable->loadItem ($this->documentNdx);

		$this->documentInfo = $this->documentTable->getRecordInfo ($item);
		if ($report)
			$report->checkDocumentInfo ($this->documentInfo);
	}

	public function setFrom ($name, $email)
	{
		$this->fromName = $name;
		$this->fromEmail = $email;
	}

	public function setTo ($emails)
	{
		$this->emailsTo = preg_split("/[\s,]+/", $emails);
	}

	public function setSubject ($subject)
	{
		$this->subject = $subject;
	}

	public function saveToOutbox ($type = 'outbox')
	{
		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app()->table('wkf.core.issues');

		$sectionNdx = 0;
		$issueKindNdx = 0;


		if (isset ($this->documentInfo['outboxSystemKind']))
			$issueKindNdx = $tableIssues->defaultSystemKind($this->documentInfo['outboxSystemKind']);

		if (isset ($this->documentInfo['outboxSystemSection']))
			$sectionNdx = $tableIssues->defaultSection($this->documentInfo['outboxSystemSection']);

		if (!$issueKindNdx)
			$issueKindNdx = $tableIssues->defaultSystemKind(4); // outbox record
		if (!$sectionNdx)
			$sectionNdx = $tableIssues->defaultSection(20); // secretariat

		$issueRecData = [
			'subject' => Str::upToLen ($this->subject, 100),
			'body' => $this->body,
			'structVersion' => $tableIssues->currentStructVersion,
			'source' => 1,
			'section' => $sectionNdx, 'issueKind' => $issueKindNdx,
			'linkId' => $this->outboxLinkId,
			'docState' => 4000, 'docStateMain' => 2,
		];

		if ($this->documentNdx)
		{
			$issueRecData ['recNdx'] = $this->documentNdx;
			$issueRecData ['tableNdx'] = $this->documentTable->ndx;
		}

		$issue = ['recData' => $issueRecData];

		if (isset($this->documentInfo['persons']['to']) && count($this->documentInfo['persons']['to']))
			$issue['persons']['wkf-issues-to'] = $this->documentInfo['persons']['to'];

		if (isset($this->documentInfo['persons']['from']))
			$issue['persons']['wkf-issues-from'] = $this->documentInfo['persons']['from'];

		foreach ($this->attachments as $att)
			$issue['attachments'][] = $att;

		if ($this->emailSent)
		{
			foreach ($this->emailsTo as $emailTo)
				$issue['systemInfo']['email']['to'][] = ['address' => $emailTo];
			$issue['systemInfo']['email']['headers'][] = ['header' => 'message-id', 'value' => $this->messageId];
		}
		if ($this->reportPrinted)
			$issue['systemInfo']['printed'] = ['status' => 1];

		$tableIssues->addIssue($issue, FALSE);
	}

	public function sendMail ()
	{
		if (!isset($this->emailsTo) || !count($this->emailsTo))
			return;

		if (!isset ($this->messageId))
			$this->messageId = time().'.'.md5 ($this->fromEmail.rand().$this->fromName).'.'.$this->fromEmail;

		$rn = "\r\n";
		$boundary = '------'.md5(rand());

		// -- headers
		$msg  = 'From: '.$this->sendMail_headerEncode($this->fromName).' <'.$this->fromEmail.'>'.$rn;
		$msg .= 'Mime-Version: 1.0'.$rn;
		$msg .= 'Content-Type: multipart/mixed;boundary="'.$boundary.'"'.$rn;
		$msg .= 'Message-Id: <'.$this->messageId.'>'.$rn;
		$msg .= 'Subject: '.$this->sendMail_headerEncode($this->subject).$rn;

		// -- addresses
		$msg .= 'To: '.implode(',', $this->emailsTo).$rn;
		$msg .= $rn;

		// -- plain text body
		$msg .= $rn . "--" . $boundary.$rn;
		$msg .= 'Content-Type: '.$this->bodyMimeType.'; charset="UTF-8"'.$rn;
		if ($this->bodyMimeType === 'text/html')
		{
			$msg .= 'Content-Transfer-Encoding: base64'.$rn;
			$msg .= 'Content-description: Mail message body'.$rn.$rn;
			$data = chunk_split(base64_encode($this->body));
			$msg .= $data.$rn;
		}
		else
		{
			$msg .= 'Content-Transfer-Encoding: 8bit'.$rn;
			$msg .= 'Content-description: Mail message body'.$rn.$rn;
			$msg .= strip_tags($this->body) . $rn;
		}

		// -- attachments
		foreach($this->attachments as $att)
		{
			$msg .= $rn . "--" . $boundary.$rn;
			$data = chunk_split(base64_encode(file_get_contents($att['fullFileName'])));
			$msg .= 'Content-Type: '.$att['mimetype'].'; name="'.$att['baseFileName'].'"'.$rn;
			$msg .= 'Content-Disposition: attachment; filename="'.$att['baseFileName'].'"'.$rn;
			$msg .= 'Content-Transfer-Encoding: base64'.$rn.$rn;
			$msg .= $data.$rn;
		}

		$msg .= $rn . '--' . $boundary . '--' . $rn;
		$ffn = __APP_DIR__.'/tmp/email-'.$this->messageId.'.eml';
		file_put_contents ($ffn, $msg);

		if ($this->app->cfgItem ('dsMode', 1) === 0)
			exec ('sendmail -t -f "'.$this->fromEmail.'" < "'.$ffn.'"');

		$this->emailSent = TRUE;
	}

	function sendMail_headerEncode ($s)
	{
		return '=?utf-8?B?'.base64_encode ($s).'?=';
	}
}

