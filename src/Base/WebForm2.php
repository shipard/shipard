<?php

namespace Shipard\Base;
use \Shipard\Utils\Utils;


/**
 * Class WebForm2
 */
class WebForm2 extends \Shipard\Base\WebForm
{
	public function createFormCode ()
	{
		$useReCaptcha = ($this->template && isset($this->template->pageParams['recaptcha-v3-site-key']));

		$this->formInfo['formErrors'] = $this->formErrors;

		$this->formInfo['formErrorsAll'] = [];
		foreach ($this->formErrors as $inputId => $msg)
		{
			$this->formInfo['formErrorsAll'][] = ['inputId' => $inputId, 'msg' => $msg];
		}

		$formData = [];
		$flds = $this->fields();
		foreach ($flds as $fld)
		{
			$inputValue = $this->app->testPostParam ($fld, NULL);
			if ($inputValue !== NULL)
				$formData[$fld] = Utils::es($inputValue);
		}
		$this->formInfo['frmData'] = $formData;
		$this->formInfo['formDataJSON'] = json_encode($formData);

		$this->loadData();

		$c = '';
		if ($useReCaptcha)
		{
			$c .= "<noscript><p>";
			$c .= Utils::es('Formulář vyžaduje javascript...');
			$c .= "</p></noscript>";
		}

		$c .= "<form class='' method='POST'";
		if ($useReCaptcha)
			$c .= " style='display: none;'";

		$c .= ">";
		$c .= "<input type='hidden' name='webFormState' value='1'/>";
		$c .= "<input type='hidden' name='webFormId' value='".$this->webFormId."'/>";
		if ($useReCaptcha)
		{
			$c .= "<input type='hidden' id='recaptcha-response' name='webFormReCaptchtaResponse' value=''/>";
		}

		if ($this->webScriptId !== '')
		{
			$script = new \lib\web\WebScript($this->app());
			$script->setScriptId($this->webScriptId);
			$script->runScript($this->formInfo, FALSE);
			$c .= $script->resultCode;
		}

		$c .= '</form>';

		return $c;
	}

	protected function loadData()
  {
  }

	protected function checkFormParamsList($id, $isString = FALSE)
	{
		$list = [];
		if (!isset($this->formParams[$id]))
			return;
		if (is_array($this->formParams[$id]))
			return;

		$parts = explode (',', $this->formParams[$id]);
		foreach ($parts as $p)
		{
			if ($isString)
				$list[] = $p;
			else
			{
				$pndx = intval($p);
				if ($pndx)
					$list[] = $pndx;
			}
		}
		if (count($list))
			$this->formParams[$id] = $list;
		else
			unset($this->formParams[$id]);
	}

	public function formParam ($id, $defaultValue = '')
	{
		if (!isset($this->formParams[$id]))
			return $defaultValue;

		return $this->formParams[$id];
	}
}

