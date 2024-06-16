<?php
namespace e10doc\slr\libs;

/**
 * class InboxComboSlr
 */
class InboxComboSlr extends \wkf\core\viewers\InboxCombo
{
	public function setShowSections()
	{
		/** @var \wkf\core\TableIssues */
		$tableIssues = $this->app->table ('wkf.core.issues');

		$section = $tableIssues->defaultSection (52);
		$this->showSections[] = $section;
	}
}
