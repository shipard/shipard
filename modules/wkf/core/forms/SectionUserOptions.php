<?php

namespace wkf\core\forms;

use \e10\utils, \E10\TableForm;


/**
 * Class SectionUserOptions
 * @package wkf\core\forms
 */
class SectionUserOptions extends \E10\Wizard
{
	/** @var \wkf\base\TableSections $tableSections */
	var $tableSections;

	var $sectionNdx = 0;
	var $sectionRecData = NULL;
	var $hdrIcon = 'icon-magic';
	var $ntfSectionType = '';

	var $thisSectionCfg = NULL;
	var $topSectionCfg = NULL;

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->doUpdate();
			$this->stepResult['lastStep'] = 1;
			$this->stepResult ['close'] = 1;
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->detectSection();

		$this->setFlag ('formStyle', 'e10-formStyleWizard');

		$this->openForm (self::ltHorizontal);
			$this->addInput('sectionNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
			$this->layoutOpen(self::ltVertical);
				$this->addInputNotifications();
				if ($this->topSectionCfg)
				{
					$this->layoutOpen(self::ltGrid);
					$this->addCheckBox('applyToAllSubSections', 'Nastavit na všech sekcích '.$this->topSectionCfg['fn'], '1', self::coColW12);
					$this->layoutClose('e10-bg-t3 pt1 pb1');
				}
			$this->layoutClose('e10-bg-t9 padd5');
		$this->closeForm ();
	}


	public function createHeader ()
	{
		$hdr = ['icon' => $this->hdrIcon];

		$hdr ['info'][] = ['class' => 'title', 'value' => $this->sectionRecData['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Nastavení sekce'];

		return $hdr;
	}

	function detectSection()
	{
		$postData = json_decode($this->app()->postData(), TRUE);
		if (!$postData)
			$postData = [];
		if(isset($postData['query-sections-subsection']))
		{
			$this->sectionNdx = intval($postData['query-sections-subsection']);
			$this->ntfSectionType = 'ss';
		}
		elseif (isset($postData['e10-widget-topTab']))
		{
			$parts = explode('-', $postData['e10-widget-topTab']);
			if (isset($parts[1]))
				$this->sectionNdx = intval($parts[1]);
			$this->ntfSectionType = 'ts';
		}
		$this->recData['sectionNdx'] = $this->sectionNdx;

		$this->tableSections = $this->app()->table('wkf.base.sections');
		$this->sectionRecData = $this->tableSections->loadItem ($this->sectionNdx);
		$this->hdrIcon = $this->tableSections->tableIcon($this->sectionRecData);

		$this->thisSectionCfg = $this->app()->cfgItem('wkf.sections.all.' . $this->sectionNdx, NULL);
		if ($this->thisSectionCfg && isset($this->thisSectionCfg['parentSection']) && $this->thisSectionCfg['parentSection'])
			$this->topSectionCfg = $this->app()->cfgItem('wkf.sections.all.' . $this->thisSectionCfg['parentSection'], NULL);
	}

	function addInputNotifications()
	{
		$marks = new \lib\docs\Marks($this->app());
		$marks->setMark(100);
		$marks->loadMarks('wkf.base.sections', [$this->sectionNdx]);

		$ntfTypeValue = isset($marks->marks[$this->sectionNdx]) ? $marks->marks[$this->sectionNdx] : 0;
		$this->recData['ntfType'] = $ntfTypeValue;

		$ntfTypes = $this->app()->cfgItem ('docMarks.100.states', []);
		foreach ($ntfTypes as $ntfTypeNdx => $ntfType)
		{
			if (isset($ntfType['st']) && !in_array($this->ntfSectionType, $ntfType['st']))
				continue;
			$enum[$ntfTypeNdx] = ['text' => $ntfType['name'], 'icon' => $ntfType['icon']];
		}
		$this->addInputEnum2('ntfType', ['text' => 'Upozorňovat na nové zprávy', 'class' => 'h2'], $enum, TableForm::INPUT_STYLE_RADIO);
	}

	function doUpdate()
	{
		$this->sectionNdx = intval($this->recData['sectionNdx']);
		$this->tableSections = $this->app()->table('wkf.base.sections');
		$this->sectionRecData = $this->tableSections->loadItem ($this->sectionNdx);

		$ntfType = $this->recData['ntfType'];

		$sndxs = NULL;
		if (isset($this->recData['applyToAllSubSections']) && intval($this->recData['applyToAllSubSections']))
		{
			$this->thisSectionCfg = $this->app()->cfgItem('wkf.sections.all.' . $this->sectionNdx, NULL);
			if ($this->thisSectionCfg && isset($this->thisSectionCfg['parentSection']) && $this->thisSectionCfg['parentSection'])
				$this->topSectionCfg = $this->app()->cfgItem('wkf.sections.all.' . $this->thisSectionCfg['parentSection'], NULL);
			if ($this->topSectionCfg)
				$sndxs = $this->topSectionCfg['subSections'];
		}

		if (!$sndxs)
			$sndxs = [$this->sectionNdx];

		foreach ($sndxs as $sectionNdx)
		{
			$existed = $this->app()->db()->query('SELECT * FROM [wkf_base_docMarks] WHERE [mark] = %i', 100,
				' AND rec = %i', $sectionNdx, ' AND [user] = %i', $this->app()->userNdx())->fetch();

			if ($existed)
			{
				$this->app()->db()->query('UPDATE [wkf_base_docMarks] SET [state] = %i', $ntfType, ' WHERE [ndx] = %i', $existed['ndx']);
			}
			else
			{
				$newItem = [
					'user' => $this->app()->userNdx(), 'table' => 1246,
					'mark' => 100, 'rec' => $sectionNdx, 'state' => $ntfType,
				];
				$this->app()->db()->query('INSERT INTO [wkf_base_docMarks] ', $newItem);
			}
		}
		return TRUE;
	}
}
