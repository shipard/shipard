<?php

namespace Shipard\Base;
use \Shipard\Utils\Utils;

/**
 * Class WebForm
 */
class WebForm
{
	public \Shipard\Application\Application $app;
	var $template = NULL;
	public $data;
	public $firstInput;
	public $formErrors = array ();
	public $recapitulation = FALSE;

	var $fw = 'bs3';
	var $webScriptId = '';
	var $webFormId = '';

	var $formInfo = [];
	var $formParams = NULL;

	protected $disableAutofocus = FALSE;

	public function __construct($app)
	{
		$this->app = $app;
		$this->firstInput = TRUE;
	}

	public function app () {return $this->app;}
	public function doIt () {return TRUE;}
	public function fields () {return array();}
	public function successMsg ()	{return $this->dictText('Hotovo.');}
	public function validate ()	{return TRUE;}
	public function createEmailRequest () {return array ();}
	public function createFormCode () {return '';}

	public function setFormParams($params)
	{
		$this->formParams = $params;
	}

	/**
	 * @param mixed $labelTxt
	 * @param mixed $type
	 * @param mixed $inputName
	 * @param mixed $options
	 * @param mixed $value
	 * @return string
	 * @deprecated
	 */
	public function addFormInput ($labelTxt, $type, $inputName, $options = NULL, $value = NULL)
	{
		$label = $this->dictText($labelTxt);

		if (isset($options['mandatory']))
			$label .= ' *';

		$inputError = '';
		$inputErrorClass = '';
		$autofocus = '';
		if ((count ($this->formErrors) == 0) && ($this->firstInput) && !$this->disableAutofocus)
			$autofocus = " autofocus='autofocus'";

		if (isset ($this->formErrors [$inputName]))
		{
			$inputError = $this->dictText($this->formErrors [$inputName]);
			$inputErrorClass = ' has-error has-feedback';
			$autofocus = " autofocus='autofocus'";
		}

		$inputClass = isset($options['inputClass']) ? ' '.$options['inputSlass'] : '';
		$inputStyle = isset($options['inputStyle']) ? ' style="'.$options['inputStyle'].'"' : '';
		$inputId = $inputName;
		if (isset($options['id']))
			$inputId = $options['id'];

		$c = '';
		if ($value !== NULL)
			$inputValue = $value;
		else
			$inputValue = Utils::es ($this->app->testPostParam ($inputName));

		if ($this->recapitulation)
		{
			$checkBoxState = intval($inputValue) ? "checked" : '';
			$c .= "<input type='hidden' id='$inputName' name='$inputName' $checkBoxState>";
			return $c;
		}

		if ($type == 'checkbox')
		{
			//$c .= "<div class='form-group'>";
			//$c .= "<div class='col-sm-offset-3 col-sm-9'>";
			$c .= "<div class='md-form'>";

			$c .= "<div class='checkbox'>";
			$c .= "<input type='checkbox' class='input-xlarge' id='$inputName' value='1' name='$inputName'/>";
			$c .= "&nbsp;<label for='$inputName'>" . Utils::es ($label) . '</label>';
			$c .= '</div>';

			$c .= '</div>';
			//$c .= '</div>';
			return $c;
		}

		if ($type == 'select')
		{
			if ($labelTxt !== '')
			{
				if (isset($options['labelAbove']))
				{
					$c .= "<div class='md-form'><label class='col-sm-12 xxx_control-label' for='$inputName'>" . Utils::es ($label) . '</label>';
					//$c .= "<div class='col-sm-12'>";
				}
				else
				{
					$c .= "<div class='md-form'><label class='col-sm-2 control-label' for='$inputName'>" . Utils::es ($label) . '</label>';
					$c .= "<div class='col-xs-5'>";
				}
			}
			$c .= "<select class='form-control{$inputClass}' name='$inputName' id='$inputId'{$inputStyle}>";
			foreach ($options ['select'] as $itemId => $item)
			{
				$selected = '';
				if (isset ($options ['selected']) && $options ['selected'] == $itemId)
					$selected = " selected='selected'";
				elseif ($inputValue == $itemId)
					$selected = " selected='selected'";
				$c .= "<option value='$itemId'$selected>" . Utils::es ($item) . "</option>";
			}
			$c .= '</select>';

			if ($inputError != '')
				$c .= "<label for='$inputName'  class='e10-form-input-error'>" . Utils::es($inputError) . '</label>';

			if ($labelTxt !== '')
			{
				if (!isset($options['labelAbove']))
					$c .= '</div>';
			}
			if ($labelTxt !== '')
				$c .= '</div>';
			return $c;
		}

		if ($type == 'memo')
		{
			if ($this->fw === 'bs4')
			{
				$c .= "<div class='md-form'>";
				if (isset($options ['icon']))
					$c .= "<i class='".$this->app->ui()->icons()->cssClass($options ['icon'])." prefix'></i>";
				$c .= "<textarea class='md-textarea' name='$inputName' id='$inputName' rows='10'></textarea>";
				if ($inputError !== '')
					$c .= "<label for='$inputName'  class='e10-form-input-error'>" . Utils::es($inputError) . '</label>';
				else
					$c .= "<label for='$inputName'>" . Utils::es($label) . '</label>';
				$c .= '</div>';
			}
			else
			{
				$c .= "<div class='form-group$inputErrorClass'><label class='col-xs-12 col-sm-3 control-label' for='$inputName'>" . Utils::es($label) . '</label>';
				$c .= "<div class='col-xs-12 col-sm-9'>";

				if ($inputError != '')
					$c .= "<span class='help-block'> <i class='fa fa-warning-sign'></i> " . Utils::es($inputError) . "</span>";

				$c .= "<textarea class='form-control' name='$inputName' id='$inputName' rows='10'>";
				$c .= '</textarea>';
				$c .= '</div>';
				$c .= '</div>';
			}
			return $c;
		}

		if ($this->fw === 'bs4')
		{
			$c .= "<div class='md-form'>";
			$c .= "<label for='$inputName'>" . Utils::es($label) . '</label>';
			if (isset($options ['icon']))
				$c .= "<i class='".$this->app->ui()->icons()->cssClass($options ['icon'])." prefix'></i>";
			$c .= "<input type='$type' class='form-control' id='$inputName'	name='$inputName' value='$inputValue'$autofocus/>";
			if ($inputError !== '')
				$c .= "<label for='$inputName'  class='e10-form-input-error'>" . Utils::es($inputError) . '</label>';
			$c .= '</div>';
		}
		else
		{
			$colWidthClass = 'col-xs-5';
			if ($options && isset ($options ['fullWidth']))
				$colWidthClass = 'col-xs-9';

			$c .= "<div class='form-group$inputErrorClass'><label class='col-sm-3 control-label' for='$inputName'>" . Utils::es($label) . '</label>';
			$c .= "<div class='$colWidthClass'>";
			$c .= "<input type='$type' class='form-control $colWidthClass' id='$inputName'	name='$inputName' value='$inputValue'$autofocus/>";
			$c .= '</div>';

			if ($inputError != '')
				$c .= "<span class='help-block'> <i class='fa fa-warning-sign'></i> " . Utils::es($inputError) . "</span>";
			else
				if ($options && isset ($options ['inline']))
					$c .= "<div class='col-xs-4'>" . $options ['inline'] . '</div>';
				else
					if ($options && isset ($options ['help']))
						$c .= "<p class='help-block'>" . $options ['help'] . '</p>';

			$c .= '</div>';
		}

		$this->firstInput = FALSE;
		return $c;
	}

	public function addInputBox ($labelTxt, $type, $inputName, $options = NULL, $value = NULL)
	{
		$c = '';

		$c .= "<div class='mb-3'>";
		$c .= $this->addInput($labelTxt, $type, $inputName, $options, $value);
		$c .= '</div>';

		return $c;
	}

	public function addInput ($labelTxt, $type, $inputName, $options = NULL, $value = NULL)
	{
		$label = $this->dictText($labelTxt);

		$c = '';
		if ($value !== NULL)
			$inputValue = $value;
		else
			$inputValue = Utils::es ($this->app->testPostParam ($inputName));

		if ($this->recapitulation)
		{
			$c .= "<input type='hidden' id='$inputName' name='$inputName' value='$inputValue'>";
			return $c;
		}

		if ($type === 'checkbox')
		{
			$c .= "<div class='form-check'>";
			$c .= "<input type='checkbox' class='form-check-input' id='$inputName' value='1' name='$inputName'/>";
			$c .= "<label class='form-check-label' for='$inputName'>" . Utils::es ($label) . '</label>';
			$c .= '</div>';
			return $c;
		}

		if ($type == 'select')
		{
			$c .= "<label class='col-sm-2 control-label' for='$inputName'>" . Utils::es ($label) . '</label>';
			$c .= "<select class='form-control' name='$inputName' id='$inputName'>";
			foreach ($options ['select'] as $itemId => $item)
			{
				$selected = '';
				if (isset ($options ['selected']) && $options ['selected'] == $itemId)
					$selected = " selected='selected'";
				$c .= "<option value='$itemId'$selected>" . Utils::es ($item) . "</option>";
			}
			$c .= '</select>';

			return $c;
		}

		if ($type == 'radio')
		{
			$c .= "<label class='col-sm-2 form-label' for='$inputName'>" . Utils::es ($label) . '</label>';
			//$c .= "<select class='form-control' name='$inputName' id='$inputName'>";
			$selected = '';
			if (!isset ($options ['selected']))
				$selected = " checked";

			foreach ($options ['select'] as $itemId => $item)
			{
				if (isset ($options ['selected']) && $options ['selected'] == $itemId)
					$selected = " checked";

					$c .= "<div class='form-check'>";
					$c .= "<input class='form-check-input' type='radio' name='$inputName' id='{$inputName}_{$itemId}' value='$itemId'{$selected}>";
					$c .= "<label class='form-check-label' for='{$inputName}_{$itemId}'>".Utils::es ($item).'</label>';
					$c .= '</div>';

					$selected = '';
				}

			return $c;
		}

		$autofocus = '';
		if ((count ($this->formErrors) == 0) && ($this->firstInput) && !$this->disableAutofocus)
			$autofocus = " autofocus='autofocus'";

		$inputError = '';
		$inputErrorClass = '';
		if (isset ($this->formErrors [$inputName]))
		{
			$inputError = $this->dictText($this->formErrors [$inputName]);
			$inputErrorClass = ' has-error has-feedback';
			$autofocus = " autofocus='autofocus'";
		}

		if ($type == 'memo')
		{
			$c .= "<div class='md-form'>";
			if (isset($options ['icon']))
				$c .= "<i class='".$this->app->ui()->icons()->cssClass($options ['icon'])." prefix'></i>";
			$c .= "<textarea class='md-textarea' name='$inputName' id='$inputName' rows='10'></textarea>";
			if ($inputError !== '')
				$c .= "<label for='$inputName'  class='e10-form-input-error'>" . Utils::es($inputError) . '</label>';
			else
				$c .= "<label for='$inputName'>" . Utils::es($label) . '</label>';
			$c .= '</div>';

			return $c;
		}

		$c .= "<label class='form-label' for='$inputName'>".Utils::es($label).'</label>';

		if (isset($options ['icon']))
			$c .= "<i class='".$this->app->ui()->icons()->cssClass($options ['icon'])." prefix'></i>";
		$c .= "<input type='$type' class='form-control' id='$inputName'	name='$inputName' value='$inputValue'$autofocus/>";
		if ($inputError !== '')
			$c .= "<div class='form-text e10-form-input-error'>" . Utils::es($inputError) . '</div>';

		$this->firstInput = FALSE;
		return $c;
	}


	function dictText ($text)
	{
		if ($this->template)
			return $this->template->dictText ($text);

		return $text;
	}

	public function getData ()
	{
		$webFormId = $this->app->testPostParam ('webFormId', NULL);
		if (!$webFormId)
			return FALSE;
		$this->data ['webFormId'] = $webFormId;

		$df = $this->fields ();
		forEach ($df as $fieldName)
		{
			$val = $this->app->testPostParam ($fieldName);
			$this->data [$fieldName] = $val;
		}

		$this->data ['regKey'] = sha1 (mt_rand() . time() . json_encode ($this->data) . mt_rand());
		$this->data ['regIP'] = $_SERVER ['REMOTE_ADDR'];
		$this->data ['regURL'] = "{$this->app->urlProtocol}{$_SERVER['HTTP_HOST']}{$this->app->urlRoot}/";
		if ($this->app->webEngine !== NULL)
			$this->data ['regSrv'] = $this->app->webEngine->serverInfo['ndx'];
		return TRUE;
	}
}

