<?php

namespace Shipard\UI\ng\renderers;
use \Shipard\UI\ng\renderers\Renderer;
use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;
use \Shipard\UI\Core\ContentRenderer;
use \Shipard\Application\DataModel;
use \Shipard\Form\TableForm;


/**
 * class TableFormRenderer
 */
class TableFormRenderer extends Renderer
{
  var ?\Shipard\Form\TableForm $form = NULL;

  public function setForm (\Shipard\Form\TableForm $form)
  {
    $this->form = $form;
    //$this->viewer->ngRenderer = $this;
  }

  public function render()
  {
    $c = '';

		$this->form->renderForm();

    $this->renderedData['hcHeader'] = $this->createHeaderCode();
    //$this->renderedData['hcToolbar'] = $this->createToolbarCode();

		$this->renderedData['hcContent'] = '';
    $this->createContentCode();

    $hcFull = '';
    $hcFull .= "<div class='formContainer'>";
      $hcFull .= "<div class='formHeader'>".$this->renderedData['hcHeader']."</div>";
      //$hcFull .= "<div class='formToolbar'>".$this->renderedData['hcToolbar']."</div>";
      $hcFull .= "<div class='formTabs'></div>";
      $hcFull .= "<div class='formContent'>".$this->renderedData['hcContent']."</div>";
    $hcFull .= "</div>";

    $this->renderedData['hcFull'] = $hcFull;
  }

	public function createContentCode()
	{
		$this->renderedData['hcContent'] = '';
		$this->renderedData['hcContent'] .= "<div class='container-fluid'>";

		foreach ($this->form->formContent as $e)
		{
			if ($e['type'] === TableForm::etColumnInput)
				$this->addColumnInput ($e);
		}

		$this->renderedData['hcContent'] .= "</div>"; // class='container-fluid'>";
	}

	public function createToolbarCode ()
	{
		$c = '';
		$btnsCode = [0 => '', 1 => ''];
		$tlbr = $this->form->createToolbar ();
		$stateBtnIdx = 0;
		foreach ($tlbr as $btn)
		{
			if ($btn['style'] === 'cancel' || $btn['style'] === 'defaultSave')
				continue;

			$side = isset ($btn['side']) ? $btn['side'] : 0; //left; 1 -> right
			$class = '';
			$icon = '';
			$params = '';
			$btnid = '';

			if ($btn['style'] === 'unlock')
			{
				$icon = '<i class="fa fa-lock fa-2x"></i> ';
				$t1 = utils::es ($this->form->lockState['mainTitle']);
				$t2 = utils::es ($this->form->lockState['subTitle']);
				$btnsCode [$side] .= "<span style='padding-left: 1em; vertical-align: middle;display: inline-block;'>$icon<h4 style='display: inline-block; position: relative; padding-left: 1ex;'>$t1<br/><small>$t2</small></h4></span>";
				continue;
			}

			switch ($btn['style'])
			{
				case 'defaultSave':
										$class = ' btn-primary e10-savebtn';
										$icon = '<i class="fa fa-download"></i> ';
										$btnid = " id='{$this->form->fid}Save'";
										if (isset ($btn['noclose']))
											$params .= " data-noclose='1'";
										$params .= " data-save-style='default'";
										break;
				case 'stateSave':
										if (isset($btn['docState']) && $btn['docState'] < 10000)
										{
											if ($stateBtnIdx === 0)
												$params .= " data-save-style='primary'";
											$stateBtnIdx++;
										}
										switch ($btn['stateStyle'])
										{
											case 'archive':
															$class = ' btn-default';
															$icon = $this->app()->ui()->icon('system/docStateArchive');
															$side = 1;
															break;
											case 'confirmed':
															$class = ' btn-info';
															if (!isset ($btn['readOnly']) && (!isset($btn['close']) || !$btn['close']))
																$params .= " data-noclose='1'";
															$icon = $this->app()->ui()->icon('system/docStateConfirmed');
															break;
											case 'edit':
											case 'concept':
															$class = ' btn-warning';
															$params .= " data-noclose='1'";
															$icon = $this->app()->ui()->icon('system/docStateEdit');
															$side = 1;
															break;
											case 'delete':
															$class = ' btn-danger';
															$icon = $this->app()->ui()->icon('system/docStateDelete');
															$side = 1;
															break;
											case 'cancel':
															$class = ' btn-danger';
															$icon = $this->app()->ui()->icon('system/docStateCancel');
															$side = 1;
															break;
											default:
															$class = ' btn-success';
															$icon = $this->app()->ui()->icon('system/docStateDone');
															break;
										}
										if (isset($btn['buttonClass']))
											$class = ' '.$btn['buttonClass'];
										if (isset ($btn['style']))
											$params .= " data-action-param-set-doc-state='{$btn['docState']}'";
										break;
				case 'cancel':
										$class = ' btn-default';
										$icon = $this->app()->ui()->icon('system/actionClose');
										break;
				case 'wizardNext':
										$class = ' btn-success';
										$icon = '<i class="fa fa-play"></i> ';
										break;
				case 'wizardDone':
										$class = ' btn-success';
										$icon = '<i class="fa fa-check-circle"></i> ';
										break;
				case 'print':
				case 'printdirect':
										$class .= ' btn-default df2-action-trigger';
										$icon = $this->app()->ui()->icon('system/actionPrint');
										$printerId = isset($btn['printer']) ? $btn['printer'] : '0';
										$params .= " data-report='{$btn ['data-report']}' data-printer='{$printerId}' data-pk='{$this->form->recData['ndx']}' data-table='".$this->form->table->tableId()."'";
										$side = 1;
										break;
				case 'unlock':
										continue 2;
			}

			if (isset($btn['close']) && $btn['close'])
				$side = 0;

			if (isset ($btn['icon']))
				$icon = $this->app()->ui()->icon($btn['icon']);

			if ($btn['style'] == 'stateSave' && $btn['stateStyle'] == 'edit' && !isset ($btn['noclose']))
				$side = 0;

			if (isset ($btn['focus']))
				$params .= " autofocus='autofocus'";

			if (isset ($btn['data-popup-url']))
				$params .= " data-popup-url='{$btn['data-popup-url']}'";
			if (isset ($btn['data-popup-width']))
				$params .= " data-popup-width='{$btn['data-popup-width']}'";
			if (isset ($btn['data-popup-height']))
				$params .= " data-popup-height='{$btn['data-popup-height']}'";
			if (isset($btn['title']))
				$params .= " title='".utils::es ($btn['title'])."'";

			$class .= (isset($btn['class'])) ? " {$btn['class']}" : " ".'shp-widget-action';

			if (isset ($btn['subButtons']))
				$btnsCode [$side] .= "<div class='btn-group'>";

			if ($side === 0)
				$btnsCode [$side] .= "<button{$btnid} class='btn btn-large$class' data-action='{$btn['action']}' data-fid='{$this->form->fid}' data-form='{$this->form->fid}'{$params}>{$icon}&nbsp;{$btn['text']}</button> ";
			else
			{
				$btnsCode [$side] .= "<li>";
				$btnsCode [$side] .= "<a href='#' {$btnid} class='dropdown-item shp-widget-action' data-action='{$btn['action']}' data-fid='{$this->form->fid}' data-form='{$this->form->fid}'{$params}>{$icon}&nbsp;{$btn['text']}</a>";
				$btnsCode [$side] .= "</li>";
			}

			/*
			if (isset ($btn['subButtons']))
			{
				foreach($btn['subButtons'] as $subbtn)
					$btnsCode [$side] .= $this->app()->ui()->actionCode($subbtn);
				$btnsCode [$side] .= '</div>';
			}
			*/
		}

		$c .= "<div class='btn-group'>";
		$c .= $btnsCode [0];
		$c .= '</div>';
		if ($btnsCode [1] != '')
		{
			$c .= ' ';
			$c .= "<div class='btn-group'>";
			$c .= "<div class='dropdown'>";
			$c .= "<button class='btn btn-outline' type='button' data-bs-toggle='dropdown' aria-expanded='false'>".$this->app()->ui()->icon('system/iconOther')."</button>";
			$c .= "<ul class='dropdown-menu'>";
			$c .= $btnsCode [1];
			$c .= '</ul>';
			$c .= '</div>';
			$c .= '</div>';
		}
		return $c;
	}

  public function createHeaderCode ()
	{
		$h = $this->form->createHeader();
		return $this->defaultHedearCode ($h);
	}

	public function defaultHedearCode ($headerInfo, $xtitle='', $xinfo='')
	{
		if (is_string ($headerInfo))
		{
			$info = array ('icon' => $headerInfo, 'title' => $xtitle, 'info' => $xinfo);
			error_log ("#WARNING: old defaultHedearCode style used!");
		}
		else
			$info = $headerInfo;

		$class = '';
		$icon = '';
		$iconClass = 'e10-docstyle-off';
		if ($this->form->docState && $this->form->table)
		{
			$docStateClass = $this->form->table->getDocumentStateInfo ($this->form->docState ['states'], $this->form->recData, 'styleClass');
			if ($docStateClass)
			{
				$iconClass = 'e10-docstyle-on';
				$class = ' '.$docStateClass;
				$class .= ' e10-ds-block';
				$stateIcon = $this->form->table->getDocumentStateInfo ($this->form->docState ['states'], $this->form->recData, 'styleIcon');
				$stateText = \E10\es ($this->form->table->getDocumentStateInfo ($this->form->docState ['states'], $this->form->recData, 'name'));
			}
		}

		$headerCode = "<div class='d-flex content-header$class p-2'>";
		/*
		if (isset ($info ['image']))
		{
			$headerCode .= "<div class='content-header-img-new' style='background-image: url({$info['image']});'>";
			$headerCode .= '</div>';
		}
		elseif (isset($info ['emoji']))
		{
			$headerCode .= "<div class='content-header-emoji $iconClass'><span>".utils::es($info ['emoji'])."</span></div>";
		}
		else
		{
			$iconClass = '';
			if (isset($headerInfo['!error']))
				$iconClass .= 'e10-error';
			$headerCode .= "<div class='content-header-icon-new'>".$this->app()->ui()->icon($info ['icon'] ?? 'system/iconFile', $iconClass, 'span')."</div>";
		}
		*/

		// info
		$headerCode .= "<div class='order-2 ms-auto content-header-info-new'>";
		$headerCode .= $this->createToolbarCode();

		/*
		if (isset ($info ['info']))
		{
			if (is_string($info ['info']))
			{ // old compatibility mode
				$headerCode .= "<span class='txt'>{$info ['info']}</span>";
				if (isset($info ['title']))
					$headerCode .= "<h1>{$info ['title']}</h1>";
			}
			else
			{
				forEach ($headerInfo ['info'] as $info)
				{
					$headerCode .= "<div class='{$info ['class']}'>";
					$headerCode .= $this->app()->ui()->composeTextLine ($info ['value']);
					$headerCode .= '</div>';
				}
			}
		}
		*/
		$headerCode .= "</div>";

		// buttons
		$headerCode .= "<div class='order-1 content-header-btns'>";
		$headerCode .= "<span style='font-size: 1.6em;' class='shp-widget-action e10-close-detail p-2' data-action='closeForm'>".$this->app()->ui()->icon('user/arrowLeft')."</span>";
		$headerCode .= "</div>";

		$headerCode .= "</div>";

		return $headerCode;
	}

	function addColumnInput ($e)
	{
		/*
			'type' => self::etColumnInput,
			'columnId' => $columnId,
			'options' => $options,
			'params' => $params,
			'columnPath' => $columnPath,
		*/
		$columnId = $e['columnId'];
		$options = $e['options'] ?? 0;
		$params = $e['params'] ?? FALSE;
		$columnPath = $e['columnPath'] ?? '';

		$col = $this->form->inputColDef($columnId, $columnPath);
		if (!$col)
			return;

		if ($columnPath !== '')
		{
			$colType = DataModel::$ctStringTypes[$col['type']];
			$colOptions = 0;
			if (isset($col ['options']) && in_array('saveOnChange', $col ['options']))
				$colOptions |= DataModel::coSaveOnChange;
		}
		else
		{
			//$col = $this->table->column($columnId);
			$colType = $col ['type'];
			$colOptions = $col ['options'];
		}

		$options |= $colOptions;
		if ($this->form->readOnly)
			$options |= TableForm::coReadOnly;
		$colLen = isset($col ['len']) ? $col ['len'] : 0;
		$multiple = isset ($col ['enumMultiple'])? $col['enumMultiple'] : 0;
		if ($multiple)
			$options |= DataModel::coEnumMultiple;
		$labelText = $this->form->columnLabel ($col, $options);

		switch ($colType)
		{
			case DataModel::ctString:			$this->addInput($columnId, $labelText, TableForm::INPUT_STYLE_STRING, $options, $colLen, $params, '', $columnPath); break;
			case DataModel::ctMoney:			$this->addInput($columnId, $labelText, TableForm::INPUT_STYLE_MONEY, $options, 0, $params, '', $columnPath); break;
			case DataModel::ctNumber:			$this->addInput($columnId, $labelText, TableForm::INPUT_STYLE_DOUBLE, $options, 0, $params, '', $columnPath); break;
			case DataModel::ctDate:				$this->addInput($columnId, $labelText, TableForm::INPUT_STYLE_DATE, $options, 0, $params, '', $columnPath); break;
			case DataModel::ctTimeStamp:	$this->addInput($columnId, $labelText, TableForm::INPUT_STYLE_DATETIME, $options, 0, $params, '', $columnPath); break;
			case DataModel::ctTime:				$this->addInput($columnId, $labelText, TableForm::INPUT_STYLE_TIME, $options, $colLen, $params, '', $columnPath); break;
			case DataModel::ctTimeLen:		$this->addInput($columnId, $labelText, TableForm::INPUT_STYLE_TIMELEN, $options, $colLen, $params, '', $columnPath); break;
			case DataModel::ctLogical:		$this->addInputCheckBox($columnId, $labelText, 1, $options); break;
			case DataModel::ctEnumString:
			case DataModel::ctEnumInt:		$this->addInputEnum ($columnId, $labelText, TableForm::INPUT_STYLE_OPTION, $options, $columnPath); break;
			case DataModel::ctMemo:				$this->addInputMemo ($columnId, $labelText, $options, $col ['type'], $col); break;
			case DataModel::ctInt:				$this->addInputInt ($columnId, $labelText, TableForm::INPUT_STYLE_INT, $options, $columnPath); break;
			case DataModel::ctShort:			$this->addInputInt ($columnId, $labelText, TableForm::INPUT_STYLE_INT, $options, $columnPath); break;
			case DataModel::ctLong:				$this->addInputInt ($columnId, $labelText, TableForm::INPUT_STYLE_INT, $options, $columnPath); break;
		}
	}

	function addInput ($columnId, $label, $inputStyle = TableForm::INPUT_STYLE_STRING, $options = 0, $len = 0, $params = FALSE, $forcePlaceholder = '', $columnPath = '')
	{
		$ip = $this->form->option('inputPrefix', '');
		$colDef = $this->form->inputColDef($columnId, $columnPath);
		$inputClass = '';
		$inputParams = '';
		$inputType = 'text';
		$inputCodePrefix = '';
		$inputCodeCoreSuffix = '';
		$inputCodeSuffix = '';
		$rightLayout = FALSE;
		$placeholder = '';
		if ($forcePlaceholder != '')
			$placeholder = " placeholder='".utils::es($forcePlaceholder)."'";
		elseif (isset($colDef['placeholder']))
			$placeholder = " placeholder='".utils::es($colDef['placeholder'])."'";

		switch ($inputStyle)
		{
			case TableForm::INPUT_STYLE_MONEY: $inputClass = 'e10-inputMoney'; $inputParams .= "autocomplete='off'"; $rightLayout = TRUE; break;
			case TableForm::INPUT_STYLE_DATE: $this->inputInfoHtml($inputStyle, $inputClass, $inputType, $inputParams); break;
			case TableForm::INPUT_STYLE_DATETIME: $this->inputInfoHtml($inputStyle, $inputClass, $inputType, $inputParams); break;
			case TableForm::INPUT_STYLE_TIME: $inputType='time'; $inputClass = 'e10-inputTime';break;
			case TableForm::INPUT_STYLE_TIMELEN: $inputClass = 'e10-inputTimeLen'; $placeholder = " placeholder='HH:MM'";break;
			case TableForm::INPUT_STYLE_DOUBLE: $inputClass = 'e10-inputDouble';  $inputParams .= "autocomplete='off'"; $rightLayout = TRUE; break;
			case TableForm::INPUT_STYLE_INT: $inputClass = 'e10-inputInt';  $inputParams .= "autocomplete='off'"; $rightLayout = TRUE; break;
			case TableForm::INPUT_STYLE_STRING:
										$inputClass = 'e10-inputString';
										if ($len)
											$inputParams .= " maxlength='$len'";
										if ($this->form->activeLayout !== TableForm::ltGrid)
										{
											if ($len < 15)
												$inputClass .= ' e10-ef-w15';
											else
											if ($len < 50)
												$inputClass .= ' e10-ef-w50';
											else
											if ($len < 100)
												$inputClass .= ' e10-ef-w100';
											else $inputClass .= ' e10-ef-w999';
										}
										if ($colDef && isset($colDef['subtype']))
											$inputType = $colDef['subtype'];
										break;
			case TableForm::INPUT_STYLE_STRING_COLOR:
										$inputClass = 'e10-inputString';
										if ($len)
											$inputParams .= " maxlength='$len'";
										$inputType = 'color';
										break;
		}

		if ($options & DataModel::coScanner)
			$inputClass .= ' e10-input-scanner';

		if ($inputType === 'color')
		{
			$inputType = 'text';
			$inputParams .= " data-sidebar-local='color'";
			$inputCodePrefix = "<div class='e10-inputReferenceColor'>";
			$inputClass .= ' e10-inputColor';
			$inputCodeSuffix = " <span></span>";
			$inputCodeSuffix .= '</div>';
		}

		if (isset($colDef['comboClass']))
		{
			$inputParams .= " data-sidebar-remote='{$colDef['comboClass']}'";
		}

		$hidden = $options & TableForm::coHidden;
		$inputClass .= $this->form->columnOptionsClass ($options);

		$colId = str_replace('.', '_', $this->form->fid . "_inp_$ip{$columnId}");
		$finalColumnName = $ip.$columnId;

		if (isset ($colDef ['comboTable']))
		{
			$inputClass .= ' e10-inputRefId e10-inputRefIdDirty e10-viewer-search';
			$comboTableId = $this->form->tableId();
			$inputParams .= " data-column='$columnId' data-srctable='$comboTableId' data-sid='{$this->form->fid}Sidebar'";

			$inputCodePrefix = "<div class='e10-inputReferenceDirty' id='{$colId}RefInpJHGFD'>";
			$inputCodeCoreSuffix = '</div>';
		}

		if ($options & TableForm::coRight)
			$rightLayout = TRUE;

		if ($options & TableForm::coDisabled && $options & TableForm::coReadOnly)
			$inputParams .= " readonly='readonly' disabled";
		elseif ($options & TableForm::coReadOnly)
			$inputParams .= " readonly='readonly'";
		elseif ($options & TableForm::coDisabled)
			$inputParams .= " readonly='readonly' disabled";

		if ($options & TableForm::coFocus)
		{
			$inputClass .= ' autofocus';
			$this->form->setFlag ('autofocus', 1);
		}
		if ($hidden)
			$inputType = 'hidden';

		$inputParams .= " data-fid='{$this->form->fid}'";

		if ($colDef)
			$inputParams .= $this->form->formInputClientEvents($colDef);

		if (isset ($params['value']))
			$inputParams .= " value='".utils::es($params['value'])."'";

		$labelClass = '';
		if ($params !== FALSE && !($options & TableForm::coReadOnly))
		{
			if (isset ($params['srcSensor']))
			{
				$inputParams .= " data-srcsensor='{$params['srcSensor']}'";
				$inputClass .= ' e10-fromSensor';
				$inputCodeSuffix = "&nbsp;⇇&nbsp;<button class='btn btn-info btn-sm e10-document-trigger sensor' data-action='setInputValue' data-inputid='$colId' id='{$colId}_sensor'>12345</button>";
			}
			else
			if (isset ($params['plusminus']))
			{
				$inputCodePrefix = "<button class='e10-document-trigger e10-plusminus' tabindex='-1' data-action='decInputValue' data-inputid='$colId' style='position: absolute; bottom: 5px; left: 5px;'><i class='fa fa-minus-square'></i></button>";
				$inputCodeSuffix = "<button class='e10-document-trigger e10-plusminus' tabindex='-1' data-action='incInputValue' data-inputid='$colId' style='position: absolute; bottom: 5px; right: 5px;'><i class='fa fa-plus-square'></i></button>";
				$inputClass .= ' e10-plusminus';
 				$labelClass = 'e10-plusminus';
			}
		}

		$labelCode = NULL;
		$hints = $this->form->columnOptionsHints ($options, $columnId);

		if ($this->form->activeLayout === TableForm::ltGrid)
		{
			if ($inputStyle === TableForm::INPUT_STYLE_DATETIME)
			{
				$hints['class'] .= ' e10-dateTime';
			}

			if ($rightLayout)
			{
				$inputClass .= ' glr';
				$labelClass .= ' glr';
			}
			else
			{
				$inputClass .= ' gll';
				$labelClass .= ' gll';
			}

			if ($label && !$hidden)
			{
				if ($options & TableForm::coPlaceholder)
				{
					$labelCode = '';
					$placeholder = " placeholder='".utils::es($label)."'";
				}
				else
				{
					$inputClass .= ' insidelabel';
					$labelCode = "<label class='$labelClass' for='$colId'>" . self::e ($label) . "</label>". $inputCodeSuffix;
				}
			}
			$inputCode = $inputCodePrefix."<input type='{$inputType}' name='$finalColumnName' id='$colId' class='$inputClass'$inputParams$placeholder/>".$inputCodeCoreSuffix;
		}
		else
		{
			if ($label && !$hidden)
			{
				if ($options & TableForm::coPlaceholder)
					$placeholder = " placeholder='".utils::es($label)."'";
				else
					$labelCode = "<label{$labelClass} for='$colId'>" . Utils::es ($label) . "</label>";
			}
			$inputCode = $inputCodePrefix."<input type='{$inputType}' name='$finalColumnName' id='$colId' class='$inputClass'$inputParams$placeholder/>".$inputCodeSuffix;
		}

		if ($inputStyle == TableForm::INPUT_STYLE_DATETIME)
		{
			$inputTypeParams = '';
			$timeInputType = 'text';
			if ($inputType === 'date')
			{
				$inputTypeParams = " step='300'";
				$timeInputType = 'time';
			}

			$inputCode .= " / <input type='$timeInputType' name='{$finalColumnName}_Time' id='{$colId}_Time' data-miid='{$colId}' class='e10-inputDateTime_Time'$inputTypeParams/>";
		}

		if ($this->form->activeLayout === TableForm::ltRenderedTable)
		{
			//$this->lastInputCode = $inputCode;
			return;
		}

		if (isset ($params['noLabel']))
			$labelCode = NULL;

		if ($hidden)
			$this->appendCode ($inputCode);
		else
			$this->appendElement ($inputCode, $labelCode, $hints);
	}

	function addInputCheckBox ($columnId, $label, $valueForTrue = "1", $options = 0, $checked = FALSE)
	{
		$class = $this->form->columnOptionsClass ($options);

		if ($this->form->readOnly)
			$options |= TableForm::coReadOnly;

		$labelClassValue = '';
		$inputParams = '';
		if ($options & TableForm::coReadOnly)
		{
			$inputParams .= " disabled='disabled'";
			$labelClassValue .= 'e10-off ';
		}
		if ($checked !== FALSE && $checked)
			$inputParams .= " checked='checked'";

		if ($this->form->activeLayout === TableForm::ltGrid)
			$labelClassValue .= "checkbox";

		$labelClass = ($labelClassValue !== '') ? " class='$labelClassValue'" : '';

		$ip = $this->form->option ('inputPrefix', '');
		$colId = str_replace('.', '_', $this->form->fid . "_inp_$ip{$columnId}");
		$labelCode = NULL;
		if ($label)
			$labelCode = "<label$labelClass for='$colId'>" . $this->app()->ui()->composeTextLine($label) . "</label>";
		$inputCode = "<input type='checkbox' name='$ip{$columnId}' id='$colId' class='e10-inputLogical$class' value='{$valueForTrue}' data-fid='{$this->form->fid}'$inputParams/>";
		$hints = $this->form->columnOptionsHints ($options);

		if ($options & TableForm::coRight || $options & TableForm::coRightCheckbox)
			$this->appendElement ($labelCode, $inputCode, $hints);
		else
			$this->appendElement ($inputCode, $labelCode, $hints);
	}

	function addInputEnum ($columnId, $label, $style = TableForm::INPUT_STYLE_RADIO, $options = 0, $columnPath = '')
	{
		$ip = $this->form->option('inputPrefix', '');
		$colDef = $this->form->inputColDef($columnId, $columnPath);
		$colId = str_replace ('.', '_', $this->form->fid."_inp_$ip{$columnId}");
		$finalColumnName = $ip.$columnId;

		$hidden = $options & TableForm::coHidden;
		if ($hidden)
		{
			$colId = str_replace ('.', '_', "inp_$ip{$columnId}");
			$inputCode = "<input type='hidden' name='$ip{$columnId}' id='$colId' data-fid='{$this->form->fid}'/>";
			$this->appendCode ($inputCode);
			return;
		}

		$labelCode = NULL;

		if ($label && $this->form->activeLayout !== TableForm::ltGrid)
			$labelCode = "<label for='$colId'>" . Utils::es ($label) . "</label>";
		$inputCode = "";

		if ($columnPath !== '')
		{
			if ($this->form->table)
				$a = $this->form->table->subColumnEnum($colDef, $this->form);
			else
				$a = $this->app()->subColumnEnum($colDef, 'cfgText');
		}
		else
			$a = $this->form->table->columnInfoEnum ($columnId, 'cfgText', $this->form);
		if ($style == TableForm::INPUT_STYLE_RADIO)
		{
			$inputClass = $this->form->columnOptionsClass ($options);
			foreach ($a as $val => $txt)
				$inputCode .= " <input type='radio' class='e10-inputRadio $inputClass' id='{$colId}_$val' name='$finalColumnName' value='$val'> <label for='inp_$ip{$columnId}_$val'>" . Utils::es ($txt) . "</label>";
		}
		if ($style == TableForm::INPUT_STYLE_OPTION)
		{
			$multiple = 0;
			if ($options & DataModel::coEnumMultiple)
				$multiple = 1;

			$class = "class='e10-inputEnum";
			if ($multiple)
				$class .= " e10-inputEnumMultiple chzn-select";
			$class .= $this->form->columnOptionsClass ($options);
			$class .= "'";
			$inputCode .= "<select name='$finalColumnName' id='$colId' data-fid='{$this->form->fid}' $class";
			if ($multiple)
				$inputCode .= " multiple='multiple'";

			if ($options & TableForm::coReadOnly)
				$inputCode .= " disabled='disabled'";

			//if ($multiple)
			//	$inputCode .= " style='width: 90%;'";
			$inputCode .= ">";
			foreach ($a as $val => $txt)
			{
				$inputCode .= " <option value='$val'>";
				$inputCode .= ($this->form->activeLayout === TableForm::ltGrid && $txt === '') ? '-- '.Utils::es ($label).' --' : Utils::es ($txt);
				$inputCode .= '</option>';
			}
			$inputCode .= "</select>";
		}

		$hints = $this->form->columnOptionsHints ($options);
		$this->appendElement ($inputCode, $labelCode, $hints);
	}

	function addInputMemo ($columnId, $label, $options = NULL, $columnType = DataModel::ctMemo, $colDef = NULL)
	{
		$ip = $this->form->option ('inputPrefix', '');
		$colId = str_replace ('.', '_', $this->form->fid."_inp_$ip{$columnId}");

		$colDef = ($this->form->table) ? $this->form->table->column ($columnId) : NULL;

		if ($columnType === DataModel::ctCode)
			$inputClass = 'e10-inputMemo e10-inputCode';
		else
			$inputClass = 'e10-inputMemo';
		$inputClass .= $this->form->columnOptionsClass ($options);

		$inputParams = '';

		if ($options & TableForm::coFullSizeY)
			$inputParams .= " style='height: 100%; border: none; padding: .5ex;'";

		if ($options & TableForm::coReadOnly || $this->form->readOnly)
			$inputParams .= " readonly='readonly'";
		$inputParams .= " data-fid='{$this->form->fid}'";

		if ($colDef && isset($colDef['comboClass']))
		{
			$inputParams .= " data-sidebar-remote='{$colDef['comboClass']}'";
		}

		$inputCodePrefix = '';
		$inputCodeCoreSuffix = '';

		if ($colDef && isset ($colDef ['comboTable']))
		{
			$inputClass .= ' e10-inputRefId e10-inputRefIdDirty e10-viewer-search';
			$comboTableId = $this->form->tableId();
			$inputParams .= " data-column='$columnId' data-srctable='$comboTableId' data-sid='{$this->form->fid}Sidebar'";

			$inputCodePrefix = "<div class='e10-inputReferenceDirty' id='{$colId}RefInpJHGFD'";
			if ($options & TableForm::coFullSizeY)
				$inputCodePrefix .= " style='height: 100%;'";

			$inputCodePrefix .= ">";
			$inputCodeCoreSuffix = '</div>';
		}

		$labelCode = NULL;
		$labelClass = '';
		if ($this->form->activeLayout === TableForm::ltGrid)
			$labelClass = " class='gll'";

		$inputCode = '';

		if ($label !== NULL /* && !($options & TableForm::coFullSizeY)*/)
			$labelCode = "<label for='inp_$ip{$columnId}'$labelClass>" . Utils::es ($label) . "</label>";
		$inputCode .= $inputCodePrefix."<textarea name='$ip{$columnId}' id='$colId' class='$inputClass'$inputParams></textarea>".$inputCodeCoreSuffix;

		$hints = $this->form->columnOptionsHints ($options, $columnId);
		$this->appendElement ($inputCode, $labelCode, $hints);
	}

	function appendElement ($widget, $label = NULL, $hints = '')
	{
		$this->renderedData['hcContent'] .= "<div class='row p-1'>";
		if ($label)
		{
			$this->renderedData['hcContent'] .= "<div class='col-4 text-end'>";
			$this->renderedData['hcContent'] .= $label;
			$this->renderedData['hcContent'] .= "</div>";
			$this->renderedData['hcContent'] .= "<div class='col-8'>";
			$this->renderedData['hcContent'] .= $widget;
			$this->renderedData['hcContent'] .= "</div>";
		}
		else
		{
			$this->renderedData['hcContent'] .= "<div class='col-12'>";
			$this->renderedData['hcContent'] .= $widget;
			$this->renderedData['hcContent'] .= "</div>";
		}
		$this->renderedData['hcContent'] .= "</div>";
	}

	function appendCode ($code)
	{
		$this->renderedData['hcContent'] .= $code;
	}

	function inputInfoHtml ($colType, &$inputClass, &$inputType, &$inputParams, $maxLen = 0)
	{
		if ($colType === TableForm::INPUT_STYLE_DATE || $colType === TableForm::INPUT_STYLE_DATETIME)
		{
			if (0 && $this->form->dateInputStyle === TableForm::disOld)
				$inputClass = 'e10-inputDate';
			elseif (1 || $this->form->dateInputStyle === TableForm::disNative)
			{
				$inputClass = 'e10-inputDateN';
				$inputType = 'date';
			}
			elseif ($this->form->dateInputStyle === TableForm::disShipard)
			{
				$inputClass = 'e10-inputDateS';
				$inputParams .= " data-sidebar-local='calendar'";
			}

			if ($colType === TableForm::INPUT_STYLE_DATETIME)
				$inputClass .= ' e10-inputDateTime';
		}
	}
}
