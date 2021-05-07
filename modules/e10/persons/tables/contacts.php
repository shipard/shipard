<?php

namespace e10\persons;
use e10\DbTable, e10\TableForm, e10\str;


/**
 * Class TableContacts
 * @package e10\persons
 */
class TableContacts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.contacts', 'e10_persons_contacts', 'Kontakty');
	}

	public function TMP__columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$recData = $this->loadItem($pk);
		if (!$recData)
			return '';

		$refTitle = [];
		if ($recData['street'] !== '')
			$refTitle[] = ['text' => $recData['street']];
		if ($recData['city'] !== '')
			$refTitle[] = ['text' => $recData['city']];
		if ($recData['zipcode'] !== '')
			$refTitle[] = ['text' => $recData['zipcode']];

		return $refTitle;
	}

	function loadContacts($table, $ndx, $inlineMode = FALSE)
	{
		$multipleRecs = is_array($ndx);

		$q[] = 'SELECT * FROM [e10_persons_address]';
		array_push($q, ' WHERE [tableid] = %s', $table->tableId ());

		if ($multipleRecs)
			array_push($q, ' AND recid IN %in', $ndx);
		else
			array_push($q, ' AND recid = %i', $ndx);

		array_push($q, ' ORDER BY ndx');

		$list = [];
		$addrTypes = $this->app()->cfgItem('e10.persons.addressTypes');

		$rows = $this->app()->db()->query ($q);
		forEach ($rows as $r)
		{
			$a = [];

			$txt = '';
			if ($r['street'] !== '')
				$txt .= $r['street'];
			if ($r['city'] !== '')
			{
				if ($txt !== '')
					$txt .= ', ';
				$txt .= $r['city'];
			}
			if ($r['zipcode'] !== '')
			{
				if ($txt !== '')
					$txt .= ', ';
				$txt .= $r['zipcode'];
			}

			if ($inlineMode)
				$a = ['text' => $txt, 'class' => 'block', 'icon' => 'icon-map-marker'];
			else
			{
				$a = $r->toArray();
				$a['text'] = $txt;
			}

			if ($multipleRecs)
				$list[$r['recid']][] = $a;
			else
				$list[] = $a;
		}

		return $list;
	}

	public function contactText($r, $inlineMode = FALSE)
	{
		$txt = '';
		if ($r['street'] !== '')
			$txt .= $r['street'];
		if ($r['city'] !== '')
		{
			if ($txt !== '')
				$txt .= ', ';
			$txt .= $r['city'];
		}
		if ($r['zipcode'] !== '')
		{
			if ($txt !== '')
				$txt .= ', ';
			$txt .= $r['zipcode'];
		}

		if ($inlineMode)
		{
			$a = ['text' => $txt, 'class' => 'block', 'icon' => 'icon-map-marker'];
			return $a;
		}

		return $txt;
	}
}


/**
 * Class FormContact
 * @package e10\persons
 */
class FormContact extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ('name');
			$this->addColumnInput ('role');
			$this->addColumnInput ('email');
			$this->addColumnInput ('phone');
		$this->closeForm ();
	}
}
