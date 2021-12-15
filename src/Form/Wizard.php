<?php

namespace Shipard\Form;
use \translation\dicts\e10\base\system\DictSystem;
use \Shipard\Utils\Utils;

class Wizard extends \Shipard\Form\TableForm
{
	var $stepResult;
	var $pageNumber;
	var $messagess = [];
	var $focusedPK = 0;

	public function app() {return $this->app;}

	public function __construct($app, $options = NULL)
	{
		//parent::__construct($table, $formId, $formOp)
		$this->app = $app;
		$this->pageNumber = intval($app->requestPath (3));
		parent::__construct(NULL, $app->requestPath (2), $app->requestPath (3));
		$this->stepResult = array ('close' => 0, 'lastStep' => 0, 'addDocument' => 0);

		$this->focusedPK = intval ($this->app()->testGetParam('focusedPK'));

		$postData = $this->app()->testGetData();
		$this->postData = json_decode ($postData, TRUE);
	}

	public function addMessage ($msg)
	{
		if ($msg === FALSE)
			return;
		else
		if (is_array($msg))
			$this->messagess = array_merge ($this->messagess, $msg);
		else
			$this->messagess [] = array ('text' => $msg);
	}

	public function createHeader ()
	{
		return array ();
	}

	public function createToolbar ()
	{
		if ($this->stepResult['lastStep'] === 0)
		{
			$toolbar [] = array ('type' => 'action', 'action' => 'wizardnext', 'text' => 'Pokračovat', 'style' => 'wizardNext');
			$toolbar [] = array ('type' => 'action', 'action' => 'cancelform', 'text' => DictSystem::text(DictSystem::diBtn_Close), 'style' => 'cancel');
		}
		else
		if ($this->stepResult['lastStep'] !== 999)
			$toolbar [] = array ('type' => 'action', 'action' => 'cancelform', 'text' => DictSystem::text(DictSystem::diBtn_Close), 'style' => 'cancel');

		return $toolbar;
	} // createToolbar

	public function doStep ()
	{
	}

	public function messagess ()
	{
		if (count($this->messagess) === 0)
			return FALSE;
		return $this->messagess;
	}

	public function finalCode ()
	{
		$data = $this->app()->testGetData();
		$saveData = json_decode ($data, TRUE);

		if (isset ($saveData ['recData']))
			$this->setRecData($saveData ['recData']);

		if ($this->app->requestPath (4) === 'sidebar')
		{
			$this->renderSidebar ($this->app->requestPath (5), $this->app->requestPath (6), $this->app->requestPath (7));
		}
		else
		{
			$this->doStep ();
			$this->createCode ();
		}

		return $this->html;
	}

	function openForm ($layoutType = TableForm::ltForm)
	{
		$formStyleClass = $this->flag ('formStyle', 'e10-formStyleDefault');
		$formStyleClass .= ' ' . 'test1';
		$this->stackPush ();

		$h = "<div class='df2-form e10-formControl e10-formWizard $formStyleClass' id='{$this->fid}Container'><div id='{$this->fid}' data-object='wizard'";
		$h .= " data-formid='{$this->formId}'";
		$h .= " data-wizardpage='{$this->formOp}'";
		$h .= '>';

		$this->appendCode ($h);
		$this->layoutOpen ($layoutType);
	}

	public function renderFormDone ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->openForm ();

		if (count($this->messagess))
		{
			$c = "<ul 'e10-addwiz-msgs'>";
			forEach ($this->messagess as $m)
			{
				$c .= "<li>" . Utils::es ($m['text']) . '</li>';
			}

			$c .= '</ul>';
			$this->appendCode ($c);
		}

		$this->closeForm ();
	}
}

