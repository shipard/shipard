<?php

namespace e10\web\webForms;
use \e10\utils;


/**
 * Class Base
 */
class Base extends \e10\E10Object
{
	/** @var \e10\web\WebPages  */
	var $webEngine;
	var $template = NULL;
	public $data;
	public $firstInput;
	public $formErrors = [];
	public $recapitulation = FALSE;

	var $fw = 'bs4';

	protected $disableAutofocus = FALSE;

	public function __construct(\e10\web\WebPages $webEngine)
	{
		parent::__construct ($webEngine->app());
		$this->webEngine = $webEngine;

		$this->firstInput = TRUE;
	}

	public function doIt () {return TRUE;}
	public function fields () {return array();}
	public function successMsg ()	{return $this->dictText('Hotovo.');}
	public function validate ()	{return TRUE;}
	public function createEmailRequest () {return array ();}
	public function createFormCode () {return '';}

	public function addFormInput ($labelTxt, $type, $inputName, $options = NULL, $value = NULL)
	{
		if ($labelTxt)
			$label = $this->dictText($labelTxt);
		else
			$label = $labelTxt;

			$c = '';
		if ($value !== NULL)
			$inputValue = $value;
		else
			$inputValue = \E10\es ($this->app->testPostParam ($inputName));

		if ($this->recapitulation)
		{
			$c .= "<input type='hidden' id='$inputName' name='$inputName' value='$inputValue'>";
			return $c;
		}

		if ($type == 'checkbox')
		{
			$c .= "<div class='form-group'>";
			$c .= "<div class='col-sm-offset-3 col-sm-9'>";

			$c .= "<div class='checkbox'>";
			$c .= "<input type='checkbox' class='input-xlarge' id='$inputName' value='1' name='$inputName'/>";
			$c .= "<label for='$inputName'>" . \E10\es ($label) . '</label>';
			$c .= '</div>';

			$c .= '</div>';
			$c .= '</div>';
			return $c;
		}

		if ($type == 'select')
		{
			$c .= "<div class='form-group'><label class='col-sm-2 control-label' for='$inputName'>" . \E10\es ($label) . '</label>';
			$c .= "<div class='col-xs-5'>";

			$c .= "<select class='form-control' name='$inputName' id='$inputName'>";
			foreach ($options ['select'] as $itemId => $item)
			{
				$selected = '';
				if (isset ($options ['selected']) && $options ['selected'] == $itemId)
					$selected = " selected='selected'";
				$c .= "<option value='$itemId'$selected>" . utils::es ($item) . "</option>";
			}
			$c .= '</select>';
			$c .= '</div>';
			$c .= '</div>';
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
			if ($this->fw === 'bs4')
			{
				$c .= "<div class='md-form'>";
				if (isset($options ['icon']))
					$c .= "<i class='".$this->app()->ui()->icons()->cssClass($options ['icon'])." prefix'></i>";
				$c .= "<textarea class='md-textarea' name='$inputName' id='$inputName' rows='10'></textarea>";
				if ($inputError !== '')
					$c .= "<label for='$inputName'  class='e10-form-input-error'>" . utils::es($inputError) . '</label>';
				else
					$c .= "<label for='$inputName'>" . utils::es($label) . '</label>';
				$c .= '</div>';
			}
			else
			{
				$c .= "<div class='form-group$inputErrorClass'><label class='col-xs-12 col-sm-3 control-label' for='$inputName'>" . \E10\es($label) . '</label>';
				$c .= "<div class='col-xs-12 col-sm-9'>";

				if ($inputError != '')
					$c .= "<span class='help-block'> <i class='fa fa-warning-sign'></i> " . \E10\es($inputError) . "</span>";

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
			if (isset($options ['icon']))
				$c .= "<i class='".$this->app()->ui()->icons()->cssClass($options ['icon'])." prefix'></i>";
			$c .= "<input type='$type' class='form-control' id='$inputName'	name='$inputName' value='$inputValue'$autofocus/>";
			if ($inputError !== '')
				$c .= "<label for='$inputName'  class='e10-form-input-error'>" . utils::es($inputError) . '</label>';
			else
			{
				if ($label !== NULL)
					$c .= "<label for='$inputName'>" . utils::es($label) . '</label>';
			}
			$c .= '</div>';
		}
		else
		{
			$colWidthClass = 'col-xs-5';
			if ($options && isset ($options ['fullWidth']))
				$colWidthClass = 'col-xs-9';

			$c .= "<div class='form-group$inputErrorClass'><label class='col-sm-3 control-label' for='$inputName'>" . \E10\es($label) . '</label>';
			$c .= "<div class='$colWidthClass'>";
			$c .= "<input type='$type' class='form-control $colWidthClass' id='$inputName'	name='$inputName' value='$inputValue'$autofocus/>";
			$c .= '</div>';

			if ($inputError != '')
				$c .= "<span class='help-block'> <i class='fa fa-warning-sign'></i> " . \E10\es($inputError) . "</span>";
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
		return TRUE;
	}

}