<?php

namespace e10\persons\libs\forms;
use \Shipard\Form\TableForm, \Shipard\Viewer\TableView;


/**
 * class PersonDefault
 */
class PersonDefault extends TableForm
{
	function checkLoadedList ($list)
	{
		if (($list->listId == 'groups') && (count($list->data) == 0))
		{
			if (isset ($this->recData ['maingroup']))
				$list->data [] = $this->recData ['maingroup'];

			if (isset ($this->recData ['maingroup']))
				unset ($this->recData['maingroup']);
		}
	}

	public function docLinkEnabled ($docLink)
	{
		if (isset ($docLink['systemGroup']))
		{
			if (isset($this->lists ['groups']))
				$usrgrps = explode ('.', $this->lists ['groups']);
			else
				$usrgrps = array_keys($this->app()->db()->query('SELECT [group] FROM [e10_persons_personsgroups] WHERE [person] = %i', $this->recData['ndx'])->fetchAssoc('group'));
			$userGroupNdx = -1;

			$groupsMap = $this->table->app()->cfgItem ('e10.persons.groupsToSG', FALSE);
			if ($groupsMap && isset ($groupsMap [$docLink['systemGroup']]))
				$userGroupNdx = $groupsMap [$docLink['systemGroup']];

			if (in_array ($userGroupNdx, $usrgrps))
				return TRUE;

			return FALSE;
		}

		return TRUE;
	}

	public function loadGroups ()
	{
		if (!isset($this->recData ['ndx']) || !$this->recData ['ndx'])
			return;

		$q = "SELECT * FROM [e10_persons_personsgroups] WHERE person = %i";
		$groups = $this->table->db()->fetchAll ($q, $this->recData ['ndx']);
		forEach ($groups as $g)
			$this->groups [] = $g ['group'];
	}

	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		if (!isset ($this->recData['company']))
			$this->recData['company'] = 0;

		$this->openForm ();
			$this->layoutOpen (TableForm::ltGrid);
				$this->openRow ('grid-form-tabs');
					$this->addColumnInput ("company", TableForm::coColW2);
					if ($this->recData['company'] === 0)
						$this->addColumnInput ("complicatedName", TableForm::coColW6);
				$this->closeRow ();

				$this->openRow ('grid-form-tabs');
				if ($this->recData['company'] == 0)
				{
					if ($this->recData['complicatedName'] == 0)
					{
						$this->addColumnInput ("firstName", TableForm::coColW6);
						$this->addColumnInput ("lastName", TableForm::coColW6);
					}
					else
					{
						$this->addColumnInput ("beforeName", TableForm::coColW1|TableForm::coPlaceholder);
						$this->addColumnInput ("firstName", TableForm::coColW4|TableForm::coPlaceholder);
						$this->addColumnInput ("middleName", TableForm::coColW2|TableForm::coPlaceholder);
						$this->addColumnInput ("lastName", TableForm::coColW4|TableForm::coPlaceholder);
						$this->addColumnInput ("afterName", TableForm::coColW1|TableForm::coPlaceholder);
					}
				}
				else
					$this->addColumnInput ("fullName", TableForm::coColW12);
				$this->closeRow ();
			$this->layoutClose ();

			$tabs ['tabs'][] = ['text' => 'Kontakty', 'icon' => 'formContacts'];
			//$tabs ['tabs'][] = ['text' => 'Adresy', 'icon' => 'x-tag'];
			$tabs ['tabs'][] = ['text' => 'Zatřídění', 'icon' => 'system/formSorting'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			if ($this->readOnly)
				$tabs ['tabs'][] = ['text' => 'Zápisník', 'icon' => 'system/formNotes'];
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->layoutOpen (TableForm::ltVertical);
						$this->addList ('properties', '', TableForm::loAddToFormLayout);
					$this->layoutClose();
					$this->layoutOpen (TableForm::ltHorizontal);
						$this->layoutOpen (TableForm::ltVertical);
							if (isset($this->recData['moreAddress']) && $this->recData['moreAddress'])
								$this->addViewerWidget ('e10.persons.address', 'form', ['dstTableId' => 'e10.persons.persons', 'dstRecId' => $this->recData['ndx']],TRUE);
							else
							{
								$this->layoutOpen (TableForm::ltForm);
									$this->addList('address', '', TableForm::loAddToFormLayout);
									if (!$this->readOnly)
									{
										$this->addSeparator(self::coH4);
										$this->addColumnInput('moreAddress', self::coRight);
									}
								$this->layoutClose('');
							}
						$this->layoutClose('width50 pt1');
						$this->layoutOpen (TableForm::ltVertical);
							if (isset($this->recData['ndx']) && $this->recData['ndx'])
								$this->addViewerWidget ('e10.persons.contacts', 'form', ['dstTableNdx' => $this->table->ndx, 'dstRecNdx' => $this->recData['ndx']],TRUE);
						$this->layoutClose('width50 pr1 pt1');
					$this->layoutClose();
				$this->closeTab ();

				/*
				$this->openTab (TableForm::ltNone);
					$this->addViewerWidget ('e10.persons.address', 'personsCombo', ['tableid' => 'e10.persons.persons', 'person' => $this->recData['ndx']]);
				$this->closeTab ();
				*/

				$this->openTab ();
					$this->addList ('groups', 'Skupiny');
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);

					$this->addSeparator(TableForm::coH1);
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);

					$this->layoutOpen(TableForm::ltVertical);
						$this->addList ('connections', 'Vazby k jiným osobám');
					$this->layoutClose();
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('language');
					if ($this->recData['company'] == 0)
					{
						$this->addColumnInput ("gender");
						if ($this->table->app()->hasRole ('admin'))
						{
							$this->addColumnInput ("roles");
							$this->addColumnInput ("login");
							$this->addColumnInput ("accountType");
						}
						elseif ($this->table->app()->hasRole ('admusr'))
						{
							$this->addColumnInput ('roles');
						}
					}
					$this->addColumnInput ('id');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('optSendDocsAttsUnited');
				$this->closeTab ();

				if ($this->readOnly)
				{
					$this->openTab(TableForm::ltNone);
						//$this->addViewerWidget('e10.persons.address', 'personsCombo', ['tableid' => 'e10.persons.persons', 'person' => $this->recData['ndx']]);
						$this->addDiary();
					$this->closeTab();
				}

			$this->closeTabs ();
		$this->closeForm ();
	}

	public function addDiary ()
	{
		if (!$this->table->ndx)
			return;

		$vid = 'mainListView' . mt_rand() . '_' . TableView::$vidCounter++;
		$tableId = $this->table->tableId();

		$tableNdx = $this->table->ndx;
		$recNdx = $this->recData['ndx'];

		$params = 				[
			'srcRecNdx' => $recNdx, 'srcTableNdx' => $tableNdx,
			'srcTableId' => $tableId, //'srcDocRecData' => $this->recData,
		];
		$this->addViewerWidget('wkf.core.issues', 'wkf.core.viewers.WkfDiaryViewer', $params);
	}
}
