<?php

namespace e10pro\bume;

use \E10\TableView, \E10\TableViewDetail, \Shipard\Form\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableBulkEmails
 * @package e10pro\wkf
 */
class TableBulkEmails extends DbTable
{
	CONST besConcept = 0, besCreatingRecipients = 1, besReadyToSend = 2, besSending = 3, besSent = 4;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.bume.bulkEmails', 'e10pro_wkf_bulkEmails', 'Hromadná pošta');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['subject']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset ($recData ['dateCreate']) || self::dateIsBlank ($recData ['dateCreate']))
			$recData ['dateCreate'] = new \DateTime();

		if (!isset($recData ['author']) || !$recData ['author'])
			$recData ['author'] = $this->app()->userNdx();

		if ($recData['docState'] === 8000)
		{
			$recData['sendingState'] = 0;
			$recData['dateReadyToSend'] = NULL;
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData ['author']) || !$recData ['author'])
			$recData ['author'] = $this->app()->userNdx();

		if (!isset($recData ['dateCreate']))
			$recData ['dateCreate'] = new \DateTime ();
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		if ($recData['docState'] === 1200)
		{
			$this->createRecipients($recData);
		}
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId === 'senderEmail')
		{
			if (!$form)
				return [];

			$enum = [];

			// -- author
			if (isset($form->recData['author']))
			{
				$q[] = 'SELECT recid, valueString FROM [e10_base_properties]';
				array_push($q, ' WHERE 1');
				array_push($q, ' AND [tableid] = %s', 'e10.persons.persons', ' AND [recid] = %i', $form->recData['author']);
				array_push($q, ' AND [group] = %s', 'contacts', ' AND property = %s', 'email');

				$rows = $this->db()->query($q);
				foreach ($rows as $r)
				{
					$email = $r['valueString'];
					$enum[$email] = $email;
				}
			}

			// -- owner
			$ownerEmail = $this->app()->cfgItem ('options.core.ownerEmail', '');
			if ($ownerEmail !== '')
				$enum[$ownerEmail] = $ownerEmail;

			// -- other emails
			$otherEmails = $this->app()->cfgItem('e10pro.bume.sendersEmails', NULL);
			if ($otherEmails && count($otherEmails))
			{
				foreach($otherEmails as $e)
				{
					$enum[$e] = $e;
				}
			}

			return $enum;
		}

		return parent::columnInfoEnum ($columnId, $valueType, $form);
	}

	function createRecipients ($recData)
	{
		$tableBulkPosts = $this->app()->table ('e10pro.bume.bulkPosts');

		// -- delete old
		$this->db()->query ('DELETE FROM [e10pro_wkf_bulkPosts] WHERE [sent] = 0 AND [bulkMail] = %i', $recData['ndx']);

		// -- add new
		$q[] = 'SELECT * FROM [e10pro_wkf_bulkRecipients]';
		array_push ($q, ' WHERE [bulkMail] = %i', $recData['ndx']);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$virtualGroup = $this->app()->cfgItem ('e10.persons.virtualGroups.'.$r['virtualGroup'], NULL);
			if (!$virtualGroup)
				continue;

			$vgObject = $this->app()->createObject($virtualGroup['classId']);
			if (!$vgObject)
				continue;

			$vgObject->addPosts($tableBulkPosts, 'bulkMail', $recData['ndx'], $r);

			unset ($vgObject);
		}

		$dateReadyToSend = new \DateTime('+ 15 minutes');
		$update = ['dateReadyToSend' => $dateReadyToSend, 'sendingState' => 2];

		$this->db()->query ('UPDATE [e10pro_wkf_bulkEmails] SET ', $update, ' WHERE [ndx] = %i', $recData['ndx']);
		$recData['sendingState'] = 2;
		$recData['dateReadyToSend'] = $dateReadyToSend;
	}

	public function recipientsLabels($recData, $class = 'label label-default', &$labels, $rows = NULL)
	{
		$virtualGroups = $this->app()->cfgItem ('e10.persons.virtualGroups', []);
		$tableBulkRecipients = $this->app()->table ('e10pro.bume.bulkRecipients');

		if (!$rows)
		{
			$q = [];
			array_push($q, 'SELECT * FROM [e10pro_wkf_bulkRecipients]');
			array_push($q, ' WHERE [bulkMail] = %i', $recData['ndx']);

			$rows = $this->db()->query($q);
		}
		foreach ($rows as $r)
		{
			$enumId = '';
			foreach ($virtualGroups[$r['virtualGroup']]['queryColumns'] as $qcId => $qcLabel)
				$enumId .= $r[$qcId] . '_';

			if (!isset($virtualGroups[$r['virtualGroup']]['enums'][$enumId]))
			{
				foreach ($virtualGroups[$r['virtualGroup']]['queryColumns'] as $qcId => $qcLabel)
				{
					$virtualGroups[$r['virtualGroup']]['enums'][$enumId][$qcId] = $tableBulkRecipients->virtualGroupEnumItems($qcId, $r);
				}
			}

			$itemName = '';
			foreach ($virtualGroups[$r['virtualGroup']]['queryColumns'] as $qcId => $qcLabel)
			{
				if (!$r[$qcId])
					continue;
				if ($itemName !== '')
					$itemName .= '/';

				$itemName .= $virtualGroups[$r['virtualGroup']]['enums'][$enumId][$qcId][$r[$qcId]];
			}

			if (!isset($labels[$r['virtualGroup']]))
			{
				$vg = $virtualGroups[$r['virtualGroup']];
				$labels[$r['virtualGroup']] = ['text' => $vg['name'] . ': ' . $itemName, 'class' => $class, 'icon' => 'system/iconPaperPlane', 'css' => 'white-space: pre-line;'];
			}
			else
				$labels[$r['virtualGroup']]['text'] .= ', '.$itemName;
		}
	}
}


/**
 * Class ViewBulkEmails
 * @package e10pro\wkf
 */
class ViewBulkEmails extends TableView
{
	var $recipients = [];
	var $virtualGroups;
	var $tableBulkRecipients;

	public function init ()
	{
		parent::init();

		$this->virtualGroups = $this->app()->cfgItem ('e10.persons.virtualGroups', []);
		$this->tableBulkRecipients = $this->app()->table ('e10pro.bume.bulkRecipients');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['subject'];
		$listItem ['t2'] = [['text' => $item['authorName'], 'icon' => 'system/iconUser']];
		$listItem ['t2'][] = ['text' => utils::datef($item['dateCreate'], '%D, %T'), 'icon' => 'system/iconCalendar'];

		//$listItem ['i2'] = strval($item['sendingState']).' ';

		if ($item['sendingState'] === 4 && $item['dateSent'])
			$listItem ['i2'] = ['text' => utils::datef ($item['dateSent'], '%D, %T'), 'icon' => 'system/iconPaperPlane'];


//		$listItem ['i2'] = ['text' => utils::datef ($item['dateReadyToSend'], '%D, %T'), 'icon' => 'system/iconPaperPlane'];

		/*
				$props = [];

				if ($item['dateSend'])
					$props [] = ['icon' => 'system/iconPaperPlane', 'text' => utils::datef ($item['dateSend'], '%D, %T')];

				if (count($props))
					$listItem ['i2'] = $props;
		*/
		return $listItem;
	}

	function decorateRow (&$item)
	{
		parent::decorateRow ($item);

		$labels = [];

		if (isset($this->recipients[$item['pk']]))
		{
			$this->table->recipientsLabels([], 'label label-default', $labels, $this->recipients[$item['pk']]);
		}

		$item ['t3'] = $labels;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT mails.*, authors.fullName AS authorName FROM [e10pro_wkf_bulkEmails] AS mails ';
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS authors ON mails.author = authors.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([subject] LIKE %s OR [text] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, 'mails.', ['dateCreate DESC', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$q = [];
		array_push ($q, 'SELECT * FROM [e10pro_wkf_bulkRecipients]');
		array_push ($q, ' WHERE [bulkMail] IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->recipients[$r['bulkMail']][] = $r->toArray();
		}
	}
}


/**
 * Class ViewDetailBulkEmail
 * @package e10pro\wkf
 */
class ViewDetailBulkEmail extends TableViewDetail
{
	public function createDetailContentState ($recData, &$info)
	{
		//$info = [];
		switch ($recData['sendingState'])
		{
			case  TableBulkEmails::besConcept:
							break;
			case  TableBulkEmails::besCreatingRecipients:
							$info [] = ['text' => 'Vytváří se seznam příjemců...'];
							break;
			case TableBulkEmails::besReadyToSend:
							$info[] = [
								'text' => 'Rozeslat ihned', 'class' => 'pull-right', 'btnClass' => 'btn-primary btn-xs', 'icon' => 'system/iconPaperPlane',
								'data-table' => 'e10pro.bume.bulkEmails', 'data-pk'=>$recData['ndx'],
								'type' => 'action', 'action' => 'wizard', 'data-class' => 'lib.wkf.SendBulkEmailWizard',
								'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => 'default'
							];
							$info [] = ['text' => 'Připraveno k odeslání', 'class' => 'h2 block'];
							$info [] = ['text' => 'Zpráva bude během několika minut rozeslána...', 'class' => 'block'];
							break;
			case TableBulkEmails::besSending:
							$info [] = ['text' => 'Zpráva se rozesílá', 'class' => 'h2 block'];
							break;
			case TableBulkEmails::besSent:
							$info [] = ['text' => 'Rozesláno', 'class' => 'h2 block'];
							break;
		}

		$this->countRecipients($info);

		if (count($info))
			$info [] = ['code' => '<br>', 'class' => 'block padd5'];
	}

	public function createDetailContent ()
	{
		$info = [];

		$this->createDetailContentState($this->item, $info);

		$this->table->recipientsLabels($this->item, 'block', $info);
		$this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => $info]);

		$this->addContent(['type' => 'text', 'subtype' => 'plain', 'text' => $this->item ['text']]);
		$this->addContentAttachments ($this->item ['ndx']);
	}

	function countRecipients(&$info)
	{
		$q[] = 'SELECT [sent], COUNT(*) AS cnt FROM [e10pro_wkf_bulkPosts] ';
		array_push ($q, ' WHERE bulkMail = %i', $this->item['ndx']);
		array_push ($q, ' GROUP BY sent');

		$rows = $this->db()->query ($q);
		$cr = 0;
		foreach ($rows as $r)
		{
			$cnt = $r['cnt'];
			if ($r['sent'])
				$info[] = ['text' => 'Odesláno emailů: '.utils::nf($cnt), 'class' => ''];
			else
				$info[] = ['text' => 'Zbývá odeslat emailů: '.utils::nf($cnt), 'class' => ''];

			$cr++;
		}

		if ($cr)
			$info[] = ['text' => ' ', 'class' => 'block'];
	}
}


/**
 * Class ViewDetailBulkEmailPosts
 * @package e10pro\wkf
 */
class ViewDetailBulkEmailPosts extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent (
			[
				'type' => 'viewer', 'table' => 'e10pro.bume.bulkPosts', 'viewer' => 'e10pro.bume.ViewBulkPosts',
				'params' => ['bulkMail' => $this->item ['ndx']]
			]);
	}
}


/**
 * Class FormBulkEmail
 * @package e10pro\wkf
 */
class FormBulkEmail extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			//$this->layoutOpen (TableForm::ltGrid);
			//	$this->openRow ('grid-form-tabs');
					$this->addColumnInput ('subject');
					$this->addColumnInput ('senderEmail');
			//	$this->closeRow ();
			$this->layoutClose ();

			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Příjemci', 'icon' => 'formRecipients'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
				$this->closeTab ();

				$this->openTab ();
					$this->addList ('rows');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
