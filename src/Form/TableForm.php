<?php

namespace Shipard\Form;

use \translation\dicts\e10\base\system\DictSystem;
use \e10\utils;
use \e10\uiutils;
use \Shipard\Application\DataModel;
use \Shipard\Table\DbTable;
use \Shipard\Base\Content;
use \Shipard\Viewer\TableView;
use \Shipard\UI\Core\ContentRenderer;
use \Shipard\Utils\TableRenderer;


class TableForm
{
	/** @var \e10\DbTable */
	var $table;
	var $html;
	var $sidebar;
	var $infoPanel = NULL;
	var $header;
	var $item;
	var $ok = 1;
	var $formId = "";
	var $formOp = "";
	var $documentPhase = "insert";
	var $postData;
	var $recData = array ();
	var $uiData = array ();
	var $lists = array ();
	var $groups = array ();
	var $infoItems = array ();
	var $tabsId = '';
	var $tabsIdx = 0;
	var $options = [];
	var $flags = array ();
	var $readOnly = 0;
	var $saveResult = [];
	var $rowElementsCount = -1;

	var $viewPortWidth = 0;

	var $subForm = FALSE;
	var $subForms = array ();

	var $subColumnsInfo = [];
	var $subColumnInfo = NULL;
	var $subColumnsData = FALSE;

	var $htmlCodeTopMenuSearch;

	var $codeStack = array ();
	var $layoutStack = array ();
	var $activeLayout;

	var $fid;
	var $docState;
	var $lockState;
	var $copyDoc = 0;

	var $dirtyColsReferences;

	const INPUT_STYLE_RADIO = 1, INPUT_STYLE_OPTION = 2, INPUT_STYLE_MONEY = 3, INPUT_STYLE_DATE = 4, INPUT_STYLE_STRING = 5,
				INPUT_STYLE_DOUBLE = 6, INPUT_STYLE_INT = 7, INPUT_STYLE_DATETIME = 8, INPUT_STYLE_TIME = 9, INPUT_STYLE_TIMELEN = 10,
				INPUT_STYLE_STRING_COLOR = 11;
	const SIDEBAR_POS_NONE = 0, SIDEBAR_POS_LEFT = 1, SIDEBAR_POS_RIGHT = 2, SIDEBAR_POS_PARENT_FORM = 3;
	const ltForm = 1, ltHorizontal = 2, ltVertical = 3, ltDocMain = 4, ltDocRows = 5, ltNone = 6, ltGrid = 7, ltRenderedTable = 99;

	const disOld = 0, disNative = 1, disShipard = 2;
	var $dateInputStyle = self::disShipard;

	// -- column options - lower bits are reserved for dataModel column options
	const coHidden				= 0x00000100,
				coHeader				= 0x00000200,
				coFocus					= 0x00000400,
				coFullSizeY			= 0x00001000,
				coReadOnly			= 0x00002000,
				coNoLabel				= 0x00004000,
				coInfoText			= 0x00008000,

				coColWidthMask	= 0x0FFF0000,
				coColW1					= 0x00010000,
				coColW2					= 0x00020000,
				coColW3					= 0x00040000,
				coColW4					= 0x00080000,
				coColW5					= 0x00100000,
				coColW6					= 0x00200000,
				coColW7					= 0x00400000,
				coColW8					= 0x00800000,
				coColW9					= 0x01000000,
				coColW10				= 0x02000000,
				coColW11				= 0x04000000,
				coColW12				= 0x08000000,

				coPlaceholder		= 0x10000000,
				coH1						= 0x20000000,
				coH2						= 0x40000000,
				coRight					= 0x80000000,
				coBold					= 0x100000000,
				coH3						= 0x200000000,
				coH4						= 0x400000000,
				coRightCheckbox	= 0x800000000,
				coDisabled			= 0x1000000000;


	const loAddToFormLayout = 0x1000, loWidgetParts = 0x2000, loRowsDisableMove = 0x4000;

	var $app = NULL;
	public function app() {return $this->app;}

	public function __construct($table, $formId, $formOp)
	{
		$this->table = $table;
		if ($this->table)
			$this->app = $this->table->app();
		$this->formId = $formId;
		$this->formOp = $formOp;

		$this->docState = NULL;
		$this->lockState = FALSE;

		if ($this->table)
		{
			$disCfg = $this->app()->cfgItem('options.experimental.dateInputStyle', 's');
			if ($disCfg === 'n')
				$this->dateInputStyle = self::disNative;
			elseif ($disCfg === 's')
				$this->dateInputStyle = self::disShipard;
		}
		$fid = $this->app()->testGetParam ('newFormId');
		if ($fid == '')
			$fid = 'mainEditForm'/* . time ()*/;
		$this->fid = $fid;

		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_NONE);

		$this->viewPortWidth = intval($this->app()->testGetParam('viewPortWidth'));
		if (!$this->viewPortWidth)
			$this->viewPortWidth = 1370;
	}

	protected function addSubForm (\E10\TableForm $form)
	{
		$this->subForms[] = $form;
		$form->subForm = TRUE;
		$form->fid = $this->fid.'_S'.count($this->subForms);
		$form->renderForm();
		$this->appendCode($form->finalCode());
	}

	public function docLinkEnabled ($docLink)
	{
		return TRUE;
	}

	public function stackPush ()
	{
		$this->codeStack [] = "";
	}

	public function stackPop ()
	{
		return array_pop ($this->codeStack);
	}

	public function setRecData ($recData)
	{
		if ($recData)
		{
			if (isset ($recData['recData']))
			{
				$this->recData = $recData['recData'];
				$this->uiData = $recData['ui'] ?? [];
			}
			else
				$this->recData = $recData;
		}
		if (!$this->table)
			return;

		$this->docState = $this->table->getDocumentState ($this->recData);
		if ($this->docState)
			$this->readOnly = $this->docState ['readOnly'];
		$this->lockState = $this->table->getDocumentLockState ($this->recData, $this);
		if ($this->lockState !== FALSE)
			$this->readOnly = TRUE;
				$this->loadGroups ();

		$this->subColumnsData = $this->table->createSubColumnsData($this->recData);
	}


	function appendCode ($code)
	{
		if (count ($this->codeStack))
			$this->codeStack [count ($this->codeStack) - 1] .= $code;
		else
			$this->html .= $code;
	}

	function checkLoadedList ($list)
	{
	}

	public function layoutOpen ($layoutType, $hints = '')
	{
		$this->stackPush ();
		$this->layoutStack [] = $layoutType;
		$this->activeLayout = $layoutType;

		$classes = '';
		if ($hints != '')
			$classes .= ' '.$hints;

		switch ($this->activeLayout)
		{
			case TableForm::ltForm:
						$this->appendCode("<table class='e10-lt-f$classes'>");
						break;
			case TableForm::ltHorizontal:
						$this->appendCode("<table class='e10-lt-h$classes'><tr class='e10-flh'>");
						break;
			case TableForm::ltVertical:
						$this->appendCode("<table class='e10-lt-v$classes'>");
						break;
			case TableForm::ltDocMain:
						$this->appendCode("<div class='e10-lt-m e10-wsh-h2p$classes'>");
						break;
			case TableForm::ltDocRows:
						$this->appendCode("<div class='e10-lt-r e10-wsh-h2p$classes'>");
						break;
		}
	}

	public function layoutClose ($hints = '')
	{
		$prevLayout = $this->activeLayout;
		switch ($this->activeLayout)
		{
			case TableForm::ltForm:
						$this->appendCode("</table> <!-- e10-lt-f -->");
						break;
			case TableForm::ltHorizontal:
						$this->appendCode("</tr><!--H--></table> <!-- e10-lt-h -->");
						break;
			case TableForm::ltVertical:
						$this->appendCode("</table> <!-- e10-lt-v -->");
						break;
			case TableForm::ltDocMain:
						$this->appendCode("</div> <!-- e10-lt-m -->");
						break;
			case TableForm::ltDocRows:
						$this->appendCode("</div> <!-- e10-lt-r -->");
						break;
		}
		array_pop ($this->layoutStack);
		$this->activeLayout = end ($this->layoutStack);
		if ($this->activeLayout === FALSE)
			$this->activeLayout = TableForm::ltNone;

		$c = $this->stackPop ();
		if ($prevLayout == TableForm::ltNone)
			$this->appendCode($c);
		else
			$this->appendElement($c, NULL, $hints);
	}

	static function e ($t)
	{
		return htmlspecialchars($t);
	}

	public function tableId ()
	{
		return $this->table->tableId ();
	}

	function appendElement ($widget, $label = NULL, $hints = '')
	{
		$classes = '';
		$params = '';
		if (is_array($hints))
		{
			if (isset($hints['class']))
				$classes = ' '.$hints['class'];
			if (isset($hints['msg']))
				$params = " data-toggle='tooltip' title=\"".utils::es($hints['msg'])."\" data-placement='bottom'";
		}
		else
		if ($hints != '')
			$classes = ' '.$hints;

		switch ($this->activeLayout)
		{
			case TableForm::ltForm:
						if ($this->rowElementsCount == -1)
						{
							if ($label && $label != '')
								$this->appendCode ("<tr class='e10-flf1'><td class='e10-fl-cellLabel'>$label</td><td class='e10-fl-cellInput$classes'$params>$widget</td></tr>");
							else
								$this->appendCode ("<tr class='e10-flf2'><td class='e10-fl-cellInput$classes' colspan='2'$params>$widget</td></tr>");
						}
						else
						if ($this->rowElementsCount == 0)
						{
							if ($label && $label != '')
								$this->appendCode ("<tr class='e10-flf1'><td class='e10-fl-cellLabel$classes'>$label</td><td class='e10-fl-cellInput'>$widget");
							else
								$this->appendCode ("<tr class='e10-flf2'><td class='e10-fl-cellInput$classes' colspan='2'>$widget");
							$this->rowElementsCount++;
						}
						else
						{
							if ($label && $label != '')
								$this->appendCode ("<span class='e10-inline-input$classes'>$label $widget</span>");
							else
								$this->appendCode ("<span class='e10-inline-input$classes'>$widget</span>");
							$this->rowElementsCount++;
						}
						break;
			case TableForm::ltHorizontal:
						if ($label && $label != '')
							$this->appendCode ("<td class='e10-fl-cellLabel'>$label</td><td class='e10-fl-cellInput$classes'$params>$widget</td>");
						else
							$this->appendCode ("<td class='e10-fl-cellInput$classes'$params>$widget</td>");
						break;
			case TableForm::ltVertical:
						if ($label && $label != '')
							$this->appendCode ("<tr class='e10-flv1'><td class='e10-fl-cellInput$classes'$params>$label<br/>$widget</td></tr>");
						else
							$this->appendCode ("<tr class='e10-flv2'><td class='e10-fl-cellInput$classes'$params>$widget</td></tr>");
						break;
			case TableForm::ltGrid:
						$this->appendCode ("<span class='e10-gl-col$classes'>$widget $label</span>");
						break;
			case TableForm::ltNone:
			case TableForm::ltDocRows:
			case TableForm::ltDocMain:
						if ($label && $label != '')
							$this->appendCode ($label."<br/>".$widget);
						else
							$this->appendCode ($widget);
						break;
		}
	}

	function addInfoItem ($key, $value)
	{
		$this->infoItems [$key] = $value;
	}

	function addCheckBox ($columnId, $label, $valueForTrue = "1", $options = 0, $checked = FALSE)
	{
		$class = $this->columnOptionsClass ($options);

		if ($this->readOnly)
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

		if ($this->activeLayout === TableForm::ltGrid)
			$labelClassValue .= "checkbox";

		$labelClass = ($labelClassValue !== '') ? " class='$labelClassValue'" : '';

		$ip = $this->option ('inputPrefix', '');
		$colId = str_replace('.', '_', $this->fid . "_inp_$ip{$columnId}");
		$labelCode = NULL;
		if ($label)
			$labelCode = "<label$labelClass for='$colId'>" . $this->app()->ui()->renderTextLine($label) . "</label>";
		$inputCode = "<input type='checkbox' name='$ip{$columnId}' id='$colId' class='e10-inputLogical$class' value='{$valueForTrue}' data-fid='{$this->fid}'$inputParams/>";
		$hints = $this->columnOptionsHints ($options);

		if ($options & TableForm::coRight || $options & TableForm::coRightCheckbox)
			$this->appendElement ($labelCode, $inputCode, $hints);
		else
			$this->appendElement ($inputCode, $labelCode, $hints);
	}

	function inputInfoHtml ($colType, &$inputClass, &$inputType, &$inputParams, $maxLen = 0)
	{
		if ($colType === self::INPUT_STYLE_DATE || $colType === self::INPUT_STYLE_DATETIME)
		{
			if ($this->dateInputStyle === self::disOld)
				$inputClass = 'e10-inputDate';
			elseif ($this->dateInputStyle === self::disNative)
			{
				$inputClass = 'e10-inputDateN';
				$inputType = 'date';
			}
			elseif ($this->dateInputStyle === self::disShipard)
			{
				$inputClass = 'e10-inputDateS';
				$inputParams .= " data-sidebar-local='calendar'";
			}

			if ($colType === self::INPUT_STYLE_DATETIME)
				$inputClass .= ' e10-inputDateTime';
		}
	}

	function addAttachmentsViewer()
	{
		$c = '';
		$ta = $this->app()->table ('e10.base.attachments');
		$v = $ta->getTableView ('lib.core.attachments.viewers.DocAttachments',
			['tableid' => $this->table->tableId(), 'recid' => isset($this->recData ['ndx']) ? $this->recData ['ndx'] : 0]);
		$v->renderViewerData ('');
		$c .= $v->createViewerCode ('', FALSE);
		$v->appendAsSubObject();

		$this->appendElement($c);
	}

	function inputColDef ($columnId, $columnPath)
	{
		if (!$this->table)
			return NULL;

		if ($columnPath === '')
			return $this->table->column($columnId);

		$path = explode ('.', $columnPath);
		$subColumnId = $path[0];
		if (count($path) === 1)
		{
			$col = utils::searchArray($this->subColumnsInfo[$subColumnId]['columns'], 'id', $columnId);
			return $col;
		}

		$col = utils::searchArray($this->subColumnsInfo[$subColumnId]['columns'], 'id', $path[1]);
		$col2 = utils::searchArray($col['columns'], 'id', $columnId);
		return $col2;
	}

	function addColumnInput ($columnId, $options = 0, $params = FALSE, $columnPath = '')
	{
		$col = $this->inputColDef($columnId, $columnPath);
		if (!$col)
			return;

		if ($columnPath !== '')
		{
			//$col = utils::searchArray($subColumnsInfo['columns'], 'id', $subColumnId);
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
		if ($this->readOnly)
			$options |= TableForm::coReadOnly;
		$colLen = isset($col ['len']) ? $col ['len'] : 0;
		$multiple = isset ($col ['enumMultiple'])? $col['enumMultiple'] : 0;
		if ($multiple)
			$options |= DataModel::coEnumMultiple;
		$labelText = $this->columnLabel ($col, $options);

		switch ($colType)
		{
			case DataModel::ctString:			$this->addInput($columnId, $labelText, self::INPUT_STYLE_STRING, $options, $colLen, $params, '', $columnPath); break;
			case DataModel::ctMoney:			$this->addInput($columnId, $labelText, self::INPUT_STYLE_MONEY, $options, 0, $params, '', $columnPath); break;
			case DataModel::ctNumber:			$this->addInput($columnId, $labelText, self::INPUT_STYLE_DOUBLE, $options, 0, $params, '', $columnPath); break;
			case DataModel::ctDate:				$this->addInput($columnId, $labelText, self::INPUT_STYLE_DATE, $options, 0, $params, '', $columnPath); break;
			case DataModel::ctTimeStamp:	$this->addInput($columnId, $labelText, self::INPUT_STYLE_DATETIME, $options, 0, $params, '', $columnPath); break;
			case DataModel::ctTime:				$this->addInput($columnId, $labelText, self::INPUT_STYLE_TIME, $options, $colLen, $params, '', $columnPath); break;
			case DataModel::ctTimeLen:		$this->addInput($columnId, $labelText, self::INPUT_STYLE_TIMELEN, $options, $colLen, $params, '', $columnPath); break;
			case DataModel::ctLogical:		$this->addCheckBox($columnId, $labelText, 1, $options); break;
			case DataModel::ctEnumString:
			case DataModel::ctEnumInt:		$this->addInputEnum ($columnId, $labelText, self::INPUT_STYLE_OPTION, $options, $columnPath); break;
			case DataModel::ctMemo:				$this->addInputMemo ($columnId, $labelText, $options, $col ['type'], $col); break;
			case DataModel::ctInt:				$this->addInputInt ($columnId, $labelText, self::INPUT_STYLE_INT, $options, $columnPath); break;
			case DataModel::ctShort:			$this->addInputInt ($columnId, $labelText, self::INPUT_STYLE_INT, $options, $columnPath); break;
			case DataModel::ctLong:				$this->addInputInt ($columnId, $labelText, self::INPUT_STYLE_INT, $options, $columnPath); break;
		}
	}

	function addInput ($columnId, $label, $inputStyle = self::INPUT_STYLE_STRING, $options = 0, $len = 0, $params = FALSE, $forcePlaceholder = '', $columnPath = '')
	{
		$ip = $this->option('inputPrefix', '');
		$colDef = $this->inputColDef($columnId, $columnPath);
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
			case self::INPUT_STYLE_MONEY: $inputClass = 'e10-inputMoney'; $inputParams .= "autocomplete='off'"; $rightLayout = TRUE; break;
			case self::INPUT_STYLE_DATE: $this->inputInfoHtml($inputStyle, $inputClass, $inputType, $inputParams); break;
			case self::INPUT_STYLE_DATETIME: $this->inputInfoHtml($inputStyle, $inputClass, $inputType, $inputParams); break;
			case self::INPUT_STYLE_TIME: $inputClass = 'e10-inputTime'; $placeholder = " placeholder='HH:MM'";break;
			case self::INPUT_STYLE_TIMELEN: $inputClass = 'e10-inputTimeLen'; $placeholder = " placeholder='HH:MM'";break;
			case self::INPUT_STYLE_DOUBLE: $inputClass = 'e10-inputDouble';  $inputParams .= "autocomplete='off'"; $rightLayout = TRUE; break;
			case self::INPUT_STYLE_INT: $inputClass = 'e10-inputInt';  $inputParams .= "autocomplete='off'"; $rightLayout = TRUE; break;
			case self::INPUT_STYLE_STRING:
										$inputClass = 'e10-inputString';
										if ($len)
											$inputParams .= " maxlength='$len'";
										if ($this->activeLayout !== TableForm::ltGrid)
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
			case self::INPUT_STYLE_STRING_COLOR:
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
		$inputClass .= $this->columnOptionsClass ($options);

		$colId = str_replace('.', '_', $this->fid . "_inp_$ip{$columnId}");
		$finalColumnName = $ip.$columnId;

		if (isset ($colDef ['comboTable']))
		{
			$inputClass .= ' e10-inputRefId e10-inputRefIdDirty e10-viewer-search';
			$comboTableId = $this->tableId();
			$inputParams .= " data-column='$columnId' data-srctable='$comboTableId' data-sid='{$this->fid}Sidebar'";

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
			$this->setFlag ('autofocus', 1);
		}
		if ($hidden)
			$inputType = 'hidden';

		$inputParams .= " data-fid='{$this->fid}'";

		if ($colDef)
			$inputParams .= $this->formInputClientEvents($colDef);

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
		$hints = $this->columnOptionsHints ($options, $columnId);

		if ($this->activeLayout === TableForm::ltGrid)
		{
			if ($inputStyle === self::INPUT_STYLE_DATETIME)
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
					$labelCode = "<label{$labelClass} for='$colId'>" . self::e ($label) . "</label>";
			}
			$inputCode = $inputCodePrefix."<input type='{$inputType}' name='$finalColumnName' id='$colId' class='$inputClass'$inputParams$placeholder/>".$inputCodeSuffix;
		}

		if ($inputStyle == self::INPUT_STYLE_DATETIME)
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

		if ($this->activeLayout === TableForm::ltRenderedTable)
		{
			$this->lastInputCode = $inputCode;
			return;
		}

		if (isset ($params['noLabel']))
			$labelCode = NULL;

		if ($hidden)
			$this->appendCode ($inputCode);
		else
			$this->appendElement ($inputCode, $labelCode, $hints);
	}

	function formInputClientEvents ($colDef)
	{
		$c = '';
		if (!isset($colDef['clientEvents']))
			return $c;

		foreach ($colDef['clientEvents'] as $e)
		{
			$c .= ' ';
			$c .= 'data-clientevent-'.$e['event'].'="'.$e['function'].'"';
		}

		return $c;
	}

	function addInputEnum ($columnId, $label, $style = self::INPUT_STYLE_RADIO, $options = 0, $columnPath = '')
	{
		$ip = $this->option('inputPrefix', '');
		$colDef = $this->inputColDef($columnId, $columnPath);
		$colId = str_replace ('.', '_', $this->fid."_inp_$ip{$columnId}");
		$finalColumnName = $ip.$columnId;

		$hidden = $options & TableForm::coHidden;
		if ($hidden)
		{
			$colId = str_replace ('.', '_', "inp_$ip{$columnId}");
			$inputCode = "<input type='hidden' name='$ip{$columnId}' id='$colId' data-fid='{$this->fid}'/>";
			$this->appendCode ($inputCode);
			return;
		}

		$labelCode = NULL;

		if ($label && $this->activeLayout !== TableForm::ltGrid)
			$labelCode = "<label for='$colId'>" . self::e ($label) . "</label>";
		$inputCode = "";

		if ($columnPath !== '')
		{
			if ($this->table)
				$a = $this->table->subColumnEnum($colDef, $this);
			else
				$a = $this->app()->subColumnEnum($colDef, 'cfgText');
		}
		else
			$a = $this->table->columnInfoEnum ($columnId, 'cfgText', $this);
		if ($style == self::INPUT_STYLE_RADIO)
		{
			foreach ($a as $val => $txt)
				$inputCode .= " <input type='radio' class='e10-inputRadio' id='{$colId}_$val' name='$finalColumnName' value='$val'> <label for='inp_$ip{$columnId}_$val'>" . self::e ($txt) . "</label>";
		}
		if ($style == self::INPUT_STYLE_OPTION)
		{
			$multiple = 0;
			if ($options & DataModel::coEnumMultiple)
				$multiple = 1;

			$class = "class='e10-inputEnum";
			if ($multiple)
				$class .= " e10-inputEnumMultiple chzn-select";
			$class .= $this->columnOptionsClass ($options);
			$class .= "'";
			$inputCode .= "<select name='$finalColumnName' id='$colId' data-fid='{$this->fid}' $class";
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
				$inputCode .= ($this->activeLayout === TableForm::ltGrid && $txt === '') ? '-- '.self::e ($label).' --' : self::e ($txt);
				$inputCode .= '</option>';
			}
			$inputCode .= "</select>";
		}

		$hints = $this->columnOptionsHints ($options);
		$this->appendElement ($inputCode, $labelCode, $hints);
	}

	function addInputEnum2 ($columnId, $label, $enums, $style = self::INPUT_STYLE_RADIO, $options = 0)
	{
		$ip = $this->option ('inputPrefix', '');
		$colId = str_replace ('.', '_', $this->fid."_inp_$ip{$columnId}");

		$labelCode = NULL;
		if ($label)
			$labelCode = "<label for='$colId'>" . $this->app()->ui()->composeTextLine($label) . "</label>";
		$inputCode = "";
		$oneInputCClass = '';
		$a = $enums;
		if ($style == self::INPUT_STYLE_RADIO)
		{
			$active = ' active';
			foreach ($a as $val => $txt)
			{
				if (is_array($txt) && isset($txt['enumLabelOnly']))
				{
					$inputCode .= $this->app()->ui()->composeTextLine($txt);
					$oneInputCClass = ' ml1';
				}
				else
				{
					$inputCode .= "<div class='padd5 e10-selectable-radio$active$oneInputCClass'>";
					$inputCode .= "<input type='radio' class='e10-inputRadio' id='{$colId}_$val' name='$ip{$columnId}' value='$val' data-fid='{$this->fid}'> ";
					$inputCode .= "<label for='{$colId}_$val' style='vertical-align: top;'>" . $this->app()->ui()->composeTextLine($txt) . "</label><br/>";
					$inputCode .= "</div>";
					$active = '';
				}
			}
		}
		if ($style == self::INPUT_STYLE_OPTION)
		{
			$multiple = 0;
			if ($options & DataModel::coEnumMultiple)
				$multiple = 1;

			$class = "class='e10-inputEnum";
			if ($multiple)
				$class .= " e10-inputEnumMultiple chzn-select";
			$class .= $this->columnOptionsClass ($options);
			$class .= "'";
			$inputCode .= "<select name='$ip{$columnId}' id='$colId' data-fid='{$this->fid}' $class";
			if ($multiple)
				$inputCode .= " multiple='multiple'";

			if ($options & TableForm::coReadOnly)
				$inputCode .= " disabled='disabled'";

			//if ($multiple)
			//	$inputCode .= " style='width: 90%;'";
			$inputCode .= ">";
			foreach ($a as $val => $txt)
				$inputCode .= " <option value='$val'>" . self::e ($txt) . "</option>";
			$inputCode .= "</select>";
		}

		$this->appendElement ($inputCode, $labelCode);
	}

	function addInputFiles ()
	{
		$c = "<div class='e10-att-input-upload'>
						<h2>Přidat soubory</h2>
						<input class='e10-att-input-file' name='add-files' data-name='add-files' type='file' onchange='e10AttWidgetFileSelected(this)' multiple='multiple'/>
						<div class='e10-att-input-files'>vyberte soubor(y), které chcete nahrát a stiskněte Odeslat</div>
				 </div>";

		$this->appendElement ($c);
	}

	function addInputInt ($columnId, $label, $inputStyle = self::INPUT_STYLE_INT, $options = 0, $columnPath = '')
	{
		if ($options & TableForm::coFocus)
			$this->setFlag ('autofocus', 1);

		$hidden = $options & TableForm::coHidden;

		$colDef = $this->inputColDef($columnId, $columnPath);

		if (isset ($colDef ['reference']) && !$hidden)
		{
			$ip = $this->option ('inputPrefix', '');

			$refTable = $this->table->app()->table ($colDef ['reference']);
			if (!$refTable)
				return;

			$input = $refTable->columnRefInput ($this, $this->table, $columnId, $options, $label, $ip);
			$hints = $this->columnOptionsHints ($options, $columnId);
			$this->appendElement ($input ['inputCode'], $input ['labelCode'], $hints);
			return;
		}

		$this->addInput ($columnId, $label, self::INPUT_STYLE_INT, $options, 0, FALSE, '', $columnPath);
	}

	function addInputIntRef ($columnId, $refTableId, $label, $options = 0)
	{
		$ip = $this->option ('inputPrefix', '');

		$refTable = $this->app()->table ($refTableId);
		if (!$refTable)
			return;

		$input = $refTable->columnRefInput ($this, $this->table, $columnId, $options, $label, $ip);
		$hints = $this->columnOptionsHints ($options, $columnId);
		$this->appendElement ($input ['inputCode'], $input ['labelCode'], $hints);
	}

	function addInputMemo ($columnId, $label, $options = NULL, $columnType = DataModel::ctMemo, $colDef = NULL)
	{
		$ip = $this->option ('inputPrefix', '');
		$colId = str_replace ('.', '_', $this->fid."_inp_$ip{$columnId}");

		$colDef = ($this->table) ? $this->table->column ($columnId) : NULL;

		if ($columnType === DataModel::ctCode)
			$inputClass = 'e10-inputMemo e10-inputCode';
		else
			$inputClass = 'e10-inputMemo';
		$inputClass .= $this->columnOptionsClass ($options);

		$inputParams = '';

		if ($options & TableForm::coFullSizeY)
			$inputParams .= " style='height: 100%; border: none; padding: .5ex;'";

		if ($options & TableForm::coReadOnly || $this->readOnly)
			$inputParams .= " readonly='readonly'";
		$inputParams .= " data-fid='{$this->fid}'";

		if ($colDef && isset($colDef['comboClass']))
		{
			$inputParams .= " data-sidebar-remote='{$colDef['comboClass']}'";
		}

		$inputCodePrefix = '';
		$inputCodeCoreSuffix = '';

		if ($colDef && isset ($colDef ['comboTable']))
		{
			$inputClass .= ' e10-inputRefId e10-inputRefIdDirty e10-viewer-search';
			$comboTableId = $this->tableId();
			$inputParams .= " data-column='$columnId' data-srctable='$comboTableId' data-sid='{$this->fid}Sidebar'";

			$inputCodePrefix = "<div class='e10-inputReferenceDirty' id='{$colId}RefInpJHGFD'";
			if ($options & TableForm::coFullSizeY)
				$inputCodePrefix .= " style='height: 100%;'";

			$inputCodePrefix .= ">";
			$inputCodeCoreSuffix = '</div>';
		}

		$labelCode = NULL;
		$labelClass = '';
		if ($this->activeLayout === TableForm::ltGrid)
			$labelClass = " class='gll'";

		$inputCode = '';

		if ($label !== NULL && !($options & TableForm::coFullSizeY))
			$inputCode .= "<label for='inp_$ip{$columnId}'$labelClass>" . self::e ($label) . "</label>";
		$inputCode .= $inputCodePrefix."<textarea name='$ip{$columnId}' id='$colId' class='$inputClass'$inputParams></textarea>".$inputCodeCoreSuffix;

		$hints = $this->columnOptionsHints ($options, $columnId);
		$this->appendElement ($inputCode, $labelCode, $hints);
	}

	function addInputTree ($columnId, $label, $treeInputDefinition, $options = 0, $columnPath = '')
	{
		$ip = $this->option ('inputPrefix', '');



		$colId = str_replace('.', '_', $this->fid . "_inp_$ip{$columnId}");
		$finalColumnName = $ip.$columnId;

		/** @var \lib\core\ui\SumTable $o */
		$o = $this->app()->createObject($treeInputDefinition['objectId']);
		if (!$o)
		{
			return;
		}
		$o->renderAll = 1;
		if (isset($treeInputDefinition['queryParams']))
			$o->setQueryParams($treeInputDefinition['queryParams']);
		$o->setColumnId ($this->fid, $colId, $finalColumnName);
		$o->init();
		$o->loadData();
		$o->renderCode();

		$labelCode = ($label && $label !== '') ? $this->app()->ui()->composeTextLine($label) : '';

		$hints = $this->columnOptionsHints ($options, $columnId);
		$this->appendElement ($o->code, $labelCode, $hints);
	}

	function addList ($listId, $label = '', $options = 0)
	{
		$listDefinition = $this->table->listDefinition ($listId);
		$listObject = $this->table->app()->createObject ($listDefinition ['class']);
		$listObject->setRecord ($listId, $this);
		$inputCode = $listObject->createHtmlCode ($options);

		if (!$inputCode)
			return '';

		if ($options & TableForm::loWidgetParts)
		{
			return $inputCode;
		}

		if ($options & TableForm::loAddToFormLayout)
		{
			$this->appendCode ($inputCode);
		}
		else
		{
			$labelCode = NULL;
			if ($label)
				$labelCode = "<label>" . self::e ($label) . "</label>";
			$this->appendElement ($inputCode, $labelCode);
		}
	}

	function addListViewer ($listId, $listViewerId, $label = '', $options = 0)
	{
		$listDefinition = $this->table->listDefinition ($listId);
		//$listTable = $this->app()->table($listDefinition['table']);

		$params = [$listDefinition['queryColumn'] => $this->recData['ndx']];

		$content[] = [
			'type' => 'viewer', 'table' => $listDefinition['table'], 'viewer' => $listViewerId,
			'params' => $params, 'inlineSourceElement' => ['type' => 'form', 'id' => $this->fid, 'detail-element' => $this->fid.'Sidebar', 'detail-id' => 'default']
		];

		$this->addContent($content, 0, FALSE, 0);
	}

	public function addSubColumns ($columnId, $isRowMode = 0)
	{
		$sci = $this->subColumnInfo($columnId);
		if (!$sci)
			return 0;

		$this->subColumnInfo = $sci;

		$oldInputPrefix = $this->option('inputPrefix', '');
		if ($isRowMode)
			$this->setOption('inputPrefix', $oldInputPrefix.'subColumns_'.$columnId.'_');
		else
			$this->setOption('inputPrefix', 'subColumns.'.$columnId.'.');

		if (isset ($sci['groups']))
		{
			foreach ($sci['groups'] as $group)
			{
				$groupAdded = 0;
				foreach ($sci['columns'] as $col)
				{
					if (!isset($col['group']) || $col['group'] !== $group['id'])
						continue;
					$sco = uiutils::subColumnEnabled ($col, $this->subColumnsData[$columnId]);
					if ($sco === FALSE)
						continue;

					if (!$groupAdded && isset($group['title']))
					{
						$this->addGroupLabel ($group['title']);
						$groupAdded = 1;
					}

					$this->addColumnInput($col['id'], $sco, FALSE, $columnId);
				}
			}
		}
		else
		if (isset ($sci['layout']))
		{
			foreach ($sci['layout'] as $layoutItem)
			{
				if (isset($layoutItem['columns']))
				{
					foreach ($layoutItem['columns'] as $subColumnId)
						$this->addColumnInput($subColumnId, 0, FALSE, $columnId);
				}
				elseif (isset($layoutItem['table']))
				{
					$oldLayout = $this->activeLayout;
					$this->activeLayout = TableForm::ltRenderedTable;

					$params = [];
					if (isset($layoutItem['table']['params']))
						$params = array_merge($params, $layoutItem['table']['params']);

					if (!isset($layoutItem['table']['params']) || !isset($layoutItem['table']['params']['tableClass']))
						$params['tableClass'] = 'e10-dataSet-table';

					if (isset($layoutItem['table']['header']))
						$params['header'] = $layoutItem['table']['header'];

					$tr = new TableRenderer($layoutItem['table']['rows'], $layoutItem['table']['cols'], $params, $this->app());
					$tr->form = $this;
					$tr->subColumnInfo = $sci;
					$tr->formColumnId = $columnId;
					$code = "<div class='padd5 e10-pane-dataSet'>";

					if (isset($layoutItem['table']['title']))
						$code .= "<div class='subtitle'>".$this->app()->ui()->composeTextLine($layoutItem['table']['title']).'</div>';

					$code .= $tr->render();
					$code .= '</div>';
					$this->activeLayout = $oldLayout;
					$this->appendElement($code);
				}
				elseif (isset($layoutItem['recordSetTable']))
				{
					$oldLayout = $this->activeLayout;
					$this->activeLayout = TableForm::ltRenderedTable;
					$params = [];
					if (isset($layoutItem['recordSetTable']['params']))
						$params = array_merge($params, $layoutItem['recordSetTable']['params']);
					if (isset($layoutItem['recordSetTable']['header']))
						$params['header'] = $layoutItem['recordSetTable']['header'];
					if (!isset($layoutItem['recordSetTable']['params']) || !isset($layoutItem['recordSetTable']['params']['tableClass']))
						$params['tableClass'] = 'e10-dataSet-table';

					$columnPath = $columnId.'.'.$layoutItem['recordSetTable']['recordSetColumn'];
					$cntRows = 6;
					$rowNdx = 1;
					$rc = '';
					for ($rowNumber = 0; $rowNumber < $cntRows; $rowNumber++)
					{
						$this->setOption('inputPrefix', 'subColumns.'.$columnId.'.'.$layoutItem['recordSetTable']['recordSetColumn'].'.'.$rowNumber.'.');
						$rc .= "<tr>";
						foreach ($layoutItem['recordSetTable']['cols'] as $subColumnId => $subColumnName)
						{
							if ($subColumnId === '#')
							{
								$rc .= "<td class='number'>";
								$rc .= strval($rowNumber + 1);
							}
							else
							{
								$rc .= "<td>";
								$this->addColumnInput($subColumnId, 0, FALSE, $columnPath);
								$rc .= $this->lastInputCode;
							}
							$rc .= "</td>";
						}
						$rc .= "</tr>";
					}
					$this->setOption('inputPrefix', 'subColumns.'.$columnId.'.');

					$tr = new TableRenderer($rc, $layoutItem['recordSetTable']['cols'], $params, $this->app());
					$tr->form = $this;
					$tr->subColumnInfo = $sci;
					$tr->formColumnId = $columnId;
					$code = "<div class='padd5 e10-pane-dataSet'>".$tr->render().'</div>';
					$this->activeLayout = $oldLayout;
					$this->appendElement($code);
				}
			}
		}
		else
		{
			foreach ($sci['columns'] as $col)
			{
				$sco = uiutils::subColumnEnabled ($col, $this->subColumnsData[$columnId]);
				if ($sco === FALSE)
					continue;
				$params = uiutils::subColumnInputParams($col, $this->subColumnsData[$columnId] ?? []);
				$this->addColumnInput($col['id'], $sco, $params, $columnId);
			}
		}

		$this->setOption('inputPrefix', $oldInputPrefix);

		return 1;
	}

	function subColumnInfo ($columnId)
	{
		if (!isset($this->subColumnsInfo[$columnId]))
			$this->subColumnsInfo[$columnId] = $this->table->subColumnsInfo($this->recData, $columnId);

		return $this->subColumnsInfo[$columnId];
	}

	public function loadList ($listId)
	{
		$listDefinition = $this->table->listDefinition ($listId);
		$listObject = $this->table->app()->createObject ($listDefinition ['class']);
		$listObject->setRecord ($listId, $this);
		$listObject->loadData ();
	}

	function addPicturesWidget ($disableImages = 0)
	{
		$app = $this->table->app();

		if (isset ($this->recData['ndx']))
		{
			$images = \E10\Base\getAttachments ($app, 'e10doc.core.heads', $this->recData['ndx'], TRUE);
		}
		else
		{
			$addPicture = $this->app()->testGetParam ('addPicture');
			if ($addPicture === '' && isset ($app->workplace['startDocumentCamera']))
			{
				if (isset ($this->postData['cameras'][$app->workplace['startDocumentCamera']]))
					$addPicture = $this->postData['cameras'][$app->workplace['startDocumentCamera']];
			}
		}

		$c = '';
		if ($disableImages === 0)
			$c .= "<div class='' style='border: 1px solid #666; width: 100%; height: 14em;'>";

		if (isset ($images) && $disableImages === 0)
		{
			$thumbSize = 512;
			forEach ($images as $img)
			{
				$thumbUrl = \E10\Base\getAttachmentUrl ($app, $img, $thumbSize, 2 * $thumbSize);

				$c .= "<div style=\"";
				$c .= "background-image:url('";
				$c .= $thumbUrl;
				$c .= "'); background-size: cover; background-position: center; width: 100%; height: 100%;\"></div>";

				break;
			}
		}
		else
		if (isset($addPicture))
		{
			if ($disableImages === 0)
			{
				$c .= "<div style=\"";
				$c .= "background-image:url('";
				$c .= $addPicture;
				$c .= "'); background-size: cover; background-position: center; width: 100%; height: 100%;\"></div>";
			}

			$ip = $this->option ('inputPrefix', '');
			$c .= "<input type='hidden' name='{$ip}_addPicture' data-fid='{$this->fid}'/>";
			$this->recData['_addPicture'] = $addPicture;
		}

		if ($disableImages === 0)
			$c .= '</div>';

		$this->appendElement ($c, NULL);
	}

	function addSeparator ($options = 0, $params = FALSE)
	{
		$hints = $this->columnOptionsHints ($options);

		$class = 'e10-ef-label';
		if (is_string($options))
			$class .= ' '.$options;
		else
		{
			if ($options & TableForm::coH1)
				$class .= ' h1';
			elseif ($options & TableForm::coH2)
				$class .= ' h2';
			elseif ($options & TableForm::coH3)
				$class .= ' h3';
			elseif ($options & TableForm::coH4)
				$class .= ' e10-bg-t9';
		}
		$c = "<hr class='$class'/>";
		$this->appendElement ($c, NULL, $hints);
	}

	function addGroupLabel ($label)
	{
		$c = "<tr class='e10-flf1 e10-form-group'>" .
			"<td class='e10-fl-cellLabel'>" . $this->app()->ui()->composeTextLine($label) .
			"</td><td class='e10-fl-cellInput' style='text-align: right; vertical-align: middle; padding-right: 1ex;'>" .
			'</td></tr>';

		$this->appendCode($c);
	}

	function addInfoText ($content, $options = 0, $params = FALSE)
	{
		$hints = $this->columnOptionsHints ($options);

		$class = 'e10-ef-it';
		if ($options & TableForm::coH1)
			$class .= ' h1';
		$c = "<span class='$class'>".$this->app()->ui()->composeTextLine($content, '').'</span>';
		$this->appendElement ($c, NULL, $hints);
	}

	function addStatic ($content, $options = 0, $params = FALSE)
	{
		$hints = $this->columnOptionsHints ($options);

		if (
			is_string($content)
			|| ((isset ($content['text']) || isset ($content[0]['text']) || isset ($content[0][0]['text'])) && !isset ($content['type']))
		)
		{
			$class = 'e10-ef-label';
			if ($options & TableForm::coH1)
				$class .= ' h1';
			if ($options & TableForm::coH2)
				$class .= ' h2';
			if ($options & TableForm::coRight)
				$class .= ' e10-right block';
			if ($options & TableForm::coBold)
				$class .= ' e10-bold';
			$c = "<span class='$class'>".$this->app()->ui()->composeTextLine($content).'</span>';
			$this->appendElement ($c, NULL, $hints);
			return;
		}
		if (is_array($content))
		{
			if (isset($content['type']))
				$this->appendElement (uiutils::renderContentPart ($this->app(), $content), NULL, $hints);
			//else
			//	$this->appendElement ('INVALID-CONTENT: '.json_encode ($content));
		}
	}

	function addContent ($content, $options = 0, $params = FALSE, $envelope = 1)
	{
		$cr = new ContentRenderer($this->app());
		$cr->content = $content;

		$code = '';
		if ($envelope)
			$code .= "<div style='padding: 4px;'>";
		$code .= $cr->createCode();
		if ($envelope)
			$code .= '</div>';

		$hints = $this->columnOptionsHints ($options);
		$this->appendElement ($code, NULL, $hints);
	}

	function addDocumentCard ($cardClassId)
	{
		$card = $this->app()->createObject($cardClassId);
		$card->setDocument($this->table, $this->recData);
		$card->createContent();

		$cr = new ContentRenderer($this->app());
		$cr->setDocumentCard($card);
		$code = "<div class='padd5'>".$cr->createCode('body').'</div>';
		$this->appendCode($code);
	}

	function addWebView ($url, $options = NULL)
	{
		$inputClass = 'e10-webView';

		if ($options & TableForm::coFullSizeY)
			$inputClass .= ' e10-wsh-h2b';

		$labelCode = NULL;
		//$inputCode = "<textarea name='$ip{$columnId}' id='inp_$ip{$columnId}' class='$inputClass'></textarea>";
		$inputCode = "<iframe src='$url' frameborder='0' style='width: 100%;' class='$inputClass'></iframe>";
		$this->appendElement ($inputCode, $labelCode);
	}

	function addWidget ($widget)
	{
		$this->appendElement ($widget->createHtmlCode (), NULL);
	}

	function addViewerWidget ($tableId, $viewClass, $options = NULL, $embedd = FALSE)
	{
		$c = '';

		/** @var \e10\TableView $v */
		$v = $this->table->app()->table ($tableId)->getTableView ($viewClass, $options);
		$v->type = 'form';
		$v->renderViewerData ('');

		if ($embedd)
			$c .= "<div class='e10-wsh-h2b' data-init-viewers='1' data-min-height='300' style='display: inline-block; width: 100%; border: 1px solid rgba(0,0,0,.4); border-radius: 2px;'>";
		$c .= $v->createViewerCode ('', TRUE);
		if ($embedd)
			$c .= "</div>";

		$this->appendElement ($c, NULL);
	}

	public function appendListRow ($listId)
	{
		return TRUE;
	}

	function columnLabel ($colDef, $options)
	{
		if ($options & TableForm::coNoLabel)
			return '';
		return isset ($colDef ['label']) ? $colDef ['label'] : $colDef ['name'];
	}

	function columnOptionsClass ($options)
	{
		$c = '';
		if ($options & DataModel::coSaveOnChange)
			$c .= ' e10-ino-saveOnChange';
		return $c;
	}

	function columnOptionsHints ($options, $columnId = NULL)
	{
		$wh = array (0x0001 => 1,
				0x0002=> 2,
				0x0004=> 3,
				0x0008=> 4,
				0x0010=> 5,
				0x0020=> 6,
				0x0040=> 7,
				0x0080=> 8,
				0x0100=> 9,
				0x0200=> 10,
				0x0400=> 11,
				0x0800=> 12
		);

		$h = [];
		$class = '';
		if ($options & TableForm::coColWidthMask)
			$class .= ' e10-gl-col'.$wh[(($options>>16) & 0x0FFF)];

		if ($options & TableForm::coRight)
			$class .= ' right';

		if ($columnId && isset($this->saveResult['columnStates']))
		{
			if (isset ($this->saveResult['columnStates'][$columnId]))
				$class .= ' e10-column-'.$this->saveResult['columnStates'][$columnId]['style'];
			$h['msg'] = $this->saveResult['columnStates'][$columnId]['msg'];
		}

		if ($class !== '')
			$h['class'] = $class;

		if (!count($h))
			return '';

		return $h;
	}

	function openTabs ($tabs, $fullSize = FALSE)
	{
		$this->stackPush();
		$this->tabsId = 't-' . time ();
		$this->tabsIdx = 1;

		if (is_string($tabs))
		{
			$tabsCode = "<ul class='e10-form-tabs' id='{$this->tabsId}'>";
			$tabList = explode ('|', $tabs);
			$class = "class='active'";
			forEach ($tabList as $t)
			{
				$tabsCode .= "<li $class id='{$this->tabsId}-{$this->tabsIdx}'>" . self::e ($t) . "</li>";
				$this->tabsIdx++;
				$class = '';
			}
			$tabsCode .= "</ul>";
		}
		else
		{
			$tabsClass = 'e10-form-maintabs e10-wsh-h2b';
			if ($fullSize === FALSE)
				$tabsClass .= ' left';
			else
			if ($fullSize === TRUE)
				$tabsClass .= ' fullSize left';
			else
			if ($fullSize === 'right')
				$tabsClass .= ' fullSize right';

			$this->tabsId = $this->fid . '-mt';
			$mainClass = 'e10-form-maintabs';
			if (isset ($tabs ['class']))
				$mainClass = $tabs ['class'];
			$tabsCode = "<div class='$tabsClass' data-main-tabs='1'>"; // closed at closeTabs
			$tabsCode .= "<div class='e10-form-maintabs-menu e10-wsh-h2p'><ul class='$mainClass' id='{$this->tabsId}'>";

			$class = "class='active'";
			forEach ($tabs ['tabs'] as $t)
			{
				$thisTabId = $this->tabsId.'-'.$this->tabsIdx;
				if (isset ($this->uiData['activeMainTab']))
				{
					if ($this->uiData['activeMainTab'] == $thisTabId)
						$class = "class='active'";
					else
						$class = '';
				}
				$tabsCode .= "<li $class id='$thisTabId' data-first-click='1'>";
				$tabsCode .= $this->app()->ui()->icon($t ['icon'], '', 'div');
				$tabsCode .= self::e ($t ['text']) . "</li>";
				$this->tabsIdx++;
				$class = '';
			}

			$tabsCode .= "</ul></div>";
		}
		$this->appendCode ($tabsCode);
		$this->tabsIdx = 1;
	}

	function closeTabs ()
	{
		$c = $this->stackPop ();
		if ($this->tabsId [0] == 'm')
			$c .= '</div>';
		$this->appendElement($c);
	}

	function openTab ($layoutType = TableForm::ltForm)
	{
		$this->stackPush();
		$this->appendCode ("<div id='{$this->tabsId}-{$this->tabsIdx}-tc' class='e10-form-tab e10-wsh-h2p' data-e10mxw='1'>");
		$this->layoutOpen (TableForm::ltNone);
		$this->layoutOpen ($layoutType);
	}

	function openRow ($hints = '')
	{
		$class = 'e10-form-row';
		if ($hints !== '')
			$class .= ' '.$hints;
		$this->rowElementsCount = 0;
		if ($this->activeLayout == TableForm::ltGrid)
			$this->appendCode ("<div class='$class'>");
	}

	function closeRow ()
	{
		$this->rowElementsCount = -1;
		if ($this->activeLayout == TableForm::ltForm && $this->rowElementsCount != -1)
			$this->appendCode ('</td></tr>');
		else if ($this->activeLayout == TableForm::ltGrid)
			$this->appendCode ('</div>');
	}

	function closeTab ()
	{
		$this->layoutClose ();
		$this->layoutClose ();
		$this->appendCode ("</div>");
		$c = $this->stackPop ();
		$this->appendCode ($c);
		$this->tabsIdx++;
	}

	public function createHeader ()
	{
		$header = $this->table->createHeader ($this->recData, DbTable::chmEditForm);
		return $header;
	}

	public function createHeaderCode ()
	{
		$h = $this->createHeader();
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
		if ($this->docState)
		{
			$docStateClass = $this->table->getDocumentStateInfo ($this->docState ['states'], $this->recData, 'styleClass');
			if ($docStateClass)
			{
				$iconClass = 'e10-docstyle-on';
				$class = ' '.$docStateClass;
				$class .= ' e10-ds-block';
				$stateIcon = $this->table->getDocumentStateInfo ($this->docState ['states'], $this->recData, 'styleIcon');
				$stateText = \E10\es ($this->table->getDocumentStateInfo ($this->docState ['states'], $this->recData, 'name'));
			}
		}

		$headerCode = "<div class='content-header$class'>";
		$headerCode .= "<table><tr>";

		if (isset ($info ['image']))
		{
			$headerCode .= "<td class='content-header-img-new' style='background-image: url({$info['image']});'>";
			$headerCode .= '</td>';
		}
		elseif (isset($info ['emoji']))
		{
			$headerCode .= "<td class='content-header-emoji $iconClass'><span>".utils::es($info ['emoji'])."</span></td>";
		}
		else
		{
			$iconClass = '';
			if (isset($headerInfo['!error']))
				$iconClass .= 'e10-error';
			$headerCode .= "<td class='content-header-icon-new'>".$this->app()->ui()->icon($info ['icon'] ?? 'system/iconFile', $iconClass, 'span')."</td>";
		}

		// info
		$headerCode .= "<td class='content-header-info-new'>";
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
		$headerCode .= "</td>";

		// sum table
		if (is_array ($headerInfo) && isset ($headerInfo ['sumTable']) && $headerInfo['sumTable'] !== FALSE)
		{
			forEach ($headerInfo ['sumTable'] as $sum)
			{
				$headerCode .= "<td class='sum-header-table'>";
				$headerCode .= \E10\renderTableFromArray ($sum['data'], $sum ['header'], $sum ['params']);
				$headerCode .= "</td>";
			}
		}

		// sum info
		if (is_array ($headerInfo) && isset ($headerInfo ['sum']))
		{
			$headerCode .= "<td class='sum-header-info'>";
			forEach ($headerInfo ['sum'] as $sum)
			{
				$headerCode .= "<div class='{$sum ['class']}'>";
				if (isset($sum ['prefix']))
					$headerCode .= "<span class='pre'>" . utils::es ($sum ['prefix']) . '</span>';
				$headerCode .= "<span class='val'>" . $this->app()->ui()->composeTextLine ($sum ['value'], '') . '</span>';
				if (isset($sum ['suffix']))
					$headerCode .= "<span class='suf'>" . utils::es ($sum ['suffix']) . '</span>';
				$headerCode .= '</div>';
			}
			$headerCode .= "</td>";
		}

		if (isset ($info ['image']))
		{
			$headerCode .= "<td class='content-header-img-new' style='background-image: url({$info['image']});'>";
			$headerCode .= '</td>';
		}

		// label
		$headerCode .= "<td class='content-header-btns'><button class='df2-action-trigger e10-close-detail' data-action='cancelform'>&times;</button>";
		$headerCode .= "</td>";

		$headerCode .= "</table>";
		$headerCode .= "</div>";

		return $headerCode;
	}

	public function createCode ()
	{
		if ($this->formOp == "sidebar")
		{
			$type = $this->table->app()->requestPath (5);
			$table = $this->table->app()->requestPath (6);
			$id = $this->table->app()->requestPath (7);
			$this->renderSidebar ($type, $table, $id);
		}
		else
		{
			if ($this->lockState === FALSE || !isset($this->lockState['disableContent']))
				$this->renderForm ();
		}
	}

	public function createSaveData () {return NULL;}

	public function createToolbar ()
	{
		$docState = $this->docState;
		if ($docState)
		{
			if (!$this->readOnly)
				$toolbar [] = array ('type' => 'action', 'action' => 'saveform', 'text' => '...', 'style' => 'defaultSave', 'noclose' => 1);
			$toolbar [] = array ('type' => 'action', 'action' => 'cancelform', 'text' => DictSystem::text(DictSystem::diBtn_Close), 'style' => 'cancel');

			if ($this->lockState === FALSE)
			{
				if (isset($docState['state']))
				{
					forEach ($docState ['state']['goto'] as $gotoStateId)
					{
						$gotoState = $docState ['states']['states'][$gotoStateId];

						if (isset($gotoState['roles']))
						{
							$userRoles = $this->app()->user()->data ('roles');
							if (count(array_intersect($userRoles, $gotoState['roles'])) == 0)
								continue;
						}

						if (isset ($gotoState['queryCols']))
						{
							$dsbl = FALSE;
							forEach ($gotoState['queryCols'] as $qcid => $qcv)
							{
								if (is_array($qcv))
								{
									if (!in_array($this->recData[$qcid], $qcv)) {
										$dsbl = TRUE;
										break;
									}
								}
								else
								if ($this->recData[$qcid] != $qcv)
								{
									$dsbl = TRUE;
									break;
								}
							}
							if ($dsbl)
								continue;
						}

						$b = [
							'type' => 'action', 'action' => 'saveform', 'text' => $gotoState ['actionName'], 'docState' => $gotoStateId,
							'style' => 'stateSave', 'stateStyle' => $gotoState ['stateStyle']
						];
						if (isset($gotoState['icon']))
							$b['icon'] = $gotoState['icon'];
						if (isset($gotoState['side']))
							$b['side'] = $gotoState['side'];
						if (isset($gotoState['buttonClass']))
							$b['buttonClass'] = $gotoState['buttonClass'];
						if (isset ($gotoState['focus']) && $gotoState['focus'])
						{
							$b['focus'] = 1;
						}
						if (isset ($gotoState['readOnly']) && $gotoState['readOnly'])
							$b['readOnly'] = 1;
						if (isset ($gotoState['close']) && $gotoState['close'])
							$b['close'] = 1;
						$toolbar [] = $b;
					}
				}
			}
			else
			{
				$toolbar [] = array ('style' => 'unlock');
			}
			if ($this->lockState === FALSE || !isset($this->lockState['disableContent']))
				$this->table->createPrintToolbar ($toolbar, $this->recData);
		}
		else
		{
			if (!$this->readOnly)
				$toolbar [] = array ('type' => 'action', 'action' => 'saveform', 'text' => 'Uložit', 'style' => 'defaultSave');
			$toolbar [] = array ('type' => 'action', 'action' => 'cancelform', 'text' => DictSystem::text(DictSystem::diBtn_Close), 'style' => 'cancel');
		}

		$fd = $this->table->formDefinition($this->formId);
		if ($fd && isset($fd['help']))
		{
			$toolbar [] =[
				'type' => 'action', 'action' => 'open-popup', 'text' => '',
				'icon' => 'system/iconHelp', 'style' => 'cancel', 'side' => 1,
				'data-popup-url' => 'https://shipard.org/'.$fd['help'],
				'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
				'title' => 'Nápověda'//DictSystem::text(DictSystem::diBtn_Help)
			];
		}

		return $toolbar;
	} // createToolbar


	public function createToolbarCode ()
	{
		$c = '';
		$btnsCode = array (0 => '', 1 => '');
		$tlbr = $this->createToolbar ();
		$stateBtnIdx = 0;
		foreach ($tlbr as $btn)
		{
			$side = isset ($btn['side']) ? $btn['side'] : 0; //left; 1 -> right
			$class = '';
			$icon = (isset($btn['icon'])) ? "<i class='fa fa-{$btn['icon']}'></i> " : '';
			$params = '';
			$btnid = '';

			if ($btn['style'] === 'unlock')
			{
				$icon = '<i class="fa fa-lock fa-2x"></i> ';
				$t1 = utils::es ($this->lockState['mainTitle']);
				$t2 = utils::es ($this->lockState['subTitle']);
				$btnsCode [$side] .= "<span style='padding-left: 1em; vertical-align: middle;display: inline-block;'>$icon<h4 style='display: inline-block; position: relative; padding-left: 1ex;'>$t1<br/><small>$t2</small></h4></span>";
				continue;
			}

			switch ($btn['style'])
			{
				case 'defaultSave':
										$class = ' btn-primary e10-savebtn';
										$icon = '<i class="fa fa-download"></i> ';
										$btnid = " id='{$this->fid}Save'";
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
											$params .= " data-docstate='{$btn['docState']}'";
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
										$params .= " data-report='{$btn ['data-report']}' data-printer='{$printerId}' data-pk='{$this->recData['ndx']}' data-table='".$this->table->tableId()."'";
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

			$class .= (isset($btn['class'])) ? " {$btn['class']}" : " df2-{$btn['type']}-trigger";

			if (isset ($btn['subButtons']))
				$btnsCode [$side] .= "<div class='btn-group'>";
			$btnsCode [$side] .= "<button{$btnid} class='btn btn-large$class' data-action='{$btn['action']}' data-fid='{$this->fid}' data-form='{$this->fid}'{$params}>{$icon}&nbsp;{$btn['text']}</button> ";
			if (isset ($btn['subButtons']))
			{
				foreach($btn['subButtons'] as $subbtn)
					$btnsCode [$side] .= $this->app()->ui()->actionCode($subbtn);
				$btnsCode [$side] .= '</div>';
			}
		}

		$c .= "<span style='float: left; '>{$btnsCode [0]}</span>";
		if ($btnsCode [1] != '')
			$c .= "<span style='float: right; '>{$btnsCode [1]}</span>";
		return $c;
	}

	public function doFormData ()
	{
		if ($this->formOp === "new" || $this->formOp === "edit")
		{
			$postData = $this->app()->testGetData();
			$this->postData = json_decode ($postData, TRUE);
		}

		if ($this->formOp == "new")
		{
			$this->checkNewRec ();
			return;
		}
		if ($this->formOp == "edit")
		{
			//$this->saveFormData ($formData);
			return;
		}
		if ($this->formOp == "save")
		{
			$this->table->saveFormData ($this);
			$this->setRecData (NULL);
			$this->validForm ();
			$this->documentPhase = "update";
			return;
		}
		if ($this->formOp == "delete")
		{
			$this->table->dbDeleteRec ($this->recData);
			return;
		}
		if ($this->formOp == "undelete")
		{
			$this->table->dbUndeleteRec ($this->recData);
			return;
		}
		if ($this->formOp == "listappend")
		{
			$this->listAppend ();
			return;
		}
		if ($this->formOp == "sidebar")
		{
			return;
		}
	}

	public function listAppend ()
	{
		$listId = $this->table->app()->requestPath (4);
	}

	public function loadGroups ()	{}

	public function finalCode ()
	{
		return $this->html;
	}

	function closeForm ()
	{
		$this->layoutClose ();
		if ($this->option ('rowMode'))
			$h = "</li>";
		else
		if ($this->subForm)
			$h = '</div></div></div></div>';
		else
			$h = "</div></div>";
		$this->appendCode ($h);
		$c = $this->stackPop ();
		$this->appendCode ($c);
	}

	function openForm ($layoutType = TableForm::ltForm)
	{
		$formStyleClass = $this->flag ('formStyle', 'e10-formStyleDefault');
		$formStyleClass .= ' ' . str_replace ('.', '-', $this->tableId());
		$this->stackPush ();

		$readOnlyParam = '';
		if ($this->readOnly)
			$readOnlyParam = " data-readonly='1'";

		if ($this->option ('rowMode'))
		{
			$layoutOptions = intval($this->option ('layoutOptions', 0));
			$rowNumber = intval($this->option ('rowNumber', 0));
			$ip = $this->option ('inputPrefix', '');
			$ipp =str_replace('.', '_', $ip);
			$list = $this->option ('list', NULL);

			$rn = $this->option ('rowNumber');
			$h = "<li class='e10-row' data-rowid='" . $rn . "' data-inputprefix='{$this->fid}_inp_$ipp'>";

			$h .= "<div class='e10-row-btns'>";
			if (!$this->readOnly)
			{
				$disableDeleteButton = $list->listDefinition ['disableDeleteButton'] ?? 0;
				if ($this->app()->hasRole('root'))
					$disableDeleteButton = 0;
				if ($list && $list->rowsTableOrderCol && !($layoutOptions & TableForm::loRowsDisableMove))
				{
					$rowOrderForInsert = $this->option ('rowOrderForInsert', 0);
					$isLastRow = $this->option ('isLastRow', 0);

					$h .= "<div class='e10-row-menu'>".$this->app()->ui()->icon('system/iconHamburgerMenu');
					$h .= "<div class='e10-row-menu-btns' style='float: left;'>";
					if (!$disableDeleteButton)
						$h .= "<button tabindex='-1' class='e10-row-action' data-action='delete' title='Smazat řádek'>".$this->app()->ui()->icon('system/actionDelete')."</button>";
					if ($rowNumber !== 0)
						$h .= "<button tabindex='-1' class='e10-row-action' data-action='up' title='Posunout nahoru'>".$this->app()->ui()->icon('system/actionMoveUp')."</button>";
					if (!$isLastRow)
						$h .= "<button tabindex='-1' class='e10-row-action' data-action='down' title='Posunout dolů'>".$this->app()->ui()->icon('system/actionMoveDown')."</button>";
					$h .= "<button tabindex='-1' class='e10-row-action' data-action='insert' data-row='$rn' data-row-order='$rowOrderForInsert' title='Vložit řádek'>".$this->app()->ui()->icon('system/actionAdd')."</button>";
					$h .= '</div>';
					$h .= '</div>';
				}
				else
				{
					if (!$disableDeleteButton)
						$h .= "<button tabindex='-1' class='e10-row-action' data-action='delete' title='Smazat řádek'>".$this->app()->ui()->icon('system/actionDelete')."</button>";
				}
			}
			$h .= "<div style='font-size:60%;'>".($rowNumber+1).'</div>';
			$h .= "</div>";

			$h .= "<input type='hidden' name='{$ip}ndx' data-fid='{$this->fid}'/>";
			if ($list)
			{
				if ($list->rowsTableOrderCol)
					$h .= "<input type='hidden' name='{$ip}{$list->rowsTableOrderCol}' data-fid='{$this->fid}'/>";
			}
		}
		else if ($this->subForm)
		{
			$h = '';
			$h .=	"<div id='{$this->fid}Form' class='e10-esf-form e10-wsh-h2b' data-object='modal' data-formId='{$this->fid}' data-srcObjectType='none' data-srcObjectId=''$readOnlyParam>";
				$h .= "<div id='{$this->fid}Header' class='e10-esf-header'></div>";
				$h .= "<div id='{$this->fid}Buttons' class='e10-esf-buttons'>".$this->createToolbarCode ().'</div>';
				$h .=	"<div id='{$this->fid}Content' class='e10-esf-content e10-wsh-h2p' data-e10mxw='1'>";
					$h .= "<div class='df2-form e10-formControl e10-form-{$this->table->tableId()} e10-form-{$this->table->tableId()}-{$this->formId} $formStyleClass' id='{$this->fid}Container'>" .
									"<div id='{$this->fid}' data-object='form' data-table='" .
									$this->tableId () . "'";
									if (isset ($this->recData ['ndx']))
										$h .= " data-pk='{$this->recData ['ndx']}'";
									$h .= " data-formid='{$this->formId}'";
									$h .= '>';
		}
		else
		{
			$ip = $this->fid.'_inp_';

			$h = "<div class='df2-form e10-formControl e10-form-{$this->table->tableId()} e10-form-{$this->table->tableId()}-{$this->formId} $formStyleClass' id='{$this->fid}Container'><div id='{$this->fid}' data-object='form' data-table='" .
							$this->tableId () . "'";
			if (isset ($this->recData ['ndx']))
				$h .= " data-pk='{$this->recData ['ndx']}'";
			$h .= " data-formid='{$this->formId}'$readOnlyParam";
			$h .= " data-inputprefix='$ip'";
			$h .= '>';
		}
		$this->appendCode ($h);
		$this->layoutOpen ($layoutType);
	}

	public function renderForm () {}


	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		return array();
	}

	public function comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, $viewerId = 'default')
	{
		if (isset($this->dirtyColsReferences[$srcColumnId]))
			$browseTable = $this->app()->table ($this->dirtyColsReferences[$srcColumnId]);
		else
		if (isset ($srcColDef ['comboTable']))
			$browseTable = $this->app()->table ($srcColDef ['comboTable']);
		else
			$browseTable = $this->app()->table ($srcColDef ['reference']);

		if (isset ($srcColDef ['comboViewer']))
			$viewerId = $srcColDef ['comboViewer'];

		$comboParams = $this->comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);

		$comboParams['comboRecData'] = [];
		foreach ($recData as $key => $value)
		{
			if (is_string($value) && strlen($value) > 128)
				continue;
			$comboParams['comboRecData'][$key] = $value;
		}

		$comboParams['comboSrcTableId'] = $srcTableId;
		$comboParams['comboSrcColumnId'] = $srcColumnId;

		$viewer = $browseTable->getTableView ($viewerId, $comboParams);
		$viewer->objectSubType = TableView::vsMini;
		$viewer->enableFullTextSearch = FALSE;
		$viewer->comboSettings = array ('column' => $srcColumnId);

		return $viewer;
	}

	public function listParams ($srcTableId, $listId, $listGroup, $recData)
	{
		return [];
	}

	protected function renderMainSidebar ($allRecData, $recData){}

	protected function renderRemoteSidebar ($allRecData, $recData, $id)
	{
		$classId = $this->app()->requestPath(8);
		$sideBar = $this->app()->createObject($classId);
		if (!$sideBar)
			return;

		$sideBar->createSidebar($this->tableId(), $recData);
		$this->sidebar = $sideBar->createHtmlCode();
	}

	public function renderSidebar ($type, $table, $id)
	{
		$allDataStr = $this->app()->postData();
		$allData = json_decode ($allDataStr, TRUE);
		$item = $allData ['recData'];
		$srcColumnName = $this->app()->testGetParam ('columnName');

		if ($type == 'column')
		{
			$colnameParts = explode ('.', $srcColumnName);
			if ($colnameParts[0] === 'subColumns')
			{
				$item = $allData;
				array_pop ($colnameParts);
				forEach ($colnameParts as $p)
					$item = $item [$p];
				$srcTable = $this->app()->table($table);
				$sci = $this->table->subColumnsInfo ($this->recData, $colnameParts[1]);
				$srcColDef = utils::searchArray($sci['columns'], 'id', $id);
			}
			else
			{
				if (count($colnameParts) > 1)
				{
					$item = $allData;
					array_pop($colnameParts);
					forEach ($colnameParts as $p)
						$item = $item [$p];
				}
				$srcTable = $this->app()->table($table);
				$srcColDef = $srcTable->column($id);
			}
			$viewer = $this->comboViewer ($table, $srcColDef, $id, $allData, $item);
			$viewer->renderViewerData ("html");
			$c = $viewer->createViewerCode ("html", "fullCode");

			$sideBar = new FormSidebar ($this->app());
			$sideBar->addTab('t1', $srcTable->tableName());
			$sideBar->setTabContent('t1', $c);

			$this->sidebar = $sideBar->createHtmlCode();
			return;
		}

		if ($type == 'list')
		{
			$listGroup = $this->table->app()->requestPath (8);

			$srcTable = $this->table->app()->table ($table);
			$listDefinition = $srcTable->listDefinition ($id);

			$listObject = $this->table->app()->createObject ($listDefinition ['class']);
			//$listObject->setRecord ($listId, $formData);
			$listObject->setRecData ($srcTable, $id, $item);
			$listObject->formData = $this;

			$c = $listObject->renderSidebar ($listGroup);

			//$c .= json_encode ($listDefinition);

			$this->sidebar = $c;
			return;

		}

		if ($type == 'main')
		{
			$colnameParts = explode ('.', $srcColumnName);
			if (count($colnameParts) > 1)
			{
				$item = $allData;
				array_pop ($colnameParts);
				forEach ($colnameParts as $p)
					$item = $item [$p];
			}
			$this->renderMainSidebar ($allData ['recData'], $item);
			return;
		}

		if ($type == 'remote')
		{
			$this->renderRemoteSidebar ($allData ['recData'], $item, $id);
			return;
		}
	}

/*
	public function setHeader ($c = "")
	{
		$nc = "";
		if ($c == '')
		{
			$nc .= "<div class='e10-ef-hdr'>" .
						 "" .
						 "</div>";
		}
		else
		{
			$nc .= "<div class='e10-ef-hdr'>" . $c . "</div>";
		}
		$this->header = $nc;
	}
	*/

	public function setFlag ($flag, $value)
	{
		$this->flags [$flag] = $value;
	}

	public function flag ($f, $defaultValue = false)
	{
		if (isset ($this->flags [$f]))
			return $this->flags [$f];
		return $defaultValue;
	}


	public function option ($o, $defaultValue = false)
	{
		if (isset ($this->options [$o]))
			return $this->options [$o];
		return $defaultValue;
	}

	public function setOption ($option, $value)
	{
		$this->options[$option] = $value;
	}

	public function setOptions ($options)
	{
		$this->options = $options;
	}

	public function checkAfterSave ()
	{
		return false;
	}

	public function checkBeforeSave (&$saveData){}

	public function checkNewRec ()
	{
		// -- enum columns
		forEach ($this->table->columns() as $colId => $c)
		{
			switch ($c ['type'])
			{
				case DataModel::ctEnumString:
				case DataModel::ctEnumInt:
								if (!isset ($this->recData[$colId]) && !isset($c ['enumMultiple']))
								{
									$a = $this->table->columnInfoEnum ($colId, 'cfgText', $this);
									$this->recData[$colId] = key($a);
								}
								break;
			}
		}
	}

	public function validForm ()
	{

	}

	public function validNewDocumentState ($newDocState, $saveData)
	{
		return TRUE;
	}

	protected function setColumnState ($columnId, $msg, $style = 'error')
	{
		$this->saveResult['columnStates'][$columnId] = ['style' => $style, 'msg' => $msg];
		if ($style === 'error')
			$this->saveResult['disableClose'] = 1;

		$this->saveResult['notifications'][] = ['style' => $style, 'msg' => utils::es($msg)];
	}

	public function formReport ()
	{
		return NULL;
	}
}

