<?php

namespace wkf\core\forms;
use \e10\base\libs\UtilsBase;


/**
 * class SmartEdit
 */
class SmartEdit extends \E10\Wizard
{
	/** @var \wkf\core\TableIssues $tableIssues */
	var $tableIssues;

	/** @var \wkf\base\TableSections $tableSections */
	var $tableSections;

	var $srcDocNdx = 0;
	var $srcDocRecData = NULL;
	var $hdrIcon = 'icon-magic';

	var $atts = NULL;

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
		$this->detectSrcDoc();

		$this->setFlag ('formStyle', 'e10-formStyleWizard');
		$this->setFlag ('maximize', 1);

		$this->openForm (self::ltHorizontal);
			$this->addInput('srcDocNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
			$this->layoutOpen(self::ltVertical);
				$this->addInputSections();
			$this->layoutClose('width30 e10-bg-t8 padd5');

			$this->layoutOpen(self::ltVertical);
				$this->addInputLabels();
				if (isset($this->atts[$this->srcDocNdx]['images']) && count($this->atts[$this->srcDocNdx]['images']) > 1)
				{
					$this->layoutOpen(self::ltGrid);
						$this->addCheckBox('splitIssueByAttachments', 'Rozdělit na jednotlivé zprávy podle příloh', '1', self::coRightCheckbox|self::coColW12);
					$this->layoutClose();
				}
			$this->layoutClose('width30 e10-bg-t9 padd5');

			$this->layoutOpen(self::ltVertical);
				//$this->addInputLabels();
				$this->addInputMemo('addFastComment', 'Rychlý komentář');
			$this->layoutClose('width40 e10-bg-t5 padd5');
		$this->closeForm ();
	}


	public function createHeader ()
	{
		$hdr = ['icon' => $this->hdrIcon];

		$hdr ['info'][] = ['class' => 'title', 'value' => $this->srcDocRecData['subject']];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Rychlá oprava'];

		return $hdr;
	}

	function detectSrcDoc()
	{
		$this->srcDocNdx = intval($this->app->testGetParam('focusedPK'));
		$this->recData['srcDocNdx'] = strval($this->srcDocNdx);

		$this->tableIssues = $this->app()->table('wkf.core.issues');
		$this->tableSections = $this->app()->table('wkf.base.sections');
		$this->srcDocRecData = $this->tableIssues->loadItem ($this->srcDocNdx);
		$this->hdrIcon = $this->tableIssues->tableIcon($this->srcDocRecData);

		$this->atts = UtilsBase::loadAttachments ($this->app(), [$this->srcDocNdx], 'wkf.core.issues');
		//$this->recData['newSection'] = $this->srcDocRecData['section'];
	}

	function addInputSections()
	{
		$sectionsAll = $this->app()->cfgItem ('wkf.sections.all', []);
		$usersSections = $this->tableSections->usersSections();
		foreach ($usersSections['top'] as $topSectionNdx => $topSectionCfg)
		{
			$enum[$topSectionNdx] = ['text' => $topSectionCfg['fn'], 'icon' => $topSectionCfg['icon'], 'class' => 'h3 e10-bold pt1 block', 'enumLabelOnly' => 1];
			if (isset($topSectionCfg['subSections']) && count($topSectionCfg['subSections']))
			{
				foreach ($topSectionCfg['subSections'] as $ssNdx)
				{
					$s = $sectionsAll[$ssNdx];
					$enum[$ssNdx] = ['text' => $s['fn'], 'icon' => $s['icon'], 'class' => ''];
				}
			}
			else
			{
				$enum[$topSectionNdx] = ['text' => $topSectionCfg['fn'], 'icon' => $topSectionCfg['icon']];
			}
		}
		$this->addInputEnum2('newSection', ['text' => 'Přesunout do sekce', 'class' => 'h2'], $enum, self::INPUT_STYLE_RADIO);
	}

	function addInputLabels()
	{
		$clsf = \E10\Base\classificationParams ($this->tableIssues);

		$lbs = $this->app()->db()->query ('SELECT * FROM [e10_base_clsf] where [tableid] = %s', 'wkf.core.issues',
			' AND [recid] = %i', $this->srcDocNdx);
		forEach ($lbs as $r)
		{
			$iid = 'l_'.$r['group'].'_'.$r['clsfItem'];
			$this->recData[$iid] = 1;
		}

		foreach ($clsf as $cg)
		{
			$this->addStatic(['text' =>$cg['name'], 'class' => 'e10-bold block']);

			$checkBoxesCode = '';
			foreach ($cg['items'] as $labelNdx => $labelCfg)
			{
				$iid = 'l_'.$cg['id'].'_'.$labelNdx;
				$lt = ['text' => $labelCfg['title'], '_css' => $labelCfg['css'], 'class' => ''];

				$ip = $this->option ('inputPrefix', '');
				$colId = str_replace('.', '_', $this->fid . "_inp_$ip{$iid}");

				$inputCode = " <input type='checkbox' name='$ip{$iid}' id='$colId' class='e10-inputLogical' value='1' data-fid='{$this->fid}'/>";
				$labelCode = "&nbsp;<label for='$colId'>" . $this->app()->ui()->renderTextLine($lt) . "</label> ";
				$checkBoxesCode .= "<span style='line-height: 1.6; white-space: nowrap; display: inline-flex;'>".$inputCode.$labelCode.'</span> ';

				if (!isset($this->recData[$iid]))
					$this->recData[$iid] = 0;
			}

			$this->appendElement($checkBoxesCode);
			if (count($clsf))
				$this->addSeparator(self::coH4);
		}
	}

	public function createToolbar ()
	{
		$t = parent::createToolbar();
		if ($this->stepResult['lastStep'] !== 0)
			return $t;

		$states = $this->app()->cfgItem('wkf.issues.docStates.default');
		foreach ($states as $dsId => $ds)
		{
			if (!isset($ds['readOnly']) || !$ds['readOnly'])
				continue;

			$b = [
				'type' => 'action', 'action' => 'wizardnext', 'text' => $ds['actionName'], 'docState' => $dsId,
				'style' => 'stateSave', 'stateStyle' => $ds ['stateStyle'], 'close' => '1',
			];

			if (isset($ds['icon']))
				$b['icon'] = $ds['icon'];
			if (isset($ds['buttonClass']))
				$b['buttonClass'] = $ds['buttonClass'];

			$t[] = $b;
		}

		return $t;
	}

	function doUpdate()
	{
		$this->srcDocNdx = intval($this->recData['srcDocNdx']);
		$this->tableIssues = $this->app()->table('wkf.core.issues');
		$this->tableSections = $this->app()->table('wkf.base.sections');
		$this->srcDocRecData = $this->tableIssues->loadItem ($this->srcDocNdx);


		$update = [];

		if (isset($this->recData['newSection']) && intval($this->recData['newSection']) && $this->srcDocRecData['section'] != intval($this->recData['newSection']))
			$update['section'] = intval($this->recData['newSection']);

		$newDocState = intval($this->app()->testGetParam('setNewDocState'));
		if ($newDocState)
		{
			$newDocStateCfg = $this->app()->cfgItem('wkf.issues.docStates.default.'.$newDocState, NULL);
			if ($newDocStateCfg)
			{
				$update['docState'] = $newDocState;
				$update['docStateMain'] = $newDocStateCfg['mainState'];
			}
		}

		// -- issue changes
		$anyChange = 0;
		if (count($update))
		{
			$this->app()->db()->query('UPDATE [wkf_core_issues] SET ', $update, ' WHERE [ndx] = %i', $this->srcDocNdx);
			$this->srcDocRecData = $this->tableIssues->loadItem ($this->srcDocNdx);
			$this->tableIssues->checkAfterSave2($this->srcDocRecData);
			$anyChange = 1;
		}

		// -- labels / classification
		$lbs = $this->app()->db()->query ('SELECT * FROM [e10_base_clsf] where [tableid] = %s', 'wkf.core.issues', ' AND [recid] = %i', $this->srcDocNdx);
		$currentLabels = [];
		forEach ($lbs as $r)
			$currentLabels[$r['group']][$r['clsfItem']] = $r['ndx'];

		$removedLabels = [];
		foreach ($this->recData as $key => $value)
		{
			if (substr($key, 0, 2) !== 'l_')
				continue;
			$parts = explode('_', $key);
			if (count($parts) !== 3)
				continue;

			$groupId = $parts[1];
			$clsfNdx = intval($parts[2]);
			$v = intval($value);

			if ($v && (!isset($currentLabels[$groupId]) || (!isset($currentLabels[$groupId][$clsfNdx]))))
			{
				$newItem = ['tableid' => 'wkf.core.issues', 'recid' => $this->srcDocNdx, 'clsfItem' => $clsfNdx, 'group' => $groupId];
				$this->app()->db()->query ('INSERT INTO [e10_base_clsf]', $newItem);
				$anyChange = 1;
			}
			elseif (!$v && isset($currentLabels[$groupId]) && isset($currentLabels[$groupId][$clsfNdx]))
			{
				$removedLabels[] = $currentLabels[$groupId][$clsfNdx];
				$anyChange = 1;
			}
		}
		if(count($removedLabels))
			$this->app()->db()->query ('DELETE FROM [e10_base_clsf] WHERE ndx IN %in', $removedLabels);

		// -- add fast comment
		if ($this->recData['addFastComment'] !== '')
		{
			/** @var \wkf\core\TableIssues $tableComments */
			$tableComments = $this->app()->table('wkf.core.comments');

			$newComment = [
				'issue' => $this->srcDocNdx, 'commentType' => 0,
				'text' => $this->recData['addFastComment'],
				'docState' => 4000, 'docStateMain' => 2,
			];

			$tableComments->checkNewRec($newComment);
			$commentNdx = $tableComments->dbInsertRec($newComment);
			$newComment = $tableComments->loadItem($commentNdx);
			$tableComments->checkAfterSave2($newComment);
		}

		if ($anyChange)
		{
			$this->tableIssues->docsLog($this->srcDocNdx);
		}

		if (isset($this->recData['splitIssueByAttachments']) && intval($this->recData['splitIssueByAttachments']))
		{
			$sie = new \wkf\core\libs\SplitIssueByAttachmentsEngine($this->app());
			$sie->setIssueNdx($this->srcDocNdx);
			$sie->run();
		}

		return TRUE;
	}
}
