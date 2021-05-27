<?php

namespace lib\ui;

use \E10\utils, \e10\E10Object, \E10\str, \e10\Response, \E10\DbTable, E10\Window;

/**
 * Class Form
 * @package lib\ui
 */
class Form extends E10Object
{
	CONST wtInput = 1, wtLine = 2;
	CONST itText = 1, itEnum = 2, itInt = 3, itAddCameraPicture = 4, itAddAttachments = 6, itMemo = 5;


	var $classId = '';
	var $content;
	var $currentContent;

	var $toolBar = [];

	var $postData;
	var $operation = '';

	var $response = NULL;

	public function init ()
	{
		$this->content = ['widgets' => []];
		$this->currentContent = &$this->content;
	}

	public function createContentCode ()
	{
		$c = $this->createCodeBlock ($this->content);
		return $c;
	}

	public function addWidget ($w)
	{
		$this->currentContent['widgets'][] = $w;
	}

	public function addInput ($inputType, $id, $params = NULL)
	{
		$input = ['wt' => Form::wtInput, 'it' => $inputType, 'id' => $id];
		if ($params)
			$input['options'] = $params;

		if ($params !== NULL) // TODO: deprecated?
			$input += $params;

		$this->addWidget($input);
	}

	public function addInputEnum ($id, $enum, $params = NULL)
	{
		$inputType = self::itEnum;
		$input = ['wt' => Form::wtInput, 'it' => $inputType, 'id' => $id, 'values' => $enum];
		if ($params)
			$input['options'] = $params;

		if ($params !== NULL) // TODO: deprecated?
			$input += $params;

		$this->addWidget($input);
	}

	public function addLine ($line)
	{
		$w = ['wt' => Form::wtLine, 'line' => $line];
		$this->addWidget($w);
	}

	protected function createCodeBlock ($block)
	{
		$c = '';
		foreach ($block['widgets'] as $i)
			$c .= $this->createCodeWidget($i);

		return $c;
	}

	protected function createCodeWidget ($w)
	{
		$c = '';
		$c .= $w['id']."!";

		switch ($w['wt'])
		{
			case Form::wtInput: return $this->createCodeWidgetInput ($w);
			case Form::wtLine: return $this->createCodeWidgetLine($w);
		}

		return $c;
	}

	public function createCodeWidgetInput ($w)
	{
		$ic = '';

		switch ($w['it'])
		{
			case Form::itEnum: $ic .= $this->createCodeWidgetInputEnum ($w); break;
			case Form::itMemo: $ic .= $this->createCodeWidgetInputMemo ($w); break;
			case Form::itAddCameraPicture: $ic .= $this->createCodeWidgetInputAddCameraPicture ($w); break;
			case Form::itAddAttachments: $ic .= $this->createCodeWidgetInputAddAttachments ($w); break;
			default: $ic .= $this->createCodeWidgetInputDefault ($w); break;
		}

		$lc = '';
		if (isset($w['label']) && !isset($w['options']['hidden']))
		{
			$lc .= "<label";
			$lc .= '>';
			$lc .= $this->app()->ui()->composeTextLine($w['label']);
			$lc .= '</label>';
		}

		$c = $lc.$ic;

		return $c;
	}

	public function createCodeWidgetInputDefault ($w)
	{
		$c = '';
		$c .= "<input";

		$c .= " name='".$w['id']."'";
		$c .= " id='".$w['id']."'";

		$class = '';

		$htmlInputType = 'text';
		switch ($w['it'])
		{
			case Form::itText: $class='e10-inputString'; break;
			case Form::itInt: $htmlInputType = 'number'; $class='e10-inputInt'; break;
		}

		if (isset($w['options']['fromSensor']))
		{
			$class .= ' e10-fromSensor';
			$c .= " data-srcsensor='{$w['options']['fromSensor']}'";
		}

		if (isset($w['options']['hidden']))
			$htmlInputType = 'hidden';

		$c .= " type='$htmlInputType'";

		$c .= " class='$class'";

		if (isset($w['options']['required']))
			$c .= ' required';

		if (isset($w['options']['minimal']))
			$c .= " min='{$w['options']['minimal']}'";

		$c .= '>';

		if (isset($w['options']['fromSensor']))
		{
			$c .= "&nbsp; ⇇ <button class='btn btn-info e10-trigger-action sensor'";
			$c .= " data-action='setInputValue' data-inputid='{$w['id']}' id='{$w['id']}_sensor'>---</button>";
		}

		return $c;
	}

	public function createCodeWidgetInputMemo ($w)
	{
		$c = '';
		$c .= "<textarea";
		$c .= " name='" . $w['id'] . "'";
		$c .= " id='" . $w['id'] . "'";
		$c .= '></textarea>';

		return $c;
	}

	public function createCodeWidgetInputAddCameraPicture ($w)
	{
		$c = '';

		$c .= "<div class='e10-camera-input'>";

		$pictureSrc = $this->app->testGetParam ('addPictureThumbnail');
		$c .= "<img src='$pictureSrc'>";

		$c .= "<input";
		$c .= " type='hidden'";
		$c .= " name='".$w['id']."'";
		$c .= " id='".$w['id']."'";
		$c .= '>';

		$c .= '</div>';

		return $c;
	}

	public function createCodeWidgetInputAddAttachments ($w)
	{
		$c = '';
		if ($this->app->clientType [1] !== 'cordova')
			return $c;

		$c .= "<div class='e10-attachments-input' id='I111'>";
		$c .= "<div class='e10-attachments-buttons'>";
		$c .= "<span class='e10-trigger-action' data-action='detail-add-photo' data-input='I111'><i class='fa fa-camera'></i> Přidat fotku</span>";
		//$c .= "<span class='e10-trigger-action' data-action='detail-add-file' data-input='I111'><i class='fa fa-folder-open-o'></i> Přidat soubor</span>";
		$c .= '</div>';

		$c .= "<div class='files'>";
		$c .= '</div>';

		$c .= '</div>';

		return $c;
	}

	public function createCodeWidgetInputEnum ($w)
	{
		$c = '';

		$class = 'e10-inputEnum';

		$enumStyle = isset($w['options']['enumStyle']) ? $w['options']['enumStyle'] : 'select';

		if ($enumStyle == 'select')
		{
			$c .= "<select";
			$c .= " name='" . $w['id'] . "' class='$class'";
			if (isset($w['options']['required']))
				$c .= ' required';
			$c .= '>';

			foreach ($w['values'] as $id => $value)
			{
				$c .= "<option value='$id'>" . utils::es($value) . '</option>';
			}
			$c .= '</select>';
		}
		else
		{
			$idx = 0;
			$c .= "<span class='e10-radio-input'>";
			foreach ($w['values'] as $id => $value)
			{
				$c .= "<span class='e10-radio-item'><span>";
				$c .= " <input type='radio' class='e10-inputRadio' id='{$w['id']}_{$idx}' name='{$w['id']}' value='$id'> <label for='{$w['id']}_{$idx}'>" . utils::es($value) . "</label>";
				$c .= '</span></span>';
				$idx++;
			}
			$c .= '</span>';
		}

		return $c;
	}

	public function createCodeWidgetLine ($w)
	{
		$c = $this->app()->ui()->composeTextLine($w['line']);

		return $c;
	}

	public function createForm () {}

	public function createToolbar ()
	{
		$this->toolBar['done'] = ['text' => 'Hotovo', 'icon' => 'icon-check'];
	}

	public function createToolbarCode ()
	{
		$c = '';

		$c .= "<span class='lmb e10-trigger-action' data-action='form-close'>";
		$c .= $this->app()->ui()->icon('system/actionClose');
		$c .= "</span>";

		$c .= "<div class='pageTitle'>";
		$c .= "<h1>".utils::es($this->title1())."</h1>";
		$c .= "<h2>".utils::es($this->title2())."</h2>";
		$c .= '</div>';

		$c .= "<ul class='rb'>";
		foreach ($this->toolBar as $buttonId => $button)
		{
			$class = 'e10-trigger-action';
			$params = " data-action='form-done'";
			$c .= "<li class='$class'".$params;

			$c .= '>';

			//$c .= "<button type='submit'>";
			$c .= $this->app()->ui()->icon($button['icon']).' ';
			$c .= utils::es($button['text']);

			$c .= '</li>';
		}

		$c .= '</ul>';

		return $c;
	}

	public function doResponse ()
	{
		$this->response->add ('classId', $this->classId);
		$this->response->add ('toolbarCode', $this->createToolbarCode ());
		$this->response->add ('contentCode', $this->createContentCode ());
	}

	public function doIt ()
	{

	}

	public function getPostData ()
	{
		$strData = $this->app->postData();
//error_log ("#######:".$strData);
		$this->postData = json_decode ($strData, TRUE);
	}

	public function response ()
	{
		$this->response = new \e10\Response ($this->app, '');
		$this->init();

		$this->getPostData ();
		$this->doIt();

		$this->createToolbar();
		$this->createForm();
		//error_log ("---".json_encode($this->content));
		$this->doResponse ();
		return $this->response;
	}

	public function title1 () {return '---';}

	public function title2 ()
	{
		return $this->app->cfgItem ('options.core.ownerShortName');
	}

}
