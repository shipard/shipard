<?php
namespace helpdesk\core\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Utils;
use \lib\persons\LinkedPersons, \Shipard\Utils\Str;


/**
 * class AddTicketNotification
 */
class AddTicketNotification extends Utility
{
	var $docRecData = NULL;
	var $docCommentRecData = NULL;
	var $reason = 0;

	/** @var \Shipard\Table\DbTable */
	var $srcTable;

	/** @var LinkedPersons */
	var $linkedPersons = NULL;

	function init ()
	{
	}

	public function setDocument($srcTable, $docRecData, $reason = 0, $docCommentRecData = NULL)
	{
		$this->init();

		$this->srcTable = $srcTable;
		$this->docRecData = $docRecData;
		$this->docCommentRecData = $docCommentRecData;
		$this->reason = $reason;
	}

	public function createUsersNotifications()
	{
		$docStates = $this->srcTable->documentStates ($this->docRecData);

		$notify = 1;
		$ntfType = 99;

		$author = $this->docRecData['author'];
		$subject = '';
		$recId = $this->docRecData['ndx'];
		$recIdMain = $this->docRecData['ndx'];

		if ($this->reason === 0)
		{ // mani doc
			if ($this->docRecData ['docStateMain'] === 0 && $this->docRecData ['docState'] === 8000)
				$notify = 0; // edits is not notified
			//elseif ($this->docRecData['activateCnt'] > 1 && $this->docRecData ['docStateMain'] === 1)
			//	$notify = 0; // only first 'publish' is notified

			$ntfType = $this->docRecData ['docStateMain'];
		}
		elseif ($this->reason === 1)
		{ // comment
			$author = $this->docCommentRecData['author'];
			$recId = $this->docCommentRecData['ndx'];
			$recIdMain = $this->docRecData['ndx'];
			$subject = $this->docRecData['subject'];
			$ntfType = 92; // comment
		}

		if (!$notify)
			return;

		if ($subject === '')
		{
			if ($this->docRecData['subject'] !== '')
				$subject = $this->docRecData['subject'];
			else
				$subject = $this->srcTable->getDocumentStateInfo($docStates, $this->docRecData, 'logName');
		}

		$n = [
			'tableId' => $this->srcTable->tableId(), 'recId' => $recId, 'recIdMain' => $recIdMain,
			'objectType' => 'document',
			'personSrc' => $author, 'subject' => str::upToLen($subject, 80),
			'icon' => $this->srcTable->tableIcon($this->docRecData), 'ntfType' => $ntfType, 'created' => new \DateTime()
		];

		$notifyTypeText = $this->srcTable->getDocumentStateInfo ($docStates, $this->docRecData, 'logName');
		if ($notifyTypeText !== FALSE)
			$n['ntfTypeName'] = $notifyTypeText;

		$this->addUsersNotifications ($n);
	}

	public function addUsersNotifications ($notification)
	{
		if (Utils::$todayClass)
			return;

		$persons = [];
		$this->loadPersonsToNotify($persons);

		// -- set old notifications for this message as read
		$this->db()->query ('UPDATE [e10_base_notifications] SET [state] = 1 WHERE [tableId] = %s', $this->srcTable->tableId(),
												' AND [recId] = %i', $this->docRecData['ndx']);

		// -- add new
		$thisUserNdx = $this->app()->userNdx();
		foreach ($persons as $dstUserNdx => $notificationState)
		{
			if ($dstUserNdx === $thisUserNdx)
				continue;
			if (!$notificationState)
				continue;
			$notification['personDest'] = $dstUserNdx;
			$this->db()->query ('INSERT INTO [e10_base_notifications]', $notification);
		}
	}

	function loadPersonsToNotify(&$persons)
	{
		$this->loadPersonsToNotify_thisDoc ($persons);
		$this->loadPersonsToNotify_Section($this->docRecData['helpdeskSection'],$persons);
	}

	public function loadPersonsToNotify_thisDoc (&$persons)
	{
		$persons[$this->docRecData['author']] = 1;

		$linkedPersons = new LinkedPersons($this->app());
		$linkedPersons->setSource($this->srcTable->tableId(), $this->docRecData['ndx']);
		$linkedPersons->setFlags(LinkedPersons::lpfNicknames);
		$linkedPersons->load();
		foreach ($linkedPersons->personsNdxs as $pndx)
		{
			$persons[$pndx] = 1;
		}
	}

	public function loadPersonsToNotify_Section ($sectionNdx, &$persons)
	{
		$linkedPersons = new LinkedPersons($this->app());
		$linkedPersons->setSource('helpdesk.core.sections', $sectionNdx);
		$linkedPersons->setFlags(LinkedPersons::lpfNicknames);
		$linkedPersons->load();
		foreach ($linkedPersons->personsNdxs as $pndx)
		{
			$persons[$pndx] = 1;
		}
	}

	public function run()
	{
		if ($this->docRecData['docState'] == 1000 || $this->docRecData['docState'] == 4000)
			return; // concept / edit

		$this->createUsersNotifications();
	}
}
