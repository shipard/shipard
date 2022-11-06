<?php
namespace wkf\core\libs;

use Shipard\Base\Utility;
use \Shipard\Report\MailMessage;
use \Shipard\Utils\Utils;


/**
 * class IssueEmailForwardEngine
 */
class IssueEmailForwardEngine extends Utility
{
  /** @var \wkf\core\TableIssues $tableIssues */
	var $tableIssues;
	var $issueNdx = 0;
	var $issueRecData;

  var ?array $documentInfo = NULL;

  var $emailsTo = '';
  var $subject = '';
  var $body = '';

  public function setIssueNdx($issueNdx)
  {
		$this->tableIssues = $this->app()->table('wkf.core.issues');
		$this->issueNdx = $issueNdx;
		$this->issueRecData = $this->tableIssues->loadItem($this->issueNdx);

    $this->documentInfo = $this->tableIssues->getRecordInfo($this->issueRecData);
    if (isset($this->documentInfo['persons']['to']))
      $this->emailsTo = $this->loadEmails($this->documentInfo['persons']['to']);


    $issueKindCfg = $this->app()->cfgItem ('wkf.issues.kinds.'.$this->issueRecData['issueKind'], NULL);

    if ($issueKindCfg && isset($issueKindCfg['emailForwardSubjectPrefix']) && $issueKindCfg['emailForwardSubjectPrefix'] !== '')
      $this->subject = trim($issueKindCfg['emailForwardSubjectPrefix']).' '.$this->issueRecData['subject'];
    else
      $this->subject = $this->issueRecData['subject'];

    $this->body = '';
    if ($issueKindCfg && isset($issueKindCfg['emailForwardBody']) && $issueKindCfg['emailForwardBody'] !== '')
      $this->body = $issueKindCfg['emailForwardBody'];
  }

	protected function loadEmails ($persons)
	{
		if (!count($persons))
			return '';

		$sql = 'SELECT valueString FROM [e10_base_properties] where [tableid] = %s AND [recid] IN %in AND [property] = %s AND [group] = %s ORDER BY ndx';
		$emailsRows = $this->app()->db()->query ($sql, 'e10.persons.persons', $persons, 'email', 'contacts')->fetchPairs ();
		return implode (', ', $emailsRows);
	}

  public function send()
  {
		$emailFromAddress = $this->app->cfgItem ('options.core.ownerEmail');
		$emailFromName = $this->app->cfgItem ('options.core.ownerFullName');

    $this->msg = new MailMessage($this->app());

    $this->msg->setFrom ($emailFromName, $emailFromAddress);
    $this->msg->setTo($this->emailsTo);
    $this->msg->setSubject($this->subject);
    $this->msg->setBody($this->body);

    if (isset($this->issueRecData['workOrder']) && $this->issueRecData['workOrder'])
      $this->msg->setDocument ('e10mnf.core.workOrders', $this->issueRecData['workOrder']);
    else
      $this->msg->setDocument ('wkf.core.issues', $this->issueRecData['ndx']);

    $this->msg->addDocAttachments($this->tableIssues->tableId(), $this->issueNdx);

    $this->msg->sendMail();
		$this->msg->saveToOutbox();

    $update = [];
    if ($this->issueRecData['docState'] !== 4000)
    {
      $update['docState'] = 4000;
      $update['docStateMain'] = 2;
    }

    if (!$this->issueRecData['tableNdx'])
    {
      $update['tableNdx'] = 1120;
      $update['recNdx'] = $this->issueRecData['workOrder'];

      if (isset($this->documentInfo['persons']['to']) && count($this->documentInfo['persons']['to']))
      {
        foreach ($this->documentInfo['persons']['to'] as $personNdx)
        {
          $newLink = [
            'linkId' => 'wkf-issues-notify',
            'srcTableId' => 'wkf.core.issues', 'srcRecId' => $this->issueNdx,
            'dstTableId' => 'e10.persons.persons', 'dstRecId' => $personNdx
          ];
          $this->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);
        }
      }
    }

    // -- update issue
    if (count($update))
    {
      $update ['dateTouch'] = Utils::now();
      $this->db()->query ('UPDATE [wkf_core_issues] SET', $update, ' WHERE ndx = %i', $this->issueNdx);

      $this->tableIssues->docsLog ($this->issueNdx);
    }
  }
}

