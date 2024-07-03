<?php

namespace e10doc\core\libs;


/**
 * class ReAccountingEngine
 */
class ReAccountingEngine extends \lib\tools\viewer\ViewerToolsAction
{
	var $docNdx = 0;

	public function init ()
	{
		$this->table = $this->app()->table('e10doc.core.heads');
		parent::init();
	}

	public function setDocument($docNdx)
	{
		$this->docNdx = $docNdx;
	}

	function doIt ()
	{
		$this->setDocState($this->docNdx, 0, 8000); // edit
		$this->setDocState($this->docNdx, 2, 4000); // done
	}

	public function run ()
	{
		$this->doIt();
	}

	protected function setDocStateFormActionBefore ($form, $docStateMain, $docState)
	{
		if ($docState === 4000)
		{
			$useDocRowsSettings = $this->app()->cfgItem ('options.experimental.testDocRowsSettings', 0);
			if ($useDocRowsSettings)
				$this->applyRowsSettings($form->recData);
		}
	}
}
