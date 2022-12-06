<?php

namespace Shipard\Table;
use \Shipard\Application\DataModel;
use \e10\utils;
use \e10\json;
use \Shipard\Utils\Str;
use \Shipard\Form\TableForm;
use \Shipard\Utils\Attachments;
use \Shipard\Base\ListData;


class DbTable
{
	/** @var \Shipard\Base\Application */
	public $dbmodel;
	var $app = NULL;
	private $humanName;
	private $sqlName;
	protected $tableId;
	var $ndx = 0;

	const chmViewer = 0x00000001, chmEditForm = 0x00000002;

	public function __construct($model)
	{
		$this->dbmodel = $model;
		$this->app = $model;
	}

	/** @return \E10\Application */
	function app (){return $this->dbmodel;}
	function db (){return $this->dbmodel->db;}
	function saveConfig (){}


	public function getCfgItem ($cfgKey, $checkSource)
	{
		return $this->app()->cfgItem ($cfgKey, NULL);
	}

	public function checkAccessToDocument ($recData)
	{
		if (PHP_SAPI === 'cli')
			return 2;

		if ($this->app()->hasRole ('all'))
			return 2;

		$allRoles = $this->app()->cfgItem ('e10.persons.roles');
		$userRoles = $this->app()->user()->data ('roles');
		$accessLevel = 0;

		forEach ($userRoles as $roleId)
		{
			$r = $allRoles[$roleId];

			if (!isset ($r['viewers']) || !isset($r['viewers'][$this->tableId]))
				continue;

			foreach ($r['viewers'][$this->tableId] as $viewerId => $viewerAccessLevel)
			{
				if ($viewerAccessLevel === 2)
					return 2;
				if ($viewerAccessLevel > $accessLevel)
					$accessLevel = $viewerAccessLevel;
			}
		}

		return $accessLevel;
	}

	public function columns ()
	{
		return $this->app()->model()->columns ($this->tableId);
	}

	public function column ($column)
	{
		return $this->app()->model()->column ($this->tableId, $column);
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		return FALSE;
	}

	public function columnName ($column)
	{
		return $this->app()->model()->columnName ($this->tableId, $column);
	}

	public function columnInfoAutocomplete ($srcTable, $srcColumnDef, $srcRecData, $search = '')
	{
		$res = array ();
		$res [0] = '---';

		$ndxColumn = '';
		$titleColumn = '';

		$autocomplete = $this->app()->model()->tableProperty ($this, 'autocomplete');
		if ($autocomplete)
		{
			$ndxColumn = $autocomplete ['columnValue'];
			$titleColumn = $autocomplete ['columnTitle'];
		}

		if ($ndxColumn == '' || $titleColumn == '')
		{
			return $res;
		}

		$q = "SELECT [$ndxColumn], [$titleColumn] FROM [" . $this->sqlName () . "]";

		$subQuery = $this->columnInfoAutocompleteQuery ($srcTable, $srcColumnDef, $srcRecData, $search);
		if ($subQuery != FALSE)
		{
			$q .= ' WHERE ' . $subQuery;
		}

		$q .= " ORDER BY $titleColumn";
		$q .= " LIMIT 0, 800";
		$rows = $this->fetchAll ($q);

		forEach ($rows as $r)
		{
			$res [$r [$ndxColumn]] = $r [$titleColumn];
		}
		return $res;
	}

	public function columnInfoAutocompleteQuery ($srcTable, $srcColumnDef, $srcRecData, $search)
	{
		return FALSE;
	}

	public function columnInfoReference ($destTable, $srcColumnDef, $srcRecData, $search = '')
	{
		return $destTable->columnInfoAutocomplete ($this, $srcColumnDef, $srcRecData, $search);
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		$res = array ();
		$column = $this->app()->model()->column ($this->tableId, $columnId);

		$enumCfg = NULL;
		if (isset ($column ['enumCfg']) && isset ($column ['enumCfg']['cfgItem']))
			$enumCfg = $this->app->cfgItem($column ['enumCfg']['cfgItem']);
		else
			$enumCfg = $this->columnInfoEnumSrc ($columnId, $form);

		if ($enumCfg)
		{
			$valueKey = '';
			if (isset ($column ['enumCfg']['cfgValue']))
				$valueKey = $column ['enumCfg']['cfgValue'];

			$textKey = '';
			if (isset ($column ['enumCfg'][$valueType]))
				$textKey = $column ['enumCfg'][$valueType];
			if (($textKey == '') && ($valueType != 'cfgText'))
			{
				if (isset ($column ['enumCfg']['cfgText']))
					$textKey = $column ['enumCfg']['cfgText'];
			}

			forEach ($enumCfg as $key => $item)
			{
				if (!$this->columnInfoEnumTest ($columnId, $key, $item, $form))
					continue;
				$thisText = "";
				if ($textKey == "")
					$thisText = $item;
				else
					$thisText = $item [$textKey];

				$thisValue = "";
				if ($valueKey == "")
					$thisValue = $key;
				else
					$thisValue = $item [$valueKey];


				$res [$thisValue] = $thisText;
			}
			return $res;
		}
		if (isset ($column ['enumValues']))
		{
			forEach ($column ['enumValues'] as $value => $text)
				$res [$value] = $text;
			return $res;
		}

		return $res;
	}

	public function columnInfoEnumStr ($recData, $columnId, $valueType = 'cfgText')
	{
		$enum = $this->columnInfoEnum($columnId, $valueType);
		if (isset($enum[$recData[$columnId]]))
			return $enum[$recData[$columnId]];
		return '';
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		return TRUE;
	}

	public function columnInfoEnumSrc ($columnId, $form)
	{
		return NULL;
	}

	public function subColumnEnum ($column, $form, $valueType = 'cfgText')
	{
		return $this->app()->subColumnEnum ($column, $valueType);
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$prefixParts = explode ('.', $inputPrefix);
		if (isset($prefixParts[0]) && $prefixParts[0] === 'subColumns')
			$pk = isset($form->subColumnsData[$prefixParts[1]][$srcColumnId]) ? intval($form->subColumnsData[$prefixParts[1]][$srcColumnId]) : 0;
		else
			$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$ndxColumn = '';
		$titleColumn = '';

		$autocomplete = $this->app()->model()->tableProperty ($this, 'autocomplete');
		if ($autocomplete)
		{
			$ndxColumn = $autocomplete ['columnValue'];
			$titleColumn = $autocomplete ['columnTitle'];
		}

		$q = "SELECT [$titleColumn] FROM [" . $this->sqlName () . "] WHERE [$ndxColumn] = " . intval ($pk);
		$refRec = $this->app()->db()->query ($q)->fetch ();
		$refTitle = $refRec [$titleColumn];

		return $refTitle;
	}

	public function columnRefInput ($form, $srcTable, $srcColumnId, $options, $label, $inputPrefix)
	{
		$thisTableId = $this->tableId();
		$refTitle = $this->columnRefInputTitle($form, $srcColumnId, $inputPrefix);
		$srcTableId = $srcTable->tableId ();
		$ip = str_replace ('.', '_', $inputPrefix);

		$columnInputClass = 'e10-inputNdx';
		if ($options & DataModel::coSaveOnChange)
			$columnInputClass .= ' e10-ino-saveOnChange';

		$class = 'e10-inputReference';
		if ($options & TableForm::coInfoText)
			$class .= ' infoText';
		if ($options & TableForm::coHeader)
			$class .= ' header';

		$editInputParams = '';
		$editInputClass = '';
		$inputParams = '';
		if ($options & TableForm::coReadOnly)
			$inputParams = " readonly='readonly'";
		if ($options & TableForm::coFocus)
		{
			$editInputParams = " autofocus='autofocus'";
			$editInputClass = ' autofocus';
		}
		$inputCode  = '';
		$inputCode .= "<div id='{$form->fid}_refinp_$ip{$srcColumnId}' class='$class'$inputParams>";
		$inputCode .= "<input type='hidden' name='$inputPrefix{$srcColumnId}' id='{$form->fid}_inp_$ip{$srcColumnId}' class='$columnInputClass' data-column='$srcColumnId' data-fid='{$form->fid}'/>";

		if (!($options & TableForm::coInfoText))
			$inputCode .= "<input type='text' name='$inputPrefix{$srcColumnId}' id='{$form->fid}_inp_refid_$ip{$srcColumnId}' class='e10-inputRefId e10-viewer-search$editInputClass' data-column='$srcColumnId' data-srctable='$srcTableId' data-sid='{$form->fid}Sidebar' autocomplete='off'$inputParams$editInputParams/>";

		$inputCode .= "<span class='btns' style='display:none;'>";
		if (!($options & TableForm::coReadOnly) && !($options & TableForm::coInfoText))
		{
			$inputCode .= $this->app()->ui()->icon('system/actionClose', 'e10-inputReference-clearItem').'&nbsp;';
			$inputCode .= $this->app()->ui()->icon('system/actionOpen', 'e10-inputReference-editItem', 'i', " data-table='$thisTableId' data-pk='0'").'&nbsp;';
		}
		$inputCode .= "</span>";

		$inputCode .= "<span class='e10-refinp-infotext'>" .$this->app()->ui()->composeTextLine($refTitle) . '</span>';

		//e10-inputRefId
		$inputCode .= '</div>';

		$info ['widgetCode'] = NULL;

		$info ['inputCode'] = $inputCode;
		$info ['labelCode'] = NULL;
		$labelClass = '';
		if ($form->activeLayout === TableForm::ltGrid)
			$labelClass = " class='gll'";
		if ($label)
			$info ['labelCode'] = "<label for='inp_refid_$ip{$srcColumnId}'$labelClass>" . utils::es ($label) . "</label>";

		return $info;
	}

	function copyDocument ($srcPK, $copyMode)
	{
		$srcRecData = $this->loadItem($srcPK);
		$recData = $this->copyDocumentRecord ($srcRecData);

		// -- documens state
		if (isset($recData['_fixedDocState']))
		{
			unset($recData['_fixedDocState']);
		}
		else
		{
			$docStates = $this->documentStates($recData);
			if ($docStates)
			{
				$stateColumn = $docStates ['stateColumn'];
				$setDocState = key($docStates ['states']);
				$recData [$stateColumn] = $setDocState;

				$mainStateColumn = $docStates ['mainStateColumn'];
				$newMainState = $docStates ['states'][$setDocState]['mainState'];
				$recData [$mainStateColumn] = $newMainState;
			}
		}

		// -- insert head
		$dstPK = $this->dbInsertRec($recData);
		$recData = $this->loadItem($dstPK);

		// -- copy lists
		$this->copyDocumentLists ($srcRecData, $recData);

		// -- copy attachments
		if ($copyMode === 2)
		{
			Attachments::copyAttachments ($this->app(), 'e10doc.core.heads', $srcPK, 'e10doc.core.heads', $dstPK);
		}

		return $dstPK;
	}

	public function copyDocumentLists ($srcRecData, $dstRecData)
	{
		$lists = array ();

		$listDefs = $this->listDefinition (NULL);
		if ($listDefs)
		{
			forEach ($listDefs as $listId => $list)
			{
				$listDefinition = $this->listDefinition ($listId);
				$listObject = $this->app()->createObject ($listDefinition ['class']);
				if (method_exists($listObject, 'copyDocumentList')) // TODO: better lists implementation
				{
					$listObject->setRecData ($this, $listId, $srcRecData);
					$listObject->copyDocumentList ($srcRecData, $dstRecData);
				}
			}
		}
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$dstRecData = array_merge($srcRecData);
		unset ($dstRecData['ndx']);

		return $dstRecData;
	}

	public function setColumns (&$recData, $cfg, $itemName = 'setColumns')
	{
		if (isset ($cfg[$itemName]))
		{
			forEach ($cfg[$itemName] as $setColId => $setColVal)
				$recData[$setColId] = $setColVal;
		}
		else
			if ($itemName === '')
			{
				forEach ($cfg as $setColId => $setColVal)
					$recData[$setColId] = $setColVal;
			}
	}

	static function dateIsBlank ($d)
	{ // TODO: remove
		if ((!isset ($d)) || ($d == NULL) || ($d == '0000-00-00'))
			return TRUE;
		return FALSE;
	}

	public function documentCard ($recData, $objectType)
	{
		$o = NULL;

		$classId = $this->app()->model()->tableProperty ($this, 'documentCard');
		if ($classId === FALSE)
			$o = new \Shipard\Base\DocumentCard($this->app());
		else
			$o =  $this->app()->createObject($classId);

		if ($o)
			$o->dstObjectType = $objectType;

		return $o;
	}

	public function dbDeleteRec ($recData)
	{
		$trash = $this->app()->model()->tableProperty ($this, 'trash');
		if ($trash != FALSE)
		{
			$trashColumn = $trash ['column'];
			if (isset ($trash ['value']))
				$trashValue = $trash ['value'];
			else
				$trashValue = 1;

			if (is_int ($trashValue))
				$this->dbmodel->db->query ("UPDATE [{$this->sqlName()}] SET [$trashColumn] = %i WHERE [ndx] = %i", $trashValue, $recData ['ndx']);
			else
				$this->dbmodel->db->query ("UPDATE [{$this->sqlName()}] SET [$trashColumn] = %s WHERE [ndx] = %i", $trashValue, $recData ['ndx']);

			$item = $this->loadItem ($recData ['ndx']);
			$this->checkAfterSave2 ($item);
		}
		else
		{
			//error_log ("ERROR: DELETE RECORD!");
			$this->db()->query ('DELETE FROM ['.$this->sqlName().'] WHERE [ndx] = %i', $recData ['ndx']);
		}
	}

	public function dbUndeleteRec ($recData)
	{
		$trash = $this->app()->model()->tableProperty ($this, 'trash');
		if ($trash != FALSE)
		{
			$trashColumn = $trash ['column'];
			if (isset ($trash ['undeleteValue']))
				$trashValue = $trash ['undeleteValue'];
			else
				$trashValue = 0;

			if (is_int ($trashValue))
				$this->dbmodel->db->query ("UPDATE [{$this->sqlName()}] SET [$trashColumn] = %i WHERE [ndx] = %i", $trashValue, $recData ['ndx']);
			else
				$this->dbmodel->db->query ("UPDATE [{$this->sqlName()}] SET [$trashColumn] = %s WHERE [ndx] = %i", $trashValue, $recData ['ndx']);

			$item = $this->loadItem ($recData ['ndx']);
			$this->checkAfterSave2 ($item);
		}
		else
		{
			error_log ("ERROR: UNDELETE RECORD!");
		}
	}


	public function dbInsert ($sql)
	{
		$q = $this->dbmodel->db->query($sql);
		$ndx = $this->dbmodel->db->getInsertId ();
		return intval ($ndx);
	}

	public function dbInsertRec (&$recData, $ownerData = NULL)
	{
		$addPicture = FALSE;
		if (isset ($recData['_addPicture']))
		{
			$addPicture = $recData['_addPicture'];
			unset ($recData['_addPicture']);
		}

		$this->checkBeforeSave ($recData, $ownerData);

		try
		{
			$this->dbmodel->db->query ("INSERT INTO [{$this->sqlName()}]", $recData);
		}
		catch (\Dibi\DriverException $e)
		{
			error_log (get_class($e) . ': ' . $e->getSql());
		}
		$newNdx = intval ($this->dbmodel->db->getInsertId ());
		$recData ['ndx'] = $newNdx;
		$this->checkAfterSave ($recData);

		//error_log ("########" . $this->dbmodel->db->sql);

		if ($addPicture !== FALSE && $addPicture != '')
		{
			$path_parts = pathinfo ($addPicture);
			$newAtt = [
				'tableid' => $this->tableId(), 'recid' => $newNdx, 'atttype' => 'campic', 'name' => $path_parts ['filename'],
				'path' => $addPicture, 'filename' => '', 'filetype' => $path_parts ['extension'],
				'attplace' => /* TableAttachments::apRemote */ 2, 'created' => new \DateTime()
			];
			$this->dbmodel->db->query ("INSERT INTO [e10_attachments_files]", $newAtt);
		}

		if (isset ($recData ['ndx']) && is_string ($recData ['ndx']))
			return $recData ['ndx'];

		return $newNdx;
	}


	public function dbUpdate ($sql)
	{
		$q = $this->dbmodel->db->query ($sql);
	}


	public function dbUpdateRec (&$recData, $ownerData = NULL)
	{
		$addPicture = FALSE;
		if (isset ($recData['_addPicture']))
		{
			$addPicture = $recData['_addPicture'];
			unset ($recData['_addPicture']);
		}

		$this->checkBeforeSave ($recData, $ownerData);
		try {
			if (is_int ($recData ['ndx']))
				$this->dbmodel->db->query ("UPDATE [{$this->sqlName()}] SET ", $recData, "WHERE [ndx] = %i", $recData ['ndx']);
			else
				$this->dbmodel->db->query ("UPDATE [{$this->sqlName()}] SET ", $recData, "WHERE [ndx] = %s", $recData ['ndx']);
		}
		catch (DibiDriverException $e)
		{
			error_log (get_class($e) . ': ' . $e->getSql());
		}
		$this->checkAfterSave ($recData);

		if ($addPicture !== FALSE && $addPicture != '')
		{
			$path_parts = pathinfo ($addPicture);
			$newAtt = [
				'tableid' => $this->tableId(), 'recid' => $recData ['ndx'], 'atttype' => 'campic', 'name' => $path_parts ['filename'],
				'path' => $addPicture, 'filename' => '', 'filetype' => $path_parts ['extension'],
				'attplace' => /* TableAttachments::apRemote */ 2, 'created' => new \DateTime()
			];
			$this->dbmodel->db->query ("INSERT INTO [e10_attachments_files]", $newAtt);
		}

		return $recData ['ndx'];
	}

	public function checkAfterSave (&$recData)
	{
	}

	public function checkAfterSave2 (&$recData)
	{
		$tableOptions = $this->app()->model()->tableProperty ($this, 'options');

		if ($tableOptions & DataModel::toConfigSource)
		{
			$dirtyFileName = __APP_DIR__ . '/config/configIsDirty.txt';
			if (!is_file ($dirtyFileName))
				file_put_contents ($dirtyFileName, '1');
		}
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		forEach ($recData as $key => $value)
		{
			$col = $this->column ($key);
			if (!$col)
			{
				//error_log ("---INVALID-COL `".$this->tableId()."` / $key`: ".json_encode($recData));
				continue;
			}
			switch ($col ['type'])
			{
				case DataModel::ctDate:
					if ($value == '0000-00-00')
						$recData [$key] = NULL;
					break;
				case DataModel::ctEnumInt:
				case DataModel::ctInt:
				case DataModel::ctShort:
					if ($value === '')
						$recData [$key] = 0;
					break;
			}
		}
	}

	protected function checkChangedInput ($changedInput, &$saveData){}

	public function checkDocumentState (&$recData)
	{
	}

	public function checkDefaultValue (&$recData, $column, $defaultValue)
	{
		if (!isset ($recData[$column]))
			$recData[$column] = $defaultValue;
	}

	public function checkNewRec (&$recData)
	{
		// -- add params
		foreach ($_GET as $c => $v)
		{
			if (strpos ($c, "__") !== 0)
				continue;
			$colName = substr ($c, 2);
			$colDef = $this->column ($colName);

			if (!$colDef)
			{
				$recData [$colName] = $v;
				continue;
			}

			switch ($colDef ['type'])
			{
				case DataModel::ctDate:
					if (is_string ($v))
					{
						//if ($d == '0000-00-00')
						//	return NULL;
						$recData [$colName] = new \DateTime ($v);
					}
					else
						$recData [$colName] = $v;
					break;
				default:
					$recData [$colName] = $v;
			}
		}

		// -- document state
		$docStates = $this->documentStates ($recData);
		if ($docStates)
		{
			$stateColumn = $docStates ['stateColumn'];
			if ((!isset($recData [$stateColumn])) || (!$recData [$stateColumn]))
			{
				$setDocState = key ($docStates ['states']);
				$recData [$stateColumn] = $setDocState;

				$mainStateColumn = $docStates ['mainStateColumn'];
				$newMainState = $docStates ['states'][$setDocState]['mainState'];
				$recData [$mainStateColumn] = $newMainState;
			}
		}
	}

	public function createNewDoc($recData)
	{
		return ['ndx' => 0];
	}

	public function checkDocumentPropertiesList (&$properties, $recData) {}

	public function checkSaveData (&$saveData, &$saveResult)
	{
		if (!isset ($saveData['saveOptions']))
			return;

		$saveOptions = $saveData['saveOptions'];
		if (isset ($saveOptions['appendRowList']))
		{
			if (isset($saveOptions['appendBlankRow']))
			{
				$listDefinition = $this->listDefinition ($saveOptions['appendRowList']);
				$newRow = [];
				if (isset($saveOptions['rowOrder']) && isset($listDefinition ['orderColumn']))
					$newRow [$listDefinition ['orderColumn']] = intval($saveOptions['rowOrder']);
				$saveData ['lists'][$saveOptions['appendRowList']][] = $newRow;
			}
		}
	}

	public function createUploadDocument ($id, $baseFileName)
	{
		return 0;
	}

	function disabledDetails ($viewerId, $detailId, $recData){return NULL;}

	public function docsLog ($ndx)
	{
		$recData = $this->loadItem ($ndx);

		$docStates = $this->documentStates ($recData);
		if (!$docStates)
			return NULL;

		$stateColumn = $docStates ['stateColumn'];
		$recInfo = $this->getRecordInfo ($recData);
		$ipAddr = (isset($_SERVER ['REMOTE_ADDR'])) ? $_SERVER ['REMOTE_ADDR'] : '0.0.0.0';
		$logEvent = array ('tableid' => $this->tableId, 'recid' => $ndx, 'docState' => $recData [$stateColumn],
			'eventTitle' => Str::upToLen($recInfo ['title'], 180), 'created' => utils::now(),
			'ipaddress' => $ipAddr, 'deviceId' => $this->app()->deviceId);

		$logEvent ['user'] = $this->app()->user()->data ('id');

		if (isset ($recInfo['project']))
			$logEvent['project'] = $recInfo['project'];

		if (isset ($recInfo['docID']) && strlen ($recInfo['docID']) <= 25)
			$logEvent['docID'] = $recInfo['docID'];

		if (isset ($recInfo['docType']))
			$logEvent['docType'] = $recInfo['docType'];

		if (isset ($recInfo['docTypeName']))
			$logEvent['docTypeName'] = $recInfo['docTypeName'];

		if (isset ($recInfo['recidOwner']))
			$logEvent['recidOwner'] = $recInfo['recidOwner'];

		$logData = [
			'recData' => $recData,
			'lists' => $this->loadLists ($recData)
		];
		Json::polish($logData['recData']);
		$logEvent ['eventData'] = json_encode ($logData);

		$this->dbmodel->db->query ('INSERT INTO [e10_base_docslog]', $logEvent);

		return $recData;
	}

	public function fetchAll ($sql)
	{
		$queryRows = $this->app()->db->query ($sql);
		return $queryRows;
	}

	public function formId ($recData, $ownerRecData = NULL, $operation = 'edit')
	{
		if ($this->app()->testGetParam ('formId') != '')
			return $this->app()->testGetParam ('formId');

		if ($operation === 'show')
			return 'show';

		return 'default';
	}

	public function formDefinition ($formId)
	{
		return $this->app()->model()->formDefinition ($this->tableId, $formId);
	}

	public function setName ($tableId, $sqlName, $humanName, $ndx = 0)
	{
		$this->humanName = $humanName;
		$this->tableId = $tableId;
		$this->sqlName = $sqlName;
		$this->ndx = $ndx;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return 'tables/'.$this->tableId;
	}

	public function tableId ()
	{
		return $this->tableId;
	}

	public function tableName ()
	{
		return $this->humanName;
	}

	public function timeline ($recData, $options = NULL)
	{
		return FALSE;
	}

	public function sqlName ()
	{
		return $this->sqlName;
	}

	public function getDetailData ($viewId, $detailId, $pk)
	{
		$vd = $this->viewDefinition ($viewId);
		if (!$vd)
			$vd = $this->viewDefinition ('default');

		$recData = $this->loadItem ($pk);
		$disabledDetails = $this->disabledDetails($viewId, $detailId, $recData);
		if ($disabledDetails !== NULL)
		{
			if (isset($disabledDetails['activate']))
				$detailId = $disabledDetails['activate'];
		}

		$detailDefinition = NULL;
		if (isset ($vd ['detail']) && $detailId === 'default')
			$detailDefinition = $vd ['detail'];
		else
		if (isset ($vd ['details'][$detailId]))
		{
			$detailDefinition = $vd ['details'][$detailId]['class'];
		}
		else
		{
			$gd = $this->app()->cfgItem('e10.global.viewerDetails', FALSE);
			if (isset ($gd[$detailId]))
				$detailDefinition = $gd[$detailId]['class'];
		}

		if (strstr ($detailId, '.') !== FALSE)
			$detailDefinition = $detailId;

		$detailClass = $detailDefinition;

		$this->loadModuleFile ($detailClass);
		$className = str_replace ('.', '\\', $detailClass);
		$detailData = new $className ($this, $viewId);
		$detailData->detailId = $detailId;

		$detailData->item = $recData;
		$detailData->ok = 1;
		if ($disabledDetails !== NULL)
			$detailData->objectData ['disabledDetails'] = $disabledDetails;

		return $detailData;
	}

	public function getListData ($listId, $listOp, $pk)
	{
		$listData = new ListData ($this, $listId, $listOp);
		$listData->finish ($pk);
		$listData->ok = 1;
		return $listData;
	}

	public function getViewerPanel ($viewId, $panelId)
	{
		$viewer = $this->getTableView ($viewId);
		$panel = $viewer->panel ($panelId);
		return $panel;
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$info = array ();

		$autocomplete = $this->app()->model()->tableProperty ($this, 'autocomplete');
		if ($autocomplete)
		{
			$titleColumn = $autocomplete ['columnTitle'];
			$info ['title'] = $recData[$titleColumn];
		}

		if (!isset ($info ['docID']))
		{
			if (isset ($recData['id']) && $recData['id'] != '')
				$info['docID'] = $recData['id'];
			else
				$info['docID'] = '#'.$recData['ndx'];
		}

		if (!isset ($info ['title']))
			$info ['title'] = '';

		return $info;
	}

	public function getReportData ($reportClass, $pk)
	{
		$item = $this->loadItem ($pk);
		$this->loadModuleFile ($reportClass);
		$className = str_replace ('.', '\\', $reportClass);
		$report = new $className ($this, $item);
		$report->init ();

		return $report;
	}

	public function getReports ($recData)
	{
		$reports = $this->app()->model()->tableProperty ($this, 'reports');
		return $reports;
	}

	public function getDocActions ($recData)
	{
		$docActions = $this->app()->model()->tableProperty ($this, 'docActions');
		return $docActions;
	}

	public function getDiaryInfo($recData)
	{
		return [];
	}

	public function saveExtraData (&$recData, $extraData)
	{
		foreach ($extraData as $id => $values)
		{
			switch ($id)
			{
				case 'clsf':
					foreach ($values as $group => $items)
					{
						$groupParts = explode(':', $group);
						$groupId = $groupParts[0];
						foreach ($items as $itemNdx => $itemValue)
						{
							$vp = explode(':', $itemValue);
							if (count($vp) === 1)
								continue;
							$this->db()->query("INSERT INTO [e10_base_clsf] ([tableid], [recid], [clsfItem], [group]) VALUES (%s, %i, %s, %s)",
								$vp[0], isset($vp[1]) ? intval($vp[1]) : 0, $itemNdx, $groupId);
						}
					}
					break;
			}
		}
	}

	function applySubColumnsData (&$saveData)
	{
		if (!isset($saveData['subColumns']))
			return;

		foreach ($saveData['subColumns'] as $columnId => $subColumns)
		{
			if (isset($saveData['recData'][$columnId]))
				$data = json_decode ($saveData['recData'][$columnId], TRUE);
			else
				$data = [];
			if (!is_array($data))
				$data = [];

			foreach ($subColumns as $key => $value)
			{
				$data[$key] = $value;
			}
			$sci = $this->subColumnsInfo($saveData ['recData'], $columnId);
			if ($sci !== FALSE)
				$this->app()->subColumnsCalc($data, $sci);

			if ($sci)
			{
				$dataClean = [];
				foreach ($sci['columns'] as $colCfg)
				{
					$key = $colCfg['id'];

					if (isset($data[$key]))
						$dataClean[$key] = $data[$key];
				}
				$saveData['recData'][$columnId] = json_encode($dataClean);
			}
			else
				$saveData['recData'][$columnId] = json_encode($data);
		}
	}

	public function createSubColumnsData ($recData)
	{
		$scd = [];

		$columns = $this->columns();
		foreach ($columns as $columnId => $column)
		{
			if ($column['type'] !== DataModel::ctSubColumns)
				continue;

			if (isset($recData[$columnId]))
				$data = json_decode ($recData[$columnId], TRUE);
			else
				$data = [];
			if (!is_array($data))
				$data = [];

			$scd[$columnId] = $data;

			foreach ($data as $key => $value)
			{
				$scd[$columnId][$key] = $value;
			}

			if (isset($scd[$columnId]))
				json::polish($scd[$columnId]);
		}

		if (count($scd))
		{
			return $scd;
		}

		return FALSE;
	}

	public function saveFormData (TableForm &$formData, $saveData = NULL)
	{
		if ($saveData === NULL)
		{
			$data = $this->app->testGetData();
			$saveData = json_decode ($data, TRUE);
		}

		$setDocState = isset ($saveData ['setDocState']) ? $saveData ['setDocState'] : 0;
		if ($setDocState === 99000)
		{
			$this->app()->notificationsClear ($this->tableId(), $saveData ['recData']['ndx']);

			$formData->flags['reloadNotifications'] = 1;
			return;
		}

		$this->app()->db->begin();
		$needLog = 0;
		$ds = FALSE;

		if (isset ($saveData['postData']))
			$formData->postData = $saveData['postData'];

		$this->applySubColumnsData ($saveData);

		if (isset ($saveData['changedInput']))
			$this->checkChangedInput ($saveData['changedInput'], $saveData);

		// prepare document state
		if ($setDocState)
		{
			$docStates = $this->documentStates ($saveData ['recData']);
			if ($docStates)
			{
				if ($formData->validNewDocumentState($setDocState, $saveData))
				{
					$stateColumn = $docStates ['stateColumn'];

					$mainStateColumn = $docStates ['mainStateColumn'];
					$newMainState = $docStates ['states'][$setDocState]['mainState'];
					$saveData ['recData'][$stateColumn] = $setDocState;

					$saveData ['recData'][$mainStateColumn] = $newMainState;
					$ds = $docStates['states'][$setDocState];
					$this->setColumns($saveData ['recData'], $ds);

					$this->checkDocumentState ($saveData ['recData']);
					$needLog = 1;
				}
			}
		}

		//error_log ('#### setDocState: ' . $saveData ['setDocState']);
		$formData->checkBeforeSave($saveData);

		// -- insert/update
		if ($saveData ['documentPhase'] == "insert")
		{
			$this->checkSaveData ($saveData, $formData->saveResult);
			$pk = $this->dbInsertRec ($saveData ['recData']);
			$formData->recData = $this->loadItem ($pk);
			$needLog = 1;
		}
		else
		{
			$this->checkSaveData ($saveData, $formData->saveResult);
			$pk = $this->dbUpdateRec ($saveData ['recData']);
			$formData->recData = $this->loadItem ($pk);
		}

		// lists
		if (isset ($saveData ['lists']))
		{
			forEach ($saveData ['lists'] as $listId => $listData)
			{
				$listDefinition = $this->listDefinition ($listId);
				$listObject = $this->app()->createObject ($listDefinition ['class']);
				$listObject->setRecord ($listId, $formData);
				$listObject->saveData ($listData);
			}
		}

		// -- check after save
		if ($formData->checkAfterSave())
			$this->dbUpdateRec ($formData->recData);

		// -- save extra data
		if (isset ($saveData['extra']))
			$this->saveExtraData($formData->recData, $saveData['extra']);

		// check after save - table mode
		$this->checkAfterSave2 ($formData->recData);

		// -- save event to log
		if ($needLog)
		{
			$this->docsLog ($pk);
		}

		$this->app()->db->commit();

		// -- print after confirm
		if ($ds !== FALSE && isset ($ds['printAfterConfirm']))
		{
			$printCfg = [];
			$this->printAfterConfirm ($printCfg, $formData->recData, $ds, $saveData);
		}
	} // saveFormData


	public function listDefinition ($listId)
	{
		return $this->app()->model()->listDefinition ($this->tableId, $listId);
	}

	public function loadDocsList ($recs)
	{
		$l = array ();

		$recs = implode (', ', $recs);
		$q = "SELECT * FROM [{$this->sqlName}] WHERE [ndx] IN ($recs)";
		$rows = $this->dbmodel->db->query ($q);

		forEach ($rows as $r)
			$l [$r['ndx']] = $this->getRecordInfo ($r);

		return $l;
	}

	public function loadItem ($ndx, $table = NULL)
	{
		$q = 'SELECT * FROM [' . (($table) ? $table : $this->sqlName) . '] WHERE [ndx] = %i';
		$r = $this->dbmodel->db->query ($q, $ndx);
		$item = $r->fetch ();
		if (!$item)
			return FALSE;
		return $item->toArray();
	}

	public function loadRecData($ndx)
	{
		$pk = $this->primaryKey($ndx);
		if ($pk)
			return $this->loadItem($pk);

		return FALSE;
	}

	public function loadLists ($recData)
	{
		$lists = array ();

		$listDefs = $this->listDefinition (NULL);
		if ($listDefs)
		{
			forEach ($listDefs as $listId => $list)
			{
				$listDefinition = $this->listDefinition ($listId);
				$listObject = $this->app()->createObject ($listDefinition ['class']);
				$listObject->setRecData ($this, $listId, $recData);
				$listObject->loadData ();
				$lists [$listId] = $listObject->data;
			}
		}
		return $lists;
	}

	public function primaryKey($ndx)
	{
		if (!is_string($ndx))
			return intval($ndx);

		if ($ndx === '' || $ndx[0] !== '@')
			return intval($ndx);

		$searchViaColId = 'id';

		$searchValue = substr($ndx, 1);
		$valueParts = explode(':', $searchValue);
		if (count($valueParts) >= 2)
		{
			$searchViaColId = $valueParts[0];
			$searchValue = substr($ndx, strlen($searchViaColId) + 2);
		}

		$colInfo = $this->column($searchViaColId);
		if (!$colInfo)
			return 0;

		$rec = $this->db()->query ("SELECT ndx FROM [{$this->sqlName ()}] WHERE [$searchViaColId] = %s", $searchValue)->fetch();
		if (!$rec || !isset($rec['ndx']))
			return 0;

		return $rec['ndx'];
	}

	public function ownerRecData ($recData, $suggestedOwnerRecData = NULL)
	{
		return NULL;
	}

	public function printAfterConfirm (&$printCfg, &$recData, $docState, $saveData = NULL)
	{
	}

	public function renderDetailData ($detailData)
	{
		// TODO: remove
	}

	public function renderReportData (ReportData &$reportData)
	{
		$reportData->setCode ('');
	}

	public function loadModuleFile ($class)
	{
		if (strstr ($class, '.') !== FALSE)
		{
			$parts = explode ('.', $class);
			$className = array_pop ($parts);
			$moduleFileName = __SHPD_MODULES_DIR__ . strtolower(implode ('/', $parts)) . '/' . end($parts) . '.php';
			if (is_file ($moduleFileName))
				include_once ($moduleFileName);
		}
	}

	public function getPrintValues ($item)
	{
		$pi = array ();
		forEach ($item as $key => $value)
		{
			$col = $this->column ($key);
			if (!$col)
			{
				$pi [$key] = $value;
				continue;
			}

			switch ($col ['type'])
			{
				case DataModel::ctEnumString:
				case DataModel::ctEnumInt:
					$values = $this->columnInfoEnum ($key, 'cfgPrint');
					if (isset ($values [$value]))
						$pi [$key] = $values [$value];
					else
						$pi [$key] = '';
					break;
				case DataModel::ctMoney:
					$pi [$key] = utils::nf ($value, 2);
					break;
				default:
					$pi [$key] = $value;
					break;
					break;
			}
		}
		return $pi;
	}


	public function getTableForm ($formOp, $pkParam, $columnValues = NULL)
	{
		$pk = $this->primaryKey($pkParam);

		$recData = [];
		if ($columnValues !== NULL)
			$this->setColumns($recData, $columnValues, '');
		$copyDoc = intval ($this->app->testGetParam('copyDoc'));
		switch ($formOp)
		{
			case 'new':
				$focusedPK = intval ($this->app->testGetParam('focusedPK'));
				if ($copyDoc)
				{
					$pk = $this->copyDocument ($focusedPK, $copyDoc);
					$recData = $this->loadItem ($pk);
				}
				elseif ($this->app()->testGetParam('createDoc') !== '')
				{
					$recData = $this->createNewDoc($recData);
					$pk = $recData['ndx'];
				}
				else
					$this->checkNewRec ($recData);
				break;
			case 'edit':
			case 'show':
			case 'delete':
			case 'undelete':
			case 'listappend':
			case 'report':
				if ($pk != "")
					$recData = $this->loadItem ($pk);
				break;
			case 'sidebar':
				$data = $this->app->testGetData();
				$saveData = json_decode ($data, TRUE);
				if (isset ($saveData ['recData']))
					$recData = $saveData ['recData'];
				break;
			case 'save':
				$data = $this->app->testGetData();
				$saveData = json_decode ($data, TRUE);
				if (isset ($saveData ['recData']))
					$recData = $saveData ['recData'];
				else
					$recData = $saveData; // OBSOLETE!
				break;
		}

		$formId = $this->formId ($recData, NULL, $formOp);

		$f = NULL;
		$fd = $this->formDefinition ($formId);
		if ($fd)
		{
			if (isset ($fd ['class']))
			{
				$this->loadModuleFile ($fd ['class']);
				$className = str_replace ('.', '\\', $fd ['class']);
				$f = new $className ($this, $formId, $formOp);
			}
		}
		else
			$f = new TableForm ($this, $formId, $formOp);

		if (isset($saveData))
			$f->setRecData ($saveData);
		else
			$f->setRecData ($recData);
		$f->copyDoc = $copyDoc;

		if ((is_numeric($pk) && $pk !== 0) || (is_string($pk) && $pk !== ''))
		{
			$f->documentPhase = "update";
		}
		return $f;
	}


	public function getListForm ($recData, $ownerRecData)
	{
		$formId = $this->formId ($recData, $ownerRecData);

		$f = NULL;
		$fd = $this->formDefinition ($formId);
		if ($fd)
		{
			if (isset ($fd ['class']))
			{
				$this->loadModuleFile ($fd ['class']);
				$className = str_replace ('.', '\\', $fd ['class']);
				$f = new $className ($this, $formId, 'edit');
			}
		}
		else
			$f = new TableForm ($this, $formId, 'edit');

		$f->recData = $recData;
		return $f;
	}


	public function getTableView ($viewId, $queryParams = NULL)
	{
		$v = NULL;

		$viewClass = '';
		$vd = NULL;

		if (strstr ($viewId, '.') == FALSE)
		{
			$vd = $this->viewDefinition ($viewId);
			if (!$vd)
				$vd = $this->viewDefinition ('default');
			if ($vd)
				$viewClass = $vd ['class'];
		}
		else
			$viewClass = $viewId;

		$this->loadModuleFile ($viewClass);
		$className = str_replace ('.', '\\', $viewClass);
		$v = new $className ($this, $viewId, $queryParams);

		if ($v)
		{
			$v->viewerDefinition = $vd;
			if ($v->rowsPageNumber !== -1)
			{
				$v->init ();
				$v->selectRows ();
			}
			else $v->ok = 1;
		}
		return $v;
	}

	public function reportId ($recData)
	{
		return 'default';
	}


	static function nf($number, $decimals=0)
	{
		return number_format($number, $decimals, ',', ' ');
	}

	public function viewDefinition ($viewId)
	{
		return $this->app()->model()->viewDefinition ($this->tableId, $viewId);
	}

	public function documentStates ($recData)
	{
		$states = $this->app()->model()->tableProperty ($this, 'states');
		if ($states)
			$states ['states'] = $this->app()->cfgItem ($states ['statesCfg']);
		return $states;
	}

	public function getDocumentState ($recData)
	{
		$states = $this->documentStates ($recData);
		if (!$states)
			return NULL;

		$stateColumn = $states ['stateColumn'];
		$stateValue = 0;

		$info = [];
		$info ['states'] = $states;

		if (isset ($recData [$stateColumn]))
		{
			$stateValue = $recData [$stateColumn];
			$info ['state'] = $states ['states'][$stateValue];
			$info ['readOnly'] = isset ($states ['states'][$stateValue]['readOnly']) ? $states ['states'][$stateValue]['readOnly'] : 0;
		}

		return $info;
	}

	public function getDocumentStateInfo ($states, $recData, $key)
	{
		$stateColumn = $states ['stateColumn'];
		if (isset ($recData [$stateColumn]))
			$stateValue = $recData [$stateColumn];
		else
			if (isset ($recData ['docState']))
				$stateValue = $recData ['docState'];
			else return NULL;

		switch ($key)
		{
			case	'styleClass':
				return 'e10-docstyle-'. $states ['states'][$stateValue]['stateStyle'];
			case	'enablePrint':
				return isset ($states ['states'][$stateValue]['enablePrint']) ? $states ['states'][$stateValue]['enablePrint'] : 0;
			case	'notify':
				return isset ($states ['states'][$stateValue][$key]) ? $states ['states'][$stateValue][$key] : 0;
			case	'style':
				return $states ['states'][$stateValue]['stateStyle'];
			case	'name':
				return $states ['states'][$stateValue]['stateName'];
			case	'logName':
				if (isset ($states ['states'][$stateValue]['logName']))
					return $states ['states'][$stateValue]['logName'];
				return FALSE;
			case	'styleIcon':
				if (isset($states ['states'][$stateValue]['icon']))
					return $states ['states'][$stateValue]['icon'];
				switch ($states ['states'][$stateValue]['stateStyle'])
				{
					case 'archive': return 'system/docStateArchive';
					case 'concept': return 'system/docStateConcept';
					case 'new': return 'system/docStateNew';
					case 'delete': return 'system/docStateDelete';
					case 'cancel': return 'system/docStateCancel';
					case 'edit': return 'system/docStateEdit';
					case 'done': return 'system/docStateDone';
					case 'halfdone': return 'system/docStateHalfDone';
					case 'confirmed': return 'system/docStateConfirmed';
					default: return 'system/docStateUnknown';
				}
				break;
		}
		return NULL;
	}

	public function getDocumentLockState ($recData, $form = NULL)
	{
		$tableOptions = $this->app()->model()->tableProperty ($this, 'options');
		if ($tableOptions & DataModel::toSystemTable)
			return FALSE;

		$accessLevel = $this->checkAccessToDocument ($recData);
		if ($accessLevel === 0)
			return ['mainTitle' => 'Doklad nelze zobrazit', 'subTitle' => 'Nemáte práva k jeho prohlížení.', 'disableContent' => TRUE];
		if ($accessLevel === 1)
			return ['mainTitle' => 'Doklad je uzamčen', 'subTitle' => 'Nemáte práva k jeho editaci.'];
		return FALSE;
	}

	public function createHeader ($recData, $options)
	{
		$h = array ("icon" => $this->tableIcon ($recData, $options));

		return $h;
	}

	public function createPrintToolbar (&$toolbar, $recData)
	{
		$enablePrint = 1;
		$docState = $this->getDocumentState ($recData);
		if ($docState)
			$enablePrint = $this->getDocumentStateInfo ($docState ['states'], $recData, 'enablePrint');
		$reports = $this->getReports ($recData);

		if ($reports && $enablePrint)
		{
			forEach ($reports as $r)
			{
				if (isset ($r['role']) && !$this->app()->hasRole($r['role']))
					continue;
				if (isset ($r['roles']))
				{
					$showIt = FALSE;
					foreach($r['roles'] as $role)
						if ($this->app()->hasRole($role))
							$showIt = TRUE;
					if (!$showIt)
						continue;
				}
				if (isset ($r['queryCol']))
				{
					if (isset($r['queryColValue']) && $recData[$r['queryCol']] != $r['queryColValue'])
						continue;
					if (isset($r['queryColValues']) && !in_array($recData[$r['queryCol']], $r['queryColValues']))
						continue;
				}
				if (isset ($r['queryCols']))
				{
					$dsbl = FALSE;
					forEach ($r['queryCols'] as $qcid => $qcv)
					{
						if (is_string($qcv) && $recData[$qcid] != $qcv)
						{
							$dsbl = TRUE;
							break;
						}
						if (is_array($qcv) && !in_array($recData[$qcid], $qcv))
						{
							$dsbl = TRUE;
							break;
						}
					}
					if ($dsbl)
						continue;
				}

				$printer = '0';
				$printerClass = 'default';
				if (isset($r['printerClass']))
					$printerClass = $r['printerClass'];

				if (isset ($r['directPrint']) && $r['directPrint'])
				{
					$workplace = $this->app()->workplace;
					if ($workplace === FALSE)
						continue;
					if (isset($r['printerClass']) && !$this->app()->workplace['printers'][$printerClass])
						continue;
					if (isset ($workplace['printers']) && isset ($workplace['printers'][$printerClass]))
						$printer = $workplace['printers'][$printerClass];
					$btn = ['type' => 'action', 'action' => 'printdirect', 'style' => 'printdirect', 'text' => $r ['name'], 'data-report' => $r ['class']];
				}
				else
					$btn = ['type' => 'action', 'action' => 'print', 'style' => 'print', 'text' => $r ['name'], 'data-report' => $r ['class']];

				$btn ['data-printer'] = $printer;
				$btn ['data-table'] = $this->tableId();
				$btn ['data-pk'] = $recData['ndx'];

				if (utils::param($r, 'email', 0))
				{
					$btn['subButtons'] = [];
					$btn['subButtons'][] = [
						'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconEmail', 'title' => 'Odeslat emailem',
						'data-table' => $this->tableId(), 'data-pk' => $recData['ndx'], 'data-class' => 'Shipard.Report.SendFormReportWizard',
						'data-addparams' => 'reportClass='.$r ['class'].'&documentTable='.$this->tableId(), 'btnClass' => 'btn-default'
					];
				}
				if (utils::param($r, 'dropdown', 0))
				{
					$report = $this->getReportData ($r ['class'], $recData['ndx']);//;$this->app()->createObject ($r ['class']);
					$report->createToolbarSaveAs ($btn);
				}
				$toolbar [] = $btn;
			}
		}
	}

	public function createDocumentToolbar (&$toolbar, $recData)
	{
		$docActions = $this->getDocActions($recData);

		if ($docActions)
		{
			forEach ($docActions as $r)
			{
				if (isset ($r['role']) && !$this->app()->hasRole($r['role']))
					continue;
				if (isset ($r['queryCol']))
				{
					if (isset($r['queryColValue']) && $recData[$r['queryCol']] != $r['queryColValue'])
						continue;
					if (isset($r['queryColValues']) && !in_array($recData[$r['queryCol']], $r['queryColValues']))
						continue;
				}
				if (isset ($r['queryCols']))
				{
					$dsbl = FALSE;
					forEach ($r['queryCols'] as $qcid => $qcv)
					{
						if (is_string($qcv) && $recData[$qcid] != $qcv)
						{
							$dsbl = TRUE;
							break;
						}
						if (is_array($qcv) && !in_array($recData[$qcid], $qcv))
						{
							$dsbl = TRUE;
							break;
						}
					}
					if ($dsbl)
						continue;
				}

				$btn = ['type' => 'action', 'text' => $r ['title'], 'class' => $r ['class'], 'data-table' => $this->tableId(), 'data-pk' => $recData['ndx']];
				if (isset ($r['icon']))
					$btn['icon'] = $r['icon'];

				$btn['data-class'] = $r['class'];

				$btn['action'] = $r['action'];

				$toolbar [] = $btn;
			}
		}
	}

	function propertyEnabled ($recData, $groupId, $propertyId, $property, $loadedProperties)
	{
		return TRUE;
	}
}
