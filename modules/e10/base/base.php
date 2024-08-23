<?php


namespace e10\base;

require_once __SHPD_MODULES_DIR__ . 'e10/web/web.php';

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils, \E10\str, \E10\uiutils;
use \Shipard\Form\FormSidebar;
use \Shipard\Utils\Json;
use \Shipard\Application\DataModel;


/* ----------
 * Properties
 * ----------
 */


function getProperties ($app, $toTableId, $toGroupId, $toRecId)
{
	$properties = array ();
	$texy = new \E10\Web\E10Texy ($app);

	$listDefinition = $app->model()->listDefinition ($toTableId, 'properties');
	$allProperties = $app->cfgItem ('e10.base.properties', array());

	// --load from table
	$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND recid = %i ORDER BY ndx";
	$query = $app->db->query ($sql, $toTableId, $toRecId);
	foreach ($query as $row)
	{
		$loaded = false;
		$p = $allProperties [$row['property']];
		if (isset ($p ['type']))
		{
			if ($p ['type'] == 'memo')
			{
				$texy->setOwner ($row);
				$txt = $texy->process ($row ['valueMemo']);
				$properties [$row['property']][] = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $txt, 'note' => $row['note']);
				$loaded = true;
			}
			else
			if ($p ['type'] == 'date')
			{
				$dd = (utils::dateIsBlank($row ['valueDate'])) ? '' : $row ['valueDate']->format ('d.m.Y');
				$properties [$row['property']][] = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $dd, 'note' => $row['note']);
				$loaded = true;
			}
		}
		if (!$loaded)
			$properties [$row['property']][] = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'],
																								'subtype' => $row ['subtype'], 'value' => $row ['valueString'], 'note' => $row ['note']);
	}

	// --join unused properties
	$listDefinition = $app->model()->listDefinition ($toTableId, 'properties');
	$allProperties = $app->cfgItem ($listDefinition ['propertiesCfgList']);
	forEach ($allProperties as $pid => $val)
	{
		if (isset ($properties [$pid]))
			continue;
		$properties [$pid][] = array ('ndx' => 0, 'property' => $pid, 'group' => $toGroupId, 'value' => '', 'subtype' => '', 'note' => '');
	}

	return $properties;
}

function getPropertiesTable ($app, $toTableId, $toRecId)
{
	$multiple = FALSE;
	$texy = new \E10\Web\E10Texy ($app);

	if (is_array($toRecId))
	{
		if (!count($toRecId))
			return [];
		$multiple = TRUE;
		$recs = implode (', ', $toRecId);
		$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND [recid] IN ($recs) ORDER BY ndx";
	}
	else
	{
		$recId = intval($toRecId);
		$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND [recid] = $recId ORDER BY ndx";
	}

	$allProperties = $app->cfgItem ('e10.base.properties', array());
	$properties = array ();

	// --load from table
	$query = $app->db->query ($sql, $toTableId);
	foreach ($query as $row)
	{
		$loaded = false;
		$p = $allProperties [$row['property']];
		if (isset ($p ['type']))
		{
			if ($p ['type'] == 'memo')
			{
				if ($row ['valueMemo'] === NULL)
					continue;
				$texy->setOwner ($row);
				$txt = $texy->process ($row ['valueMemo']);
				$oneProp = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $txt, 'type' => 'memo', 'name' => $p ['name']);
				$loaded = true;
			}
			else
			if ($p ['type'] == 'date')
			{
				$oneProp = [
					'ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'],
					'value' => $row ['valueDate'], 'type' => 'memo', 'name' => $p ['name']
				];
				if ($row ['valueDate'])
					$oneProp['value'] = utils::datef($row ['valueDate'], '%d');
				$loaded = true;
			}
			else
			if ($p ['type'] == 'text')
			{
				$oneProp = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $row ['valueString'], 'type' => 'text', 'name' => $p ['name']);
				$loaded = true;
			}
			else
			if ($p ['type'] == 'enum')
			{
				$oneProp = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $p ['enum'][$row ['valueString']]['fullName'],
						'type' => 'enum', 'name' => $p ['name']);
				$loaded = true;
			}

			if ($loaded && $row ['note'] !== '')
				$oneProp ['note'] = $row ['note'];
		}
		if (!$loaded)
			$oneProp = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $p [$row ['valueString']], 'type' => 'string', 'name' => $p ['name']);

		if ($multiple)
			$properties [$row ['recid']][$row['group']][$row['property']][] = $oneProp;
		else
			$properties [$row['group']][$row['property']][] = $oneProp;
	}

	return $properties;
}

function getPropertiesDetailCode2 ($table, $recData, $tableClass='default itemParams')
{
	$result = array();

	$allProperties = $table->app()->cfgItem ('e10.base.properties', array());
	$allPropertiesGroups = $table->app()->cfgItem ('e10.base.propertiesGroups', array());
	$properties = \E10\Base\getPropertiesTable ($table->app (), $table->tableId(), $recData ['ndx']);

	$c = '';
	$c .= "<table class='$tableClass'>";
	forEach ($properties as $groupId => $groupContent)
	{
		$needCaption = TRUE;
		forEach ($groupContent as $propertyId => $propertyValues)
		{
			$p = $allProperties [$propertyId];
			if ($p ['type'] == 'memo')
				continue;
			if ($needCaption)
				$c .= "<tr><td style='font-weight: bold;' colspan='2'>" . utils::es ($allPropertiesGroups[$groupId]['name']) . '</td></tr>';
			$c .= "<tr><td style='width: 30%; text-align: right;'>" . utils::es ($p ['name']) . '</td><td>';

			$propvals = array ();
			forEach ($propertyValues as $pv)
				$propvals[] = $pv ['value'];

			$result['propertyValues'][$propertyId] = $propvals;

			$c .= implode(', ', $propvals);
			$c .= '</td></tr>';
			$needCaption = FALSE;
		}
	}
	$c .= "</table>";
	$result['params'] = $c;

	$c = '';
	forEach ($properties as $groupId => $groupContent)
	{
		forEach ($groupContent as $propertyId => $propertyValues)
		{
			$p = $allProperties [$propertyId];
			if ($p ['type'] != 'memo')
				continue;

			$c = '';
			$propvals = array ();
			forEach ($propertyValues as $pv)
				$propvals[] = $pv ['value'];
			$c .= implode('<hr/>', $propvals);

			$result[$propertyId] = $c;
		}
	}

	return $result;
}


function getPropertiesDetail ($table, $recData, $title = '')
{
	$result = ['type' => 'properties', 'title' => $title, 'pane' => 'e10-pane e10-pane-table'];

	$allProperties = $table->app()->cfgItem ('e10.base.properties', array());
	$allPropertiesGroups = $table->app()->cfgItem ('e10.base.propertiesGroups', array());
	$properties = \E10\Base\getPropertiesTable ($table->app (), $table->tableId(), $recData ['ndx']);

	forEach ($properties as $groupId => $groupContent)
	{
		$newGroup = array ('title' => $allPropertiesGroups[$groupId]['name'], 'rows' => array ());

		forEach ($groupContent as $propertyId => $propertyValues)
		{
			$p = $allProperties [$propertyId];
			if ($p ['type'] == 'memo')
				continue;

			$propvals = array ();
			forEach ($propertyValues as $pv)
			{
				$vv = $pv ['value'];
				if (isset ($pv ['note']) && $pv ['note'] != '')
					$vv .= ' ('.$pv ['note'].')';
				$propvals[] = $vv;
			}

			$newGroup ['rows'][] = array ('title' => $p ['name'], 'value' => implode(', ', $propvals));
		}

		if (count($newGroup ['rows']))
			$result ['params'][] = $newGroup;
	}

	forEach ($properties as $groupId => $groupContent)
	{
		forEach ($groupContent as $propertyId => $propertyValues)
		{
			$p = $allProperties [$propertyId];
			if ($p ['type'] != 'memo')
				continue;

			$propvals = array ();
			forEach ($propertyValues as $pv)
				$propvals[] = $pv ['value'];

			$result ['texts'][] = array ('title' => $p ['name'], 'text' => implode('<hr/>', $propvals));
		}
	}

	if (!isset($result ['texts']) && !isset($result ['params']))
		return FALSE;

	return $result;
}


function searchArrayItem ($array, $key, $value)
{
	foreach ($array as $k => $v)
	{
		if (isset ($v [$key]) && $v [$key] == $value)
			return $v;
	}
	return NULL;
}

class ListProperties implements \E10\IDocumentList
{
	public $table;
	public $listId;
	public $listDefinition;
	public $myProperties = array ();
	public $allProperties;
	public $data = array ();
	public $dataGrouped = array ();
	public $options = 0;

	function init ()
	{
		$this->listDefinition = $this->table->listDefinition ($this->listId);
		$this->allProperties = $this->table->app()->cfgItem ('e10.base.properties', []);

		$cfgItem = NULL;
		$this->myProperties = [];
		if (isset ($this->listDefinition ['srcCfgKeyColumn']))
		{
			if (isset($this->recData [$this->listDefinition ['srcCfgKeyColumn']]))
				$this->myProperties = $this->table->app()->cfgItem ($this->listDefinition ['propertiesCfgList'].'.'.$this->recData [$this->listDefinition ['srcCfgKeyColumn']], []);
		}
		else
			$this->myProperties = $this->table->app()->cfgItem ($this->listDefinition ['propertiesCfgList'], []);

		if (isset ($this->listDefinition ['propertiesCfgList2']))
		{
			if (isset($this->listDefinition ['srcCfgKeyColumn2']))
			{
				if (isset($this->recData [$this->listDefinition ['srcCfgKeyColumn2']]))
					$this->myProperties = array_merge($this->myProperties, $this->table->app()->cfgItem($this->listDefinition ['propertiesCfgList2'].'.'.$this->recData [$this->listDefinition ['srcCfgKeyColumn2']], array()));
			}
			else
				$this->myProperties = array_merge($this->myProperties, $this->table->app()->cfgItem($this->listDefinition ['propertiesCfgList2'], []));
		}
		$this->table->checkDocumentPropertiesList ($this->myProperties, $this->recData);
	}

	function myLoadData ($withUnused)
	{
		$loadedProperties = array ();
		$rowNumber = 0;
		// --load from table
		if (isset ($this->recData ['ndx']))
		{
			$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND recid = %i ORDER BY ndx";
			$query = $this->table->app()->db()->query ($sql, $this->table->tableId (), $this->recData ['ndx']);
			foreach ($query as $row)
			{
				$loaded = false;
				$p = $this->allProperties [$row['property']];
				$item = array ('rowNumber' => $rowNumber, 'ndx' => $row ['ndx'], 'property' => $row ['property'], 'name' => $p ['name'],
											 'group' => $row ['group'], 'subtype' => $row ['subtype'], 'note' => $row ['note']);
				if ((isset ($p ['type'])) AND ($p ['type'] === 'memo'))
					$item['value'] = $row['valueMemo'];
				else
				if ((isset ($p ['type'])) AND ($p ['type'] === 'date'))
					$item['value'] = $row['valueDate'];
				else
				if ((isset ($p ['type'])) AND ($p ['type'] === 'reference'))
					$item['value'] = $row['valueNum'];
				else
					$item['value'] = $row['valueString'];
				$this->dataGrouped [$row ['group']][$row ['property']][] = $item;
				$this->data [] = $item;
				$loadedProperties [$row ['group']][$row ['property']] = 1;
				$rowNumber++;
			}
		}

		if (isset($this->formData) && $this->formData)
			$this->formData->checkLoadedList ($this);
		$rowNumber = count($this->data);

		// --join unused properties
		if ($withUnused)
		{
			forEach ($this->myProperties as $groupId => $groupDef)
			{
				forEach ($groupDef as $propertyId)
				{
					$p = $this->allProperties [$propertyId] ?? NULL;
					if (!$p)
						continue;
					if (!$this->table->propertyEnabled ($this->recData, $groupId, $propertyId, $p, $this->dataGrouped))
					{
						unset ($this->dataGrouped[$groupId][$propertyId]);
						continue;
					}

					if (isset ($loadedProperties [$groupId][$propertyId]) || isset($this->dataGrouped[$groupId][$propertyId]))
						continue;

					if (isset($p['optionaly']) && $p['optionaly'])
						continue;

					$this->dataGrouped [$groupId][$propertyId][] = array ('rowNumber' => $rowNumber, 'ndx' => 0, 'property' => $propertyId, 'group' => $groupId, 'value' => '');
					$this->data [] = array ('rowNumber' => $rowNumber, 'ndx' => 0, 'property' => $propertyId, 'group' => $groupId,
																	'value' => '', 'subtype' => '', 'note' => '');
					$rowNumber++;
				}
			}
		}
		if (isset($this->formData))
			$this->formData->lists [$this->listId] = $this->data;
	}

	function loadData ()
	{
		$this->myLoadData (false);
	}

	function saveData ($listData)
	{
		$usedNdx = array ();
		forEach ($listData as $row)
		{
			$r = array ();
			$p = $this->allProperties [$row['property']];
			$r ['property'] = $row ['property'];
			$hasValue = false;

			if ((isset ($p ['type'])) AND ($p ['type'] == 'memo'))
			{
				$r['valueMemo'] = $row ['value'];
				if ($row ['value'] != '')
					$hasValue = true;
			}
			else
			if ((isset ($p ['type'])) AND ($p ['type'] == 'date'))
			{
				$r['valueDate'] = $row ['value'];
				if (DbTable::dateIsBlank ($row ['value']) == FALSE)
				{
					$dd = utils::createDateTime ($row ['value']);
					$r ['valueNum'] = date_timestamp_get ($dd);
					$r ['valueString'] = date_format ($dd, 'Y-m-d');

					$hasValue = true;
				}
			}
			else
			if ((isset ($p ['type'])) AND ($p ['type'] == 'reference'))
			{
				$r['valueString'] = $row ['value'];
				$r['valueNum'] = $row ['value'];
				if ($row ['value'] != 0)
					$hasValue = true;
			}
			else
			{
				$r['valueString'] = str::upToLen($row ['value'], 64);
				if ($row ['value'] != '')
					$hasValue = true;
			}

			if (!$hasValue)
				continue;

			if ((isset ($p ['note'])) AND ($p ['note']))
				$r['note'] = isset ($row['note']) ? $row['note'] : '';

			if ($row ['ndx'] == 0)
			{ // insert
				$r['tableid'] = $this->table->tableId();
				$r['recid'] = $this->recData ['ndx'];
				$r['group'] = $row ['group'];
				$r['subtype'] = isset ($row['subtype']) ? $row['subtype'] : '';
				$r['note'] = isset ($row['note']) ? $row['note'] : '';

				$this->table->app()->db()->query ("INSERT INTO [e10_base_properties]", $r);
				$newNdx = intval ($this->table->app()->db()->getInsertId ());
				$usedNdx [] = $newNdx;
			}
			else
			{	// update
				$this->table->app()->db()->query ("UPDATE [e10_base_properties] SET ", $r, "WHERE [ndx] = %i", $row ['ndx']);
				$usedNdx [] = $row ['ndx'];
			}
		}
		// -- clear deleted/unused rows
		$q = "DELETE FROM [e10_base_properties] where [tableid] = %s AND [recid] = %i";
		if (count ($usedNdx))
			$q .= " AND [ndx] NOT IN (" . implode(', ', $usedNdx) . ")";
		$this->table->app()->db()->query ($q, $this->table->tableId(), $this->recData ['ndx']);
	}

	function setRecord ($listId, \Shipard\Form\TableForm $formData)
	{
		$this->table = $formData->table;
		$this->listId = $listId;
		$this->formData = $formData;
		$this->recData = $formData->recData;
		$this->init ();
	}

	function setRecData ($table, $listId, $recData)
	{
		$this->table = $table;
		$this->listId = $listId;
		$this->recData = $recData;
		$this->init ();
	}

	function createHtmlCode ($options = 0)
	{
		$this->options |= $options;
		$this->myLoadData (true);

		$c = "";
		$memoInputs = array ();

		if ($options & TableForm::loAddToFormLayout)
		{

		}
		else
			$c .= "<div>" . "<table class='e10-base-properties'>";

		$rowNumber = 0;
		$allPropertiesGroups = $this->table->app()->cfgItem ('e10.base.propertiesGroups', array());

		forEach ($this->myProperties as $groupId => $groupDef)
		{
			$optionalyBtns = '';
			if (!$this->formData->readOnly)
			{
				forEach ($groupDef as $propertyId)
				{
					$p = $this->allProperties [$propertyId];
					if (isset ($p['optionaly']) && $p['optionaly'])
					{
						$bntContent = $this->formData->app()->ui()->icon('system/actionAdd').' '.utils::es($p['name']);
						$bntTitle = utils::es('Přidat '.$p['name']);
						$optionalyBtns .= " <button type='button' class='btn btn-default btn-xs e10-row-append' tabindex='-1' title='{$bntTitle}' ".
															"data-list='{$this->listId}' data-propid='{$propertyId}' data-groupid='{$groupId}'>$bntContent</button>";
					}
				}
			}

			$needHeader = TRUE;
			forEach ($groupDef as $propertyId)
			{
				$p = $this->allProperties [$propertyId];
				if (!isset ($this->dataGrouped [$groupId]) || !isset($this->dataGrouped [$groupId][$propertyId]))
					continue;
				forEach ($this->dataGrouped [$groupId][$propertyId] as $row)
				{
					if ($needHeader && isset ($p ['type']) && $p ['type'] != 'memo')
					{
						$c .= "<tr class='e10-flf1 e10-property-group'>" .
							"<td class='e10-fl-cellLabel'>" . utils::es ($allPropertiesGroups[$groupId]['name']) .
							"</td><td class='e10-fl-cellInput' style='text-align: right; vertical-align: middle; padding-right: 1ex;'>" . $optionalyBtns .
							'</td></tr>';
						$needHeader = FALSE;
					}

					if ($options & TableForm::loWidgetParts)
					{
						if (isset ($p ['type']) && $p ['type'] == 'memo')
						{
							$inputCode = $this->createHtmlCodeRow ($rowNumber, $row, $p, $groupId, $propertyId);
							$memoInputs [] = array ('text' => $p ['name'], 'icon' => 'system/formNote', 'widgetCode' => $inputCode);
						}
						else
							$c .= $this->createHtmlCodeRow ($rowNumber, $row, $p, $groupId, $propertyId);
					}
					else
						$c .= $this->createHtmlCodeRow ($rowNumber, $row, $p, $groupId, $propertyId);
					$rowNumber++;
				}
			}
		}
		if ($options & TableForm::loAddToFormLayout)
		{

		}
		else
			$c .= "</table>" . "</div>";

		if ($options & TableForm::loWidgetParts)
		{
			$parts ['widgetCode'] = $c;
			$parts ['memoInputs'] = $memoInputs;
			return $parts;
		}

		return $c;
	}

	function createHtmlCodeRow ($rowNumber, $dataItem, $property, $groupId, $propertyId)
	{
		$c = "";
		if ($this->options & TableForm::loAddToFormLayout)
			$c .= "<tr class='e10-flf1 e10-property'>";
		else
			$c .= "<tr class='e10-property'>";

		if (isset ($property ['type']) && $property ['type']  == 'memo' && $this->options & TableForm::loWidgetParts)
			$c .= "<td class='e10-fl-cellInput' colspan='2'>";
		else
			$c .= "<td class='e10-fl-cellLabel'>{$property ['name']}</td><td class='e10-fl-cellInput'>";

		$readOnlyParam = '';
		if ($this->formData->readOnly)
			$readOnlyParam = " readonly='readonly'";

		$inputPrefix = "lists.{$this->listId}.{$dataItem['rowNumber']}";
		$c .= "<input type='hidden' name='{$inputPrefix}.ndx' data-fid='{$this->formData->fid}' value='{$dataItem ['ndx']}'/>";
		$c .= "<input type='hidden' name='{$inputPrefix}.property' data-fid='{$this->formData->fid}' value='{$dataItem ['property']}'/>";
		$c .= "<input type='hidden' name='{$inputPrefix}.group' data-fid='{$this->formData->fid}' value='{$dataItem ['group']}'/>";

		$inputId = str_replace ('.', '-', $inputPrefix . '-value');
		$inputNoteId = str_replace ('.', '-', $inputPrefix . '-note');

		if ($property ['type']  == 'text')
			$c .= "<input type='text' name='{$inputPrefix}.value' class='e10-ef-w50' maxlength='64' id='$inputId' data-fid='{$this->formData->fid}'$readOnlyParam/>";
		else
		if ($property ['type']  == 'memo')
		{
			if ($this->options & TableForm::loWidgetParts)
				$c .= "<textarea name='{$inputPrefix}.value' class='e10-inputMemo e10-wsh-h2b' id='$inputId' data-fid='{$this->formData->fid}'$readOnlyParam></textarea>";
			else
				$c .= "<textarea name='{$inputPrefix}.value' class='e10-inputMemo' id='$inputId' data-fid='{$this->formData->fid}'$readOnlyParam></textarea>";
		}
		else
		if ($property ['type']  == 'date')
		{
			$inputClass = '';
			$inputType = '';
			$inputParams = '';
			$this->formData->inputInfoHtml(TableForm::INPUT_STYLE_DATE, $inputClass, $inputType, $inputParams);
			$c .= "<input type='$inputType' name='{$inputPrefix}.value' class='$inputClass' id='$inputId' data-fid='{$this->formData->fid}'$readOnlyParam$inputParams/>";
		}
		else
		if ($property ['type']  == 'reference')
		{
			if ($this->formData->readOnly)
				$readOnlyParam = " disabled='disabled'";

			$refTable = $this->table->app()->table ($property ['.referenceId']);
			if ($refTable)
			{
					$rows = $refTable->columnInfoAutocomplete ();
					$c .= "<select style='width: 20ex;' name='{$inputPrefix}.value' class='e10-inputEnum e10-inputReference chzn-select'$readOnlyParam>";
					foreach ($rows as $val => $txt)
				$c .= " <option value='{$val}'>" . htmlspecialchars ($txt) . "</option>";
					$c .= "</select>";
			}
			else
				$c .= "!!!Bad reference Id: {$property ['.referenceId']}!!!";
		}
		else
		if ($property ['type']  == 'enum')
		{
			$class='';
			if (isset($property['saveOnChange']))
				$class= " class='e10-ino-saveOnChange'";

			if ($this->formData->readOnly)
				$readOnlyParam = " disabled='disabled'";
			$c .= "<select data-fid='{$this->formData->fid}' id='$inputId' name='$inputPrefix.value'$readOnlyParam{$class}>";
			foreach ($property ['enum'] as $valueX => $enumDef)
			{
				$value = strval($valueX);
				if ($value [0] == '.')
					continue;
				$c .= " <option value='{$value}'>" . utils::es ($enumDef ['fullName']) . '</option>';
			}
			$c .= "</select>";
		}
		else
			$c .= "!!!Bad field type: {$property ['type']}!!!";


		if (isset($property ['note']) && $property ['note'])
			$c .= "<label for='$inputNoteId' class='e10-prop-note'><i class='fa fa-pencil'></i></label>".
						"<input type='text' placeholder='pozn.' name='{$inputPrefix}.note' class='e10-prop-note' maxlength='50' id='$inputNoteId' data-fid='{$this->formData->fid}'$readOnlyParam/>";

		if (isset($property ['multi']) && $property ['multi'] && !$this->formData->readOnly)
			$c .= " <button type='button' class='btn btn-default btn-xs e10-row-append' tabindex='-1' data-list='{$this->listId}' data-propid='{$dataItem ['property']}' data-groupid='{$dataItem ['group']}'>".$this->formData->app()->ui()->icon('system/actionAdd')."</button>";

		$c .= "</td></tr>";

		return $c;
	}

	function appendRowCode ()
	{
		if (!isset ($this->formData))
		{
			$this->formData = new \Shipard\Form\TableForm($this->table, 0, 'none');
			$this->formData->fid = $this->fid;
		}
		$c = "";

		$rowNumber = intval ($this->table->app()->testGetParam ('rowNumber'));
		$propertyId = $this->table->app()->testGetParam ('propId');
		$groupId = $this->table->app()->testGetParam ('groupId');

		$p = $this->allProperties [$propertyId];

		//$this->dataGrouped [$groupId][$propertyId][] = array ('rowNumber' => $rowNumber, 'ndx' => 0, 'property' => $propertyId, 'group' => $groupId, 'name' => $p ['text'], 'value' => '');
		$dataItem = array ('rowNumber' => $rowNumber, 'ndx' => 0, 'property' => $propertyId, 'group' => $groupId, 'name' => $p ['text'], 'value' => '');

		$this->options |= TableForm::loAddToFormLayout;
		$c .= $this->createHtmlCodeRow ($rowNumber, $dataItem, $p, $groupId, $propertyId);
		$rowNumber++;

	//	$rowsAppendId = "";
	//	if ($this->listDefinition ['id'] == 'rows')
	//		$rowsAppendId = "id='{$this->fid}FormMainRowsAppend' ";

		//$c .= "<li class='e10-rows-append'><button {$rowsAppendId}data-list='{$this->listId}' data-row='$rowNumber'>Přidat další řádek</button></li>";

		return $c;
	}

	function copyDocumentList ($srcRecData, $dstRecData)
	{
		if (isset ($srcRecData ['ndx']))
		{
			$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND recid = %i ORDER BY ndx";
			$query = $this->table->app()->db()->query ($sql, $this->table->tableId (), $srcRecData ['ndx']);
			foreach ($query as $row)
			{
				$rowRecData = array_merge ($row->toArray());
				unset ($rowRecData['ndx']);
				$rowRecData ['recid'] = $dstRecData ['ndx'];
				$this->table->app()->db()->query ("INSERT INTO [e10_base_properties]", $rowRecData);
			}
		}
	}
}

function searchPropertyRecId ($app, $tableId, $propertyId, $propertyValue)
{
	$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND [property] = %s AND [valueString] = %s ORDER BY [ndx] LIMIT 0, 1";
	$rec = $app->db()->query ($sql, $tableId, $propertyId, $propertyValue)->fetch ();
	if (!$rec)
		return 0;

	return $rec ['recid'];
}


function allPropertiesCfg ($app)
{
	$props = array ();
	$propNdxMap = array ();

	$rows = $app->db->query ("SELECT * FROM e10_base_propdefs ORDER BY [ndx]");

	foreach ($rows as $r)
	{
		$newProp = array ('id' => $r ['id'], 'type' => $r ['type'], 'name' => $r ['shortName']);
		if ($r ['multipleValues'])
			$newProp ['multi'] = 1;
		if ($r ['optionaly'])
			$newProp ['optionaly'] = 1;
		if ($r ['enableNote'])
			$newProp ['note'] = 1;
		$props [$r ['id']] = $newProp;
		$propNdxMap [$r['ndx']] = $r ['id'];
	}

	$propertiesEnumRows = $app->db->query ("SELECT * from [e10_base_propdefsenum] ORDER BY [fullName]");
	foreach ($propertiesEnumRows as $r)
	{
		$propid = $propNdxMap [$r ['property']];
		if ($props [$propid]['type'] == 'enum')
			$props [$propid]['enum'][$r ['id']] = array ('fullName' => $r ['fullName']);
	}

	return $props;
}

function allPropertiesGroupsCfg ($app)
{
	$propsGroups = array ();
	$propGroupsNdxMap = array ();

	$rows = $app->db->query ("SELECT * FROM e10_base_propgroups ORDER BY [ndx]");

	foreach ($rows as $r)
	{
		$newGroup = array ('id' => $r ['id'], 'name' => $r ['shortName']);
		$propsGroups [$r ['id']] = $newGroup;
		$propGroupsNdxMap [$r['ndx']] = $r ['id'];
	}

	$sql = "SELECT links.ndx, links.linkId as linkId, links.srcRecId as propgroup, props.fullName as fullName, props.id as propId from e10_base_doclinks as links " .
				 "LEFT JOIN e10_base_propdefs as props ON links.dstRecId = props.ndx " .
				 "where dstTableId = 'e10.base.propdefs' AND srcTableId = 'e10.base.propgroups'";

	$rows = $app->db->query ($sql);
	foreach ($rows as $r)
	{
		$propGroupId = $propGroupsNdxMap [$r ['propgroup']];
		$propsGroups [$propGroupId]['properties'][] = $r['propId'];
	}

	return $propsGroups;
}

/* -----------
 * Attachments
 * -----------
 */

function addAttachments ($app, $toTableId, $toRecId, $fullFileName, $attType, $moveFile = false, $attOrder = 0, $attName = '')
{
	$newAtt = array ();
	$newAtt ['tableid'] = $toTableId;
	$newAtt ['recid'] = $toRecId;
	$newAtt ['atttype'] = $attType;
	$newAtt ['order'] = $attOrder;

	$baseFileName = '';

	if (substr ($fullFileName, 0, 2)  == '//')
	{ // e10remote attachment
		$parts = explode(':', $fullFileName);

		$path_parts = pathinfo ($parts [1]);
		$baseFileName = $path_parts ['filename'];
		$fileType = $path_parts ['extension'];

		$newAtt ['path'] = $parts [0];
		$newAtt ['filename'] = $parts [1];
		$newAtt ['filetype'] = $fileType;
		$newAtt ['attplace'] = TableAttachments::apE10Remote;
	}
	else
	{ // regular file
		$fileCheckSum = sha1_file($fullFileName);

		$path = strftime ('%Y/%m/%d') . "/$toTableId/";
		$destPath = __APP_DIR__ . '/att/' . $path;

		if (!is_dir($destPath))
		{
			mkdir ($destPath, 0775, true);
			$steps = explode ('/', strftime ('%Y/%m/%d').'/'.$toTableId);
			$p = __APP_DIR__ . '/att';
			foreach ($steps as $step)
			{
				$p .= '/'.$step;
				chmod($p, 0775);
				if (Utils::superuser())
					chown($p, Utils::wwwUser());
				chgrp($p, Utils::wwwGroup());
			}
		}

		$path_parts = pathinfo ($fullFileName);
		$baseFileName = $path_parts ['filename'];
		$fileName = $baseFileName;
		$fileType = strtolower($path_parts ['extension']);

		$newAtt ['path'] = $path;
		$newAtt ['filename'] = $fileName . '.' . $path_parts ['extension'];
		$newAtt ['filetype'] = $fileType;
		$newAtt ['attplace'] = TableAttachments::apLocal;

		if ($fileCheckSum !== FALSE)
			$newAtt ['fileCheckSum'] = $fileCheckSum;

		$destFullFileName = $destPath.'/'.$fileName.'.'.$path_parts ['extension'];

		if ($moveFile)
		{
			//rename($fullFileName, $destFullFileName);
			copy ($fullFileName, $destFullFileName);
			unlink($fullFileName);
		}
		else
			copy ($fullFileName, $destFullFileName);
	}

	$newAtt ['fileSize'] = filesize($destFullFileName);
	$newAtt ['name'] = ($attName === '') ? str::upToLen($baseFileName, 80) : str::upToLen($attName, 80);

	$app->db->query ("INSERT INTO [e10_attachments_files]", $newAtt);
	$newNdx = intval ($app->db->getInsertId ());

	if ($fileType === 'isdocx')
	{
		$archive = new \lib\core\attachments\FileArchiveExtractor($app);
		$archive->setFileName($destFullFileName);
		$archive->extractAsAttachments($toTableId, $toRecId);
	}
	elseif ($fileType === 'pdf')
	{
		$e = new \lib\pdf\PdfExtractor($app);
		$e->setFileName($destFullFileName);
		$e->extractAsAttachments($toTableId, $toRecId);
	}

	// -- get metadata
	$e = new \lib\core\attachments\Extract($app);
	$e->setAttNdx($newNdx);
	$e->run();

	// -- analyze data content
	$ddfe = new \lib\docDataFiles\AttachmentsUpdater($app);
	$ddfe->init();
	$ddfe->doOne($newNdx);

	return $newNdx;
}


function getAttachments ($app, $toTableId, $toRecId, $asRecords = FALSE)
{ // TODO: obsolete?
	$files = array ();
	$sql = "SELECT * FROM [e10_attachments_files] where [tableid] = %s AND [recid] = %i AND [deleted] = 0 ORDER BY defaultImage DESC, [order], name";
	$query = $app->db->query ($sql, $toTableId, $toRecId);
	if ($asRecords)
	{
		foreach ($query as $row)
			$files [] = $row->toArray();
	}
	else
	foreach ($query as $row)
	{
		$fn = $row ['path'] . $row ['filename'];
		$files [] = $fn;
	}

	return $files;
}


function getAttachments2 ($app, $toTableId, $toRecIds)
{
	$recs = implode (', ', $toRecIds);
	$files = array ();
	if (count($toRecIds) == 0)
		return $files;

	$sql = 'SELECT * FROM [e10_attachments_files] where [tableid] = %s AND [recid] IN %in AND [deleted] = 0 ORDER BY defaultImage DESC, [order], name';
	$query = $app->db->query ($sql, $toTableId, $toRecIds);
	foreach ($query as $row)
	{
		$files [$row['recid']][] = $row;
	}
	return $files;
}


function getAttachmentUrl ($app, $attachment, $thumbWidth = 0, $thumbHeight = 0, $fullUrl = FALSE, $params = FALSE)
{
	$absUrl = '';
	if ($fullUrl || (isset($app->clientType [1]) && $app->clientType [1] === 'cordova'))
		$absUrl = $app->urlProtocol . $_SERVER['HTTP_HOST'];

	$url = '';

	if ($thumbWidth || $thumbHeight)
	{
		if ($attachment ['attplace'] === TableAttachments::apLocal)
		{
			$url = $absUrl.$app->dsRoot . '/imgs';
			if ($thumbWidth)
				$url .= '/-w' . intval($thumbWidth);
			if ($thumbHeight)
				$url .= '/-h' . intval($thumbHeight);
			if ($params !== FALSE)
				$url .= '/'.implode('/', $params);
			$url .= '/att/' . $attachment ['path'] . urlencode($attachment ['filename']);
		}
		else
		if ($attachment ['attplace'] === TableAttachments::apE10Remote)
		{
			$url = $attachment ['path'] . 'imgs';
			if ($thumbWidth)
				$url .= '/-w' . intval($thumbWidth);
			if ($thumbHeight)
				$url .= '/-h' . intval($thumbHeight);
			$url .= '/' . urlencode($attachment ['filename']);
		}
		else
		if ($attachment ['attplace'] === TableAttachments::apRemote)
			$url = $attachment ['path'];
	}
	else
	{
		if ($attachment ['attplace'] === TableAttachments::apLocal)
			$url = $absUrl.$app->dsRoot . '/att/' . $attachment ['path'] . $attachment ['filename'];
		else
		if ($attachment ['attplace'] === TableAttachments::apE10Remote)
			$url = $attachment ['path'] . '/' . $attachment ['filename'];
		if ($attachment ['attplace'] === TableAttachments::apRemote)
			$url = $attachment ['path'];
	}
	return $url;
}

function getAttachmentsThumbnails ($app, $toTableId, $toRecId, $width, $height)
{
	$files = array ();
	$sql = "SELECT * FROM [e10_attachments_files] where [tableid] = %s AND [recid] = %i AND [deleted] = 0";
	$query = $app->db->query ($sql, $toTableId, $toRecId);

	foreach ($query as $row)
		$files [] = getAttachmentUrl ($app, $row, $width, $height);

	return $files;
}

function getDefaultImages ($app, $toTableId, $toRecIds, $params = FALSE, $enableAnyImage = FALSE, $thWidth = 1024, $thHeight = 0)
{
	$images = [];

	$q[] = 'SELECT * FROM [e10_attachments_files]';
	array_push($q, ' WHERE [tableid] = %s', $toTableId, ' AND [deleted] = 0');
	array_push($q, ' AND [recid] IN %in', $toRecIds);
	if (!$enableAnyImage)
		array_push($q, ' AND [defaultImage] = 1');
	array_push($q, ' ORDER BY defaultImage DESC, [order], name');

	$rows = $app->db->query ($q);
	foreach ($rows as $r)
	{
		if (isset($images[$r['recid']]))
			continue;
		$img = [
			'originalImage' => getAttachmentUrl ($app, $r),
			'smallImage' => getAttachmentUrl ($app, $r, $thWidth, $thHeight, FALSE, $params),
			'fileName' => $r['path'] . $r['filename'],
		];
		$images[$r['recid']] = $img;
	}

	return $images;
}

function loadAttachments ($app, $ids, $tableId = FALSE)
{
	static $imgFileTypes = array ('pdf', 'jpg', 'jpeg', 'png', 'gif', 'svg');

	$files = array ();
	if (count($ids) == 0)
		return $files;

	if ($tableId)
	{
		$sql = "SELECT * FROM [e10_attachments_files] where [recid] IN %in AND tableid = %s AND [deleted] = 0 ORDER BY defaultImage DESC, [order], name";
		$query = $app->db->query ($sql, $ids, $tableId);
		foreach ($query as $row)
		{
			$img = $row->toArray ();
			$img['folder'] = 'att/';
			$img['url'] = getAttachmentUrl ($app, $row);
			if (strtolower($row['filetype']) === 'pdf' || strtolower($row['filetype']) === 'svg')
				$img['original'] = 1;
			if (strtolower($row['filetype']) === 'svg')
				$img['svg'] = 1;
			if (in_array(strtolower($row['filetype']), $imgFileTypes))
				$files [$row['recid']]['images'][] = $img;
			else
				$files [$row['recid']]['files'][] = $img;
		}
		forEach ($files as &$f)
		{
			$f['count'] = 0;
			if (isset ($f['files']))
			{
				$f['hasDownload'] = 1;
				$f['count'] += count($f['files']);
			}
			if (isset ($f['images']))
			{
				if (count ($f['images']) > 2)
					$f['hasImagesSmall'] = 1;
				else
					$f['hasImagesBig'] = 1;
				$f['count'] += count($f['images']);
			}
		}
	}
	else
	{
		$sql = "SELECT * FROM [e10_attachments_files] where [ndx] IN %in AND [deleted] = 0 ORDER BY defaultImage DESC, [order], name";
		$query = $app->db->query ($sql, $ids);
		foreach ($query as $row)
		{
			$img = $row->toArray ();
			$img['folder'] = 'att/';
			$img['url'] = getAttachmentUrl ($app, $row);
			if (strtolower($row['filetype']) === 'pdf' || strtolower($row['filetype']) === 'svg')
				$img['original'] = 1;
			if (strtolower($row['filetype']) === 'svg')
				$img['svg'] = 1;
			if (strtolower($row['filetype']) === 'pdf')
				$img['original'] = 1;
			if (in_array(strtolower($row['filetype']), $imgFileTypes))
				$files ['images'][] = $img;
			else
				$files ['files'][] = $img;
		}
	}

	return $files;
}


/**
 * Widgetový prohlížeč příloh
 *
 */

class ViewAttachments extends \E10\TableViewWidget
{
	var $classification;

	public function init ()
	{
		parent::init();

		$mq [] = ['id' => 'active', 'title' => 'Platné'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
	}

	public function zeroRowCode ()
	{
		$c = '';
		$c .= "<div class='e10-tvw-item' id='{$this->vid}Footerdddd'>";

		$recId = intval ($this->queryParam ('recid'));
		if ($recId == 0)
		{
			$c .= "<p>Před přidáním přílohy musí být dokument uložen.</p>";
		}
		else
		{
			$c .= $this->app()->ui()->addAttachmentsInputCode($this->queryParam('tableid'), $this->queryParam('recid'), $this->vid);
		}
		$c .= '</div>';

		return $c;
	}

	public function createToolbar ()
	{
		return array ();
	}

	public function rowHtml ($listItem)
	{
		$attInfo = $this->table->attInfo($listItem);

		$c = '';

		$class = '';
		if ($listItem ['deleted'])
			$class = ' deleted';

		$c .= "<div class='e10-tvw-item{$class}' data-pk='{$listItem['ndx']}'>";
		$c .= "<table style='clear: both; vertical-align: top !important; width: 100%;'><tr>";

		$url = getAttachmentUrl ($this->table->app(), $listItem);
		$thumbUrl = getAttachmentUrl ($this->table->app(), $listItem, 192, 192);
		$c .= "<td style='text-align: center; width: 200px; '><a href='$url' target='new'><img src='$thumbUrl'/></a></td>";

		$c .= "<td class='e10-tvw-item-attachment' style='vertical-align: top;'>";
		$c .= "<div class='h2 padd5'>" . strval ($this->lineRowNumber + $this->rowsFirst) . '. ' .
						\E10\es ($listItem ['name']) . "<span style='float: right;'>#{$listItem ['ndx']}</span>".'</div>';

		if ($listItem['fileKind'] !== 0)
			$c .= "<div class='padd5'>".$this->app()->ui()->composeTextLine($attInfo['labels']).'</div>';

		$c .= "<div class='padd5'><input type='text' style='width: 100%;' value='$url' readonly='readonly'/></div>";

		if (isset ($this->classification [$listItem ['ndx']]))
		{
			$tags = [];
			forEach ($this->classification [$listItem ['ndx']] as $clsfGroup)
				$tags = array_merge ($tags, $clsfGroup);
			$c .= "<div class='padd5'>".$this->app()->ui()->composeTextLine($tags).'</div>';
		}

		$c .= $this->createItemMenuCode ($listItem, 'e10-tvw-item-menu');
		$c .= '</td>';

		$c .= '</tr></table>';
		$c .= '</div>';
		return $c;
	}


	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$recId = intval ($this->queryParam ('recid'));
		if ($recId == 0)
		{
			$this->runQuery (NULL);
			return;
		}

		$q[] = 'SELECT * FROM [e10_attachments_files] ';

		array_push ($q, ' WHERE [tableid] = %s', $this->queryParam ('tableid'), ' AND [recid] = %i ', $recId);

		if ($fts !== '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [filename] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		if ($mainQuery === '' || $mainQuery === 'active')
			array_push ($q, ' AND deleted = 0');
		if ($mainQuery === 'trash')
			array_push ($q, ' AND deleted = 1');

		array_push ($q, ' ORDER BY defaultImage DESC, [order], name');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->classification = \E10\Base\loadClassification($this->table->app(), $this->table->tableId(), $this->pks);
	}
} // class ViewAttachments


/*
 * ViewAttachmentsForDocument
 *
 */

class ViewAttachmentsForDocument extends \E10\TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if ($this->queryParam ('recid'))
			$this->addAddParam ('recid', $this->queryParam ('recid'));
		if ($this->queryParam ('tableid'))
			$this->addAddParam ('tableid', $this->queryParam ('tableid'));
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['name'];
		$listItem ['i1'] = '#'.$item ['ndx'];
		$listItem ['icon'] = 'x-image';

		return $listItem;
	}


	public function selectRows ()
	{
		$q = "SELECT * FROM [e10_attachments_files] WHERE [tableid] = %s AND [recid] = %i ORDER BY [ndx]" . $this->sqlLimit();
		$this->runQuery ($q, $this->queryParam ('tableid'), $this->queryParam ('recid'));
	}
} // class ViewAttachmentsForDocument



/**
 * Detail s přílohami dokumentu
 *
 */

class ViewDetailAttachments extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10.base.attachments', 'e10.base.ViewAttachmentsForDocument',
														 array ('recid' => $this->item ['ndx'], 'tableid' => $this->tableId()));
	}
} // class ViewDetailAttachments


/* --------
 * ListRows
 * --------
 */


class ListRows implements \E10\IDocumentList
{
	public $formData = NULL;
	public $fid = '';
	public $recData;
	public $listId;
	public $listDefinition;
	public $listGroup = '';
	public $data = array ();

	public $headTable;
	public $rowsTable;
	public $rowsTableQueryCol;
	public $rowsTableOrderCol = FALSE;

	var $rowOrderForInsert;

	function init ()
	{
		$this->listDefinition = $this->headTable->listDefinition ($this->listId);
		$this->rowsTable = $this->headTable->app()->table ($this->listDefinition ['table']);
		$this->rowsTableQueryCol = $this->listDefinition ['queryColumn'] ?? '';

		if (isset($this->listDefinition ['orderColumn']))
			$this->rowsTableOrderCol = $this->listDefinition ['orderColumn'];

		if (isset ($this->listDefinition ['group']))
			$this->listGroup = $this->listDefinition ['group'];
	}

	function loadData ()
	{
		if (isset($this->recData ['ndx']))
		{
			$q [] = 'SELECT * FROM [' . $this->rowsTable->sqlName () . '] ';
			array_push($q, " WHERE [{$this->rowsTableQueryCol}] = %i", $this->recData ['ndx']);

			$this->loadDataQry($q);

			if ($this->rowsTableOrderCol)
				array_push($q, " ORDER BY [{$this->rowsTableOrderCol}], ndx");
			else
				array_push($q, " ORDER BY ndx");

			$rows = $this->rowsTable->app()->db ()->query ($q);

			forEach ($rows as $r)
			{
				//-- subColumns
				$columns = $this->rowsTable->columns();
				foreach ($columns as $columnId => $column)
				{
					if ($column['type'] !== DataModel::ctSubColumns || !isset($r[$columnId]))
						continue;

					$scData = json_decode ($r[$columnId], TRUE);
					if (!$scData)
						continue;

					foreach ($scData as $scKey => $scValue)
					{
						$r['subColumns_'.$columnId.'_'.$scKey] = $scValue;
					}
				}

				$this->data [] = $r;
			}
		}
		if ($this->formData)
			$this->formData->checkLoadedList ($this);

		if (isset($this->formData))
			$this->formData->lists [$this->listId] = $this->data;
	}

	function loadDataQry (&$q)
	{
	}

	function saveData ($listData)
	{
		//error_log ('##SAVE DATA (' . $this->formData->recData ['ndx'] . ')' . json_encode ($listData));
		$usedNdx = [];
		$lastRowOrder = 0;

		forEach ($listData as &$row)
		{
			if ($this->rowsTableOrderCol && (!isset($row[$this->rowsTableOrderCol]) || !$row[$this->rowsTableOrderCol]))
				$row[$this->rowsTableOrderCol] = $lastRowOrder + 100;

			$row [$this->rowsTableQueryCol] = $this->recData ['ndx'];
			$this->checkSavedRow($row);
			if (!isset ($row ['ndx']) || $row ['ndx'] == 0 || $row ['ndx'] == '')
			{ // insert
				unset ($row['ndx']);
			//	error_log ('##' . json_encode ($row));
				$newNdx = $this->rowsTable->dbInsertRec ($row, $this->recData);
				$usedNdx [] = $newNdx;
			}
			else
			{ // update
				$this->rowsTable->dbUpdateRec ($row, $this->recData);
				$usedNdx [] = $row ['ndx'];
			}

			if ($this->rowsTableOrderCol)
				$lastRowOrder = $row[$this->rowsTableOrderCol];
		}

		// -- clear deleted rows
		$q[] = 'DELETE FROM ['.$this->rowsTable->sqlName ().']';
		array_push($q, ' WHERE ['.$this->rowsTableQueryCol.'] = %i', $this->recData ['ndx']);
		if (count($usedNdx))
			array_push($q, ' AND [ndx] NOT IN %in', $usedNdx);

		$this->loadDataQry($q);
		$this->rowsTable->app()->db()->query($q);
	}

	protected function checkSavedRow (&$row)
	{
	}

	function setRecord ($listId, \Shipard\Form\TableForm $formData)
	{
		$this->listId = $listId;
		$this->formData = $formData;
		$this->fid = $formData->fid;
		$this->recData = $formData->recData;
		$this->headTable = $this->formData->table;
		$this->init ();
	}

	function setRecData ($table, $listId, $recData)
	{
		$this->listId = $listId;
		$this->recData = $recData;
		$this->headTable = $table;
		$this->init ();
	}

	function createHtmlCode ($options = 0)
	{
		$this->loadData ();

		$c = "";
		$c .= "<div class='e10-rows e10-rows-{$this->listId} e10-wsh-h2b' data-refreshLayout='e10RowsEditRefreshLayout' data-list='{$this->listId}' data-fid='{$this->fid}'><ul>";

		$rowNumber = 0;
		$lastRowOrderNumber = 0;
		$thisRowOrderNumber = 0;
		forEach ($this->data as $row)
		{
			if ($this->rowsTableOrderCol)
			{
				$thisRowOrderNumber = isset($row[$this->rowsTableOrderCol]) ? $row[$this->rowsTableOrderCol] : 0;
				$this->rowOrderForInsert = $lastRowOrderNumber + intval(($thisRowOrderNumber - $lastRowOrderNumber) / 2);
			}
			$c .= $this->createHtmlCodeRow ($rowNumber, $row, $options);
			$rowNumber++;
			$lastRowOrderNumber = $thisRowOrderNumber;
		}

		$disableButton = isset($this->listDefinition ['disableAddButton']) ? intval($this->listDefinition ['disableAddButton']) : 0;
		if ($this->rowsTable->app()->hasRole('root'))
			$disableButton = 0;
		if (!$this->formData->readOnly && !$disableButton)
		{
			$c .= "<li class='e10-rows-append'>";
			if ($this->formData->appendListRow ($this->listId))
				$c .= "<button class='btn btn-default' data-list='{$this->listId}' data-row='$rowNumber' data-fid='{$this->fid}'>".$this->formData->app()->ui()->icon('system/actionAdd')." Přidat další řádek</button>";
			$c .= '</li>';
		}

		$c .= "</ul></div>";

		return $c;
	}

	function appendRowCode ()
	{
		$c = "";
		$rowNumber = intval ($this->table->app()->testGetParam ('rowNumber'));

		$c .= $this->createHtmlCodeRow ($rowNumber, NULL);
		$rowNumber++;

		$rowsAppendId = "";
		if ($this->listDefinition ['id'] == 'rows')
			$rowsAppendId = "id='{$this->fid}FormMainRowsAppend' ";

		$disableButton = isset($this->listDefinition ['disableAddButton']) ? intval($this->listDefinition ['disableAddButton']) : 0;
		if ($this->rowsTable->app()->hasRole('root'))
			$disableButton = 0;
		if (!$disableButton)
			$c .= "<li class='e10-rows-append'><button {$rowsAppendId}data-list='{$this->listId}' data-row='$rowNumber' data-fid='{$this->fid}'>Přidat další řádek1_{$this->listDefinition ['id']}</button></li>";

		return $c;
	}

	function createHtmlCodeRow ($rowNumber, $dataItem, $layoutOptions)
	{
		$c = "";
		$inputPrefix = "lists.{$this->listId}.$rowNumber.";
		$form = $this->rowsTable->getListForm ($dataItem, $this->recData);
		$form->setOptions ([
			'rowMode' => true, 'rowNumber' => $rowNumber, 'inputPrefix' => $inputPrefix, 'layoutOptions' => $layoutOptions,
			'rowOrderForInsert' => $this->rowOrderForInsert, 'ownerRecData' => $this->recData, 'list' => $this,
			'isLastRow' => (count($this->data) === $rowNumber + 1)
		]);
		$form->readOnly = $this->formData->readOnly;
		if ($form->ok)
		{
			$form->doFormData ();
			$form->renderForm ();
			$c .= $form->html;
		}
		return $c;
	}

	function copyDocumentList ($srcRecData, $dstRecData)
	{
		if (isset ($srcRecData ['ndx']))
		{
			$sql = "SELECT * FROM [" . $this->rowsTable->sqlName () . "] WHERE {$this->rowsTableQueryCol} = %i ORDER BY ndx";
			$query = $this->rowsTable->app()->db()->query ($sql, $srcRecData ['ndx']);
			foreach ($query as $row)
			{
				$rowRecData = $this->rowsTable->copyDocumentRecord($row->toArray(), $dstRecData);
				$rowRecData [$this->rowsTableQueryCol] = $dstRecData ['ndx'];
				$this->rowsTable->dbInsertRec ($rowRecData, $dstRecData);
			}
		}
	}

}


/* --------------
 * Classification
 * --------------
 */


function classificationParams ($table)
{
	$clsfItems = $table->app()->cfgItem ('e10.base.clsf');

	$params = [];
	$groups = $table->app()->cfgItem ('e10.base.clsfGroups', []);
	forEach ($groups as $key => $group)
	{
		if (isset ($group ['tables']) && !in_array ($table->tableId(), $group ['tables']))
			continue;

		$p = ['id' => $key, 'name' => isset($group['label']) ? $group['label'] : $group['name'], 'items' => []];
		$grpItems = $table->app()->cfgItem ('e10.base.clsf.'.$key, []);
		foreach ($grpItems as $itmNdx => $itm)
		{
			$clsfItem = $clsfItems [$key][$itmNdx];

			$p['items'][$itmNdx] = ['id' => $itmNdx, 'title' => $itm ['name']];
			if (isset($clsfItem['css']))
				$p['items'][$itmNdx]['css'] = $clsfItem['css'];
		}
		if (count($p['items']))
			$params[] = $p;
	}
	return $params;
}


function getClsf ($app, $tableId, $recId, $simple = false)
{
	$query = $app->db->query ("SELECT [ndx], [clsfItem], [group] from [e10_base_clsf] where [tableid] = %s AND [recid] = %i", $tableId, $recId);

	$clsf = array ();

	if ($simple)
	{
		foreach ($query as $row)
			$clsf [$row['group']][$row['ndx']] = $row['clsfItem'];
	}
	else
	{
		$clsfItems = $app->cfgItem ('e10.base.clsf');
		foreach ($query as $row)
		{
			if (isset($clsfItems [$row['group']][$row['clsfItem']]))
			{
				$clsf [$row['group']][] = [
					'dstTableId' => 'e10.base.clsfitems', 'dstRecId' => $row['clsfItem'],
					'title' => $clsfItems [$row['group']][$row['clsfItem']]['name']
				];
			}
			//else
			//	error_log("Invalid clsf: ".json_encode($row));
		}
	}
	return $clsf;
}


function loadClassification ($app, $tableId, $pkeys, $class = 'label label-info', $withIcons = FALSE, $withIds = FALSE)
{
	$c = array();
	if (is_int($pkeys))
		$recIds = strval($pkeys);
	else
	{
		if (!count($pkeys))
			return [];
		$recIds = implode(', ', $pkeys);
	}
	$clsfGroups = $app->cfgItem ('e10.base.clsfGroups');
	$clsfItems = $app->cfgItem ('e10.base.clsf');

	$query = $app->db->query ("SELECT * from [e10_base_clsf] where [tableid] = %s AND [recid] IN ($recIds)", $tableId);
	forEach ($query as $r)
	{
		$clsfItem = $clsfItems [$r['group']][$r['clsfItem']];
		$i = ['text' => $clsfItem['name'], 'class' => $class, 'clsfItem' => $r['clsfItem']];
		if ($withIcons)
			$i ['icon'] = $clsfGroups [$r['group']]['icon'];
		if ($withIds === TRUE)
			$i ['id'] = $clsfItem['id'];
		if ($withIds === 'text')
			$i ['text'] = $clsfItem['id'];

		if (isset($clsfItem['css']))
			$i['css'] = $clsfItem['css'];

		$c [$r['recid']][$r['group']][] = $i;
	}

	return $c;
}


class ListClassification implements \E10\IDocumentList
{
	public $formData = NULL;
	public $recData;
	public $table;
	public $listId;
	public $listDefinition;
	public $listGroup = '';
	public $data = array ();
	public $allTags;
	public $options = 0;

	function init ()
	{
		$this->listDefinition = $this->table->listDefinition ($this->listId);
		$this->clsfItems = $this->table->app()->cfgItem ('e10.base.clsf');
	}

	function loadData ()
	{
		if (isset($this->recData ['ndx']) && $this->recData ['ndx'])
			$clsf = getClsf ($this->table->app(), $this->table->tableId(), $this->recData ['ndx'], false);
		else
			$clsf = [];

		$groups = $this->table->app()->cfgItem ('e10.base.clsfGroups');
		forEach ($groups as $key => $group)
		{
			if (!isset ($clsf[$key]))
				$clsf[$key] = array ();
		}

		$this->data = $clsf;

		if ($this->formData)
			$this->formData->checkLoadedList ($this);

		if ($this->formData)
			$this->formData->lists [$this->listId] = $this->data;
	}

	function saveData ($listData)
	{
		if (isset($this->recData ['ndx']) && $this->recData ['ndx'])
			$currentClsf = getClsf ($this->table->app(), $this->table->tableId(), $this->recData ['ndx'], true);
		else
			$currentClsf = [];
		$newClsf = [];

		if ($listData != '')
		{
			forEach ($listData as $g => $itms)
			{
				if (isset ($itms ['e10.base.clsfitems']))
					$newClsf [$g] = $itms ['e10.base.clsfitems'];
			}
		}

		// new:
		unset ($g);
		unset ($itms);
		forEach ($newClsf as $g => $itms)
		{
			forEach ($itms as $i)
			{
				if ((!$currentClsf) || (!isset($currentClsf [$g])) || (array_search ($i, $currentClsf [$g]) === FALSE))
					$this->table->db()->query ("INSERT INTO [e10_base_clsf] ([tableid], [recid], [clsfItem], [group]) VALUES (%s, %i, %s, %s)",
																		 $this->table->tableId(), $this->recData ['ndx'], $i, $g);
			}
		}

		// deleted:
		unset ($g);
		unset ($itms);
		forEach ($currentClsf as $g => $itms)
		{
			forEach ($itms as $ndx => $i)
			{
				if (!isset($newClsf [$g]) || (!in_array ($i, $newClsf [$g])))
					$this->table->db()->query ("DELETE FROM [e10_base_clsf] where [ndx] = %i", $ndx);
			}
		}
	}

	function setRecord ($listId, \Shipard\Form\TableForm $formData)
	{
		$this->listId = $listId;
		$this->formData = $formData;
		$this->recData = $formData->recData;
		$this->table = $formData->table;
		$this->init ();
	}

	function setRecData ($table, $listId, $recData)
	{
		$this->listId = $listId;
		$this->recData = $recData;
		$this->table = $table;
		$this->init ();
	}

	function createHtmlCode ($options = 0)
	{
		$this->options |= $options;

		$this->loadData ();

		$readOnlyParam = '';
		if ($this->formData->readOnly || $options & TableForm::coReadOnly)
			$readOnlyParam = " readonly='readonly'";

		$groups = $this->table->getCfgItem ('e10.base.clsfGroups', $this);
		forEach ($groups as $key => $group)
		{
			if (!isset ($this->clsfItems [$key]) || !count($this->clsfItems [$key]))
				continue;
			if (isset ($group ['tables']) && !in_array ($this->table->tableId(), $group ['tables']))
				continue;
			if (isset ($group ['groups']))
			{
				$exist = false;
				forEach ($group ['groups'] as $sg)
				{
					if (in_array ($sg, $this->formData->groups))
					{
						$exist = true;
						break;
					}
				}
				if (!$exist)
					continue;
			}

			$inputLabelText = (isset($group['label'])) ? $group['label'] : $group['name'];
			$inputLabel = $inputLabelText;
			$inputHint = '';

			if ($this->formData->activeLayout === TableForm::ltGrid)
			{
				$inputLabel = NULL;
				$inputHint = $this->formData->columnOptionsHints ($options);
			}

			$inputId = str_replace ('.', '-', "inp_lists_{$this->listId}_$key");
			$inputName = "lists.{$this->listId}.$key";

			$inputCode = '';
			$inputCode .= "<div id='$inputId' class='e10-inputDocLink' data-name='$inputName'
											data-srctable='{$this->table->tableId()}' data-listid='{$this->listId}'
											data-listgroup='$key' data-fid='{$this->formData->fid}'$readOnlyParam>";
			$inputCode .= "<span class='placeholder'>" . '...' . '</span>';
			$inputCode .= '<ul>';
			$inputCode .= "<li class='input'><input class='e10-inputListSearch e10-viewer-search' style='width: 10ex' data-sid='{$this->formData->fid}Sidebar'></li>";
			$inputCode .= '</ul>';
			$inputCode .= '</div>';
			if ($this->formData->activeLayout === TableForm::ltGrid)
				$inputCode .= "<label class='gll'>".utils::es($inputLabelText).'</label>';

			$this->formData->appendElement ($inputCode, $inputLabel, $inputHint);
		}

		return FALSE;
	}

	function renderSidebar ($listGroup)
	{
		$sideBar = new FormSidebar ($this->table->app());

		$comboParams = $this->formData->listParams ($this->table->tableId(), $this->listId, $listGroup, $this->recData);
		$comboParams['group'] = $listGroup;

		$browseTable = $this->table->app()->table ('e10.base.clsfitems');
		$viewer = $browseTable->getTableView ("default", $comboParams);
		$viewer->objectSubType = TableView::vsMini;
		$viewer->enableFullTextSearch = FALSE;
		$viewer->comboSettings = array ('column' => 'TEST');
		$viewer->renderViewerData ("html");

		$sideBar->addTab('1', $browseTable->tableName());
		$sideBar->setTabContent('1', $viewer->createViewerCode ("html", "fullCode"));

		return $sideBar->createHtmlCode();
	}

	static function referenceWidget ($form, $srcColumnId, $table, $recId)
	{
		$today = Utils::today('Y-m-d');

		$listObject = new ListClassification($table->app());
		$listObject->setRecData ($table, 'clsf', array ('ndx'=>$recId));
		$listObject->loadData();

		$clsf = getClsf ($table->app(), $table->tableId(), $recId, false);
		$groups = $table->app()->cfgItem ('e10.base.clsfGroups');
		$clsfItems = $table->app()->cfgItem ('e10.base.clsf');

		forEach ($groups as $key => $group)
		{
			if (!isset ($clsf[$key]))
				$clsf[$key] = array ();
		}

		$result = array('html' => '');
		forEach ($groups as $key => $group)
		{
			if (!isset ($group ['onReferences']))
				continue;
			if (!isset ($clsf [$key]))
				continue;
			if (isset ($group ['tables']) && !in_array ($table->tableId(), $group ['tables']))
				continue;

			forEach ($clsf[$key] as $clsfItemOn)
			{
				$result['html'] .= "<span class='label label-info' style='font-size: 90%;'>{$clsfItemOn['title']}</span> ";
				$result['itemsOn'][$key][$clsfItemOn['dstRecId']] = $clsfItemOn;
			}

			if (isset($clsfItems[$key]))
			{
				forEach ($clsfItems[$key] as $clsfItemOff)
				{
					if (isset ($result['itemsOn'][$key][$clsfItemOff['ndx']]))
						continue;
					if (isset($clsfItemOff['validTo']) && $clsfItemOff['validTo'] < $today)
						continue;
					$inputName = "extra.clsf.$key:$srcColumnId.".$clsfItemOff['ndx'];
					$inputId = str_replace ('.', '-', "{$form->fid}_$inputName");
					$inputValue = $table->tableId().':'.$recId;
					$result['html'] .= "<span style='border: 1px solid #aaa; background-color: #eee!important;padding: 3px; display: inline-block;'><input type='checkbox' class='e10-inputLogical' name='$inputName' id='$inputId' data-fid='$form->fid' value='$inputValue'/><label for='$inputId'> ".Utils::es($clsfItemOff['name']).'</label></span>';
				}
			}
		}
		return $result;
	}
}


/* ------------
 * ListDocLinks
 * ------------
 */

function getDocLinks ($app, $tableId, $recId, $simple = FALSE)
{
	$query = $app->db->query ("SELECT [ndx], [linkId], [dstTableId], [dstRecId] from [e10_base_doclinks] where [srcTableId] = %s AND [srcRecId] = %i",
														$tableId, $recId);

	if ($simple)
	{
		$links = array ('recs' => array (), 'ndx' => array ());

		foreach ($query as $row)
		{
			$links ['recs'][$row['linkId']][$row['dstTableId']][] = $row['dstRecId'];
			$links ['ndx'][$row['linkId']][$row['dstTableId']][$row['dstRecId']] = $row['ndx'];
		}
		return $links;
	}

	$links = [];

	// primary keys for record info
	$pks = [];
	$recInfo = [];
	foreach ($query as $row)
	{
		$pks [$row['dstTableId']][] = $row['dstRecId'];
	}
	// record info
	forEach ($pks as $dstTableId => $ndxs)
	{
		$dt = $app->table($dstTableId);
		$recInfo [$dstTableId] = $dt->loadDocsList($ndxs);
	}

	// -- links
	$allLinks = $app->cfgItem ('e10.base.doclinks', NULL);
	$linksCfg = ($allLinks && isset($allLinks[$tableId])) ? $allLinks[$tableId] : [];
	foreach ($query as $row)
	{
		if ($row['dstTableId'] === '_CfgItemTable')
		{
			$ld = $linksCfg[$row['linkId']];
			$sd = $ld['sources'][0];
			$title = $app->cfgItem ($sd['cfgItem'].'.'.$row['dstRecId'].'.'.$sd['textKey'], '!!!'.$row['dstRecId']);
		}
		else
			$title = $recInfo[$row['dstTableId']][$row['dstRecId']]['title'];

		$links [$row['linkId']][] = [
				'dstTableId' => $row['dstTableId'], 'dstRecId' => $row['dstRecId'],
				'title' => $title
		];
	}
	return $links;
}


class ListDocLinks implements \E10\IDocumentList
{
	public $formData = NULL;
	public $recData;
	public $table;
	public $listId;
	public $listDefinition;
	public $data = array ();
	public $allLinks = array ();
	public $options = 0;

	function init ()
	{
		$this->listDefinition = $this->table->listDefinition ($this->listId);
		//$this->clsfItems = $this->table->app()->cfgItem ('e10.base.clsf');
	}

	function getAllLinks ()
	{
		$links = $this->table->app()->cfgItem ('e10.base.doclinks', NULL);

		if (!$links)
			return array();
		if (!isset($links [$this->table->tableId()]))
			return array();
		$allLinks = $links [$this->table->tableId()];

		$filteredLinks = array ();
		foreach ($allLinks as $linkKey => $linkDef)
		{
			if (isset ($linkDef['queryCol']))
			{
				if (isset($linkDef['queryColValue']) && $this->recData[$linkDef['queryCol']] != $linkDef['queryColValue'])
					continue;
				if (isset($linkDef['queryColValues']) && !in_array($this->recData[$linkDef['queryCol']], $linkDef['queryColValues']))
					continue;
			}

			if (isset ($linkDef['listId']) && $linkDef['listId'] !== $this->listId)
				continue;

			if (($this->formData) && (!$this->formData->docLinkEnabled ($linkDef)))
				continue;

			$filteredLinks[$linkKey] = $linkDef;
		}
		return $filteredLinks;
	}

	function loadDocLinks ($app, $tableId, $recId, $simple = FALSE)
	{
		if (!count($this->allLinks))
			return [];

		$q = [];
		array_push($q, 'SELECT [ndx], [linkId], [dstTableId], [dstRecId] FROM [e10_base_doclinks]');
		array_push($q, ' WHERE [srcTableId] = %s', $tableId, ' AND [srcRecId] = %i', $recId);
		array_push($q, ' AND [linkId] IN %in', array_keys($this->allLinks));

		$rows = $app->db->query ($q);

		if ($simple)
		{
			$links = ['recs' => [], 'ndx' => []];

			foreach ($rows as $row)
			{
				$links ['recs'][$row['linkId']][$row['dstTableId']][] = $row['dstRecId'];
				$links ['ndx'][$row['linkId']][$row['dstTableId']][$row['dstRecId']] = $row['ndx'];
			}
			return $links;
		}

		$links = [];

		// primary keys for record info
		$pks = [];
		$recInfo = [];
		foreach ($rows as $row)
		{
			$pks [$row['dstTableId']][] = $row['dstRecId'];
		}
		// record info
		forEach ($pks as $dstTableId => $ndxs)
		{
			$dt = $app->table($dstTableId);
			$recInfo [$dstTableId] = $dt->loadDocsList($ndxs);
		}

		// -- links
		$allLinks = $app->cfgItem ('e10.base.doclinks', NULL);
		$linksCfg = ($allLinks && isset($allLinks[$tableId])) ? $allLinks[$tableId] : [];
		foreach ($rows as $row)
		{
			if ($row['dstTableId'] === '_CfgItemTable')
			{
				$ld = $linksCfg[$row['linkId']];
				$sd = $ld['sources'][0];
				$title = $app->cfgItem ($sd['cfgItem'].'.'.$row['dstRecId'].'.'.$sd['textKey'], '!!!'.$row['dstRecId']);
			}
			else
				$title = $recInfo[$row['dstTableId']][$row['dstRecId']]['title'];

			$links [$row['linkId']][] = [
				'dstTableId' => $row['dstTableId'], 'dstRecId' => $row['dstRecId'],
				'title' => $title
			];
		}
		return $links;
	}

	function loadData ()
	{
		$this->allLinks = $this->getAllLinks ();
		if (isset($this->recData ['ndx']) && $this->recData ['ndx'])
			$links = $this->loadDocLinks ($this->table->app(), $this->table->tableId(), $this->recData ['ndx']);
		else
			$links = [];

		forEach ($this->allLinks as $linkDef)
		{
			$key = $linkDef['linkid'];
			if (!isset ($links[$key]))
				$links[$key] = array ();
		}

		$this->data = $links;

		if ($this->formData)
		{
			$this->formData->checkLoadedList ($this);
			$this->formData->lists [$this->listId] = $this->data;
		}
	}

	function renderSidebar ($listGroup)
	{
		$this->allLinks = $this->getAllLinks ();
		$thisLink = $this->allLinks [$listGroup];

		$sideBar = new FormSidebar ($this->table->app());

		forEach ($thisLink ['sources'] as $src)
		{
			$comboParams = $this->formData->listParams ($this->table->tableId(), $this->listId, $listGroup, $this->recData);
			if (isset($src['comboParams']))
				$comboParams = $src['comboParams'];

			$comboParams['comboRecData'] = [];
			foreach ($this->recData as $key => $value)
			{
				if (is_string($value) && strlen($value) > 128)
					continue;
				$comboParams['comboRecData'][$key] = $value;
			}

			if (isset($src['table']))
			{
				$browseTable = $this->table->app()->table($src['table']);
				$viewerId = (isset ($src['viewer'])) ? $src['viewer'] : 'default';
				$viewer = $browseTable->getTableView ($viewerId, $comboParams);
			}
			else
			{
				$browseTable = new \e10\CfgItemTable($this->table->app());
				$viewer = new \e10\CfgItemViewer($browseTable, $src['cfgItem'].':'.$src['textKey'], $comboParams);
				$viewer->setCfgItem($src['cfgItem'], $src['textKey']);
				$viewer->init ();
				$viewer->selectRows ();
			}
			$viewer->objectSubType = TableView::vsMini;
			$viewer->comboSettings = array ('column' => 'TEST');
			$viewer->enableFullTextSearch = FALSE;
			$viewer->renderViewerData ("html");

			$id = isset($src ['id']) ? $src ['id'] : '1';
			$sideBar->addTab($id, $browseTable->tableName());
			$sideBar->setTabContent($id, $viewer->createViewerCode ("html", "fullCode"));
		}
		return $sideBar->createHtmlCode();
	}

	function saveData ($listData)
	{
		$this->allLinks = $this->getAllLinks ();
		$currentData = $this->loadDocLinks ($this->table->app(), $this->table->tableId(), $this->recData ['ndx'], TRUE);
		$currentLinks = isset($currentData ['recs']) ? $currentData ['recs'] : [];
		// new: {"e10pro-wkf-message-notify":{"e10.persons.persons":["6","20","10","14"]}}
		forEach ($listData as $groupId => $groupData)
		{
			forEach ($groupData as $dstTableId => $dstTableRecIds)
			{
				forEach ($dstTableRecIds as $dstRecId)
				{
					if (isset ($currentLinks[$groupId]) && isset ($currentLinks[$groupId][$dstTableId]) && in_array ($dstRecId, $currentLinks[$groupId][$dstTableId]))
						continue;
					$this->table->db()->query ("INSERT INTO [e10_base_doclinks] ([linkId], [srcTableId], [srcRecId], [dstTableId], [dstRecId]) VALUES (%s, %s, %i, %s, %i)",
									$groupId, $this->table->tableId(), $this->recData ['ndx'], $dstTableId, $dstRecId);
				}
			}
		}

		// deleted:
		forEach ($currentLinks as $groupId => $groupData)
		{
			forEach ($groupData as $dstTableId => $dstTableRecIds)
			{
				forEach ($dstTableRecIds as $dstRecId)
				{
					if (isset ($listData[$groupId]) && isset ($listData[$groupId][$dstTableId]) && in_array ($dstRecId, $listData[$groupId][$dstTableId]))
						continue;
					$this->table->db()->query ("DELETE FROM [e10_base_doclinks] WHERE [ndx] = %i",
																		 $currentData ['ndx'][$groupId][$dstTableId][$dstRecId]);
				}
			}
		}
	}

	function setRecord ($listId, \Shipard\Form\TableForm $formData)
	{
		$this->listId = $listId;
		$this->formData = $formData;
		$this->recData = $formData->recData;
		$this->table = $formData->table;
		$this->init ();
	}

	function setRecData ($table, $listId, $recData)
	{
		$this->listId = $listId;
		$this->recData = $recData;
		$this->table = $table;
		$this->init ();
	}

	function createHtmlCode ($options = 0)
	{
		$this->options |= $options;
		$this->loadData ();

		forEach ($this->allLinks as $linkDef)
		{
			$inputId = str_replace ('.', '-', "{$this->formData->fid}_inp_lists_{$this->listId}_{$linkDef['linkid']}");
			$inputName = "lists.{$this->listId}.{$linkDef['linkid']}";

			$readOnlyParam = '';
			if ($this->formData->readOnly || $options & TableForm::coReadOnly)
				$readOnlyParam = " readonly='readonly'";

			$inputLabel = $linkDef ['name'];
			$inputHint = '';

			if ($this->formData->activeLayout === TableForm::ltGrid)
			{
				$inputLabel = NULL;
				$inputHint = $this->formData->columnOptionsHints ($options);
			}

			$inputClass = 'e10-inputDocLink';
			if (isset($this->listDefinition['saveOnChange']))
				$inputClass .= ' e10-ino-saveOnChange';
			$inputCode = '';
			$inputCode .= "<div id='$inputId' class='$inputClass' data-name='$inputName'
											data-srctable='{$this->table->tableId()}' data-listid='{$this->listId}'
											data-listgroup='{$linkDef['linkid']}' data-fid='{$this->formData->fid}'$readOnlyParam>";
			$inputCode .= "<span class='placeholder'>" . '...' . '</span>';
			$inputCode .= '<ul>';
			$inputClass = '';
			if ($options & TableForm::coFocus)
			{
				$inputClass = ' autofocus';
				$this->formData->setFlag ('autofocus', 1);
			}
			$inputCode .= "<li class='input'><input class='e10-inputListSearch e10-viewer-search$inputClass' style='width: 10ex' data-sid='{$this->formData->fid}Sidebar'></li>";
			$inputCode .= '</ul>';
			$inputCode .= '</div>';
			if ($this->formData->activeLayout === TableForm::ltGrid)
				$inputCode .= "<label class='gll'>".utils::es($linkDef ['name']).'</label>';

			$this->formData->appendElement ($inputCode, $inputLabel, $inputHint);
		}

		return FALSE;
	}
}


function linkedPersons ($app, $table, $toRecId, $elementClass = '')
{
	if (is_string($table))
		$tableId = $table;
	else
		$tableId = $table->tableId ();

	$links = $app->cfgItem ('e10.base.doclinks', NULL);

	if (!$links)
		return array();
	if (!isset($links [$tableId]))
		return array();
	$allLinks = $links [$tableId];

	$lp = array ();

	if (is_array($toRecId))
	{
		if (count($toRecId) === 0)
			return $lp;
		$recs = implode (', ', $toRecId);
		$sql = "(SELECT links.ndx, links.linkId as linkId, links.srcRecId as srcRecId, links.dstRecId as dstRecId, persons.fullName as fullName, persons.company as company from e10_base_doclinks as links " .
						"LEFT JOIN e10_persons_persons as persons ON links.dstRecId = persons.ndx " .
						"where srcTableId = %s AND dstTableId = 'e10.persons.persons' AND links.srcRecId IN ($recs))" .
						" UNION ".
						"(SELECT links.ndx, links.linkId as linkId, links.srcRecId as srcRecId, 0, groups.name as fullName, 3 from e10_base_doclinks as links " .
						"LEFT JOIN e10_persons_groups as groups ON links.dstRecId = groups.ndx " .
						"where srcTableId = %s AND dstTableId = 'e10.persons.groups' AND links.srcRecId IN ($recs))";
	}
	else
	{
		$recId = intval($toRecId);
		$sql = "(SELECT links.ndx, links.linkId as linkId, links.srcRecId as srcRecId, links.dstRecId as dstRecId, persons.fullName as fullName, persons.company as company from e10_base_doclinks as links " .
						"LEFT JOIN e10_persons_persons as persons ON links.dstRecId = persons.ndx " .
						"where srcTableId = %s AND dstTableId = 'e10.persons.persons' AND links.srcRecId = $recId)" .
						" UNION ".
						"(SELECT links.ndx, links.linkId as linkId, links.srcRecId as srcRecId, 0, groups.name as fullName, 3 from e10_base_doclinks as links " .
						"LEFT JOIN e10_persons_groups as groups ON links.dstRecId = groups.ndx " .
						"where srcTableId = %s AND dstTableId = 'e10.persons.groups' AND links.srcRecId = $recId)";
	}

	$query = $app->db->query ($sql, $tableId, $tableId);

	foreach ($query as $r)
	{
		$icon = 'icon-sign-blank';
		if (isset ($allLinks [$r['linkId']]['icon']))
			$icon = $allLinks [$r['linkId']]['icon'];
		if (isset ($lp [$r['srcRecId']][$r['linkId']]))
			$lp [$r['srcRecId']][$r['linkId']][0]['text'] .= ', '.$r ['fullName'];
		else
			$lp [$r['srcRecId']][$r['linkId']][0] = ['icon' => $icon, 'text' => $r ['fullName'], 'class' => $elementClass];
		if ($r['dstRecId'])
			$lp [$r['srcRecId']][$r['linkId']][0]['pndx'][] = $r['dstRecId'];
	}

	return $lp;
}



/**
 * Detail s historií dokumentu
 *
 */

class ViewDetailDocHistory extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10.base.docslog', 'e10.base.ViewDocsLogDoc',
														 array ('recid' => $this->item ['ndx'], 'tableid' => $this->tableId()));
	}
} // class ViewDetailDocHistory


/**
 * Class NotificationCentre
 * @package E10\Base
 */
class NotificationCentre extends \Shipard\UI\Core\WidgetPane
{
	var $viewer = NULL;

	var $ntfEmoji = [
		0 => '🔆', 1 => '*️⃣', 2 => '✅', 4 => '🚫', 5 => '✖️', 90 => '⚠️', 91 => '📥', 92 => '💬'
	];

	public function broadcast ($msgId, $sender)
	{
		$this->viewer = $sender;

		$this->objectData['ntfBadges'] = $this->notificationsBadges();

		$cntNotifications = 0;
		if (isset($this->objectData['ntfBadges']['ntf-badge-app-total']))
			$cntNotifications = $this->objectData['ntfBadges']['ntf-badge-app-total'];
		else
			$cntNotifications = $this->viewer->countRows;

		$this->objectData['cntNotifications'] = $cntNotifications;

		if ($cntNotifications === 0)
			$this->objectData['cntNotificationsText'] = '';
		elseif ($cntNotifications > 50)
			$this->objectData['cntNotificationsText'] = '50+';
		else
			$this->objectData['cntNotificationsText'] = strval ($cntNotifications);

		if (isset($this->viewer->objectData['staticContent']))
			$this->objectData['runningActivities'] = 1;
		else
			$this->objectData['runningActivities'] = 0;

		if ($this->viewer->countRows > 0)
		{
			foreach ($this->viewer->objectData ['dataItems'] as $msg)
			{
				if (!$msg['notified'])
				{
					$title = $this->app->cfgItem ('options.core.ownerShortName', '');
					if (isset($msg['personName']) && $msg['personName'] !== '')
						$title .= ' / '.$msg['personName'];
					$body = '';
					if ($msg['subject'] !== '')
						$body = $msg['subject'];
					elseif ($msg['ntfTypeName'] !== '')
					 $body .= $msg['ntfTypeName'];
					//$body .= $msg['text'];

					if (isset($this->ntfEmoji[$msg['ntfType']]))
						$body = $this->ntfEmoji[$msg['ntfType']].' '.$body;

					$this->objectData['notifications'] = [['msg' => $body, 'title' => $title, 'desktop' => 1, 'icon' => $this->app()->ui()->icons()->cssClass($msg['icon'])]];
					$this->app->db()->query ('UPDATE [e10_base_notifications] SET [notified] = NOW() WHERE ndx = %i', $msg['ndx']);
					break;
				}
			}
		}
	}

	public function createContent ()
	{
		if (substr($this->widgetAction, 0, 5) === 'drop-')
		{
			$parts = explode ('-', $this->widgetAction);
			$ndx = intval ($parts[1]);
			if ($ndx)
				$this->app->db()->query ('UPDATE [e10_base_notifications] SET [state] = 1 WHERE ndx = %i', $ndx);
		}

		$this->addContentViewer ('e10.base.notifications', 'nc', []);
	}

	public function title () {return FALSE;}

	public function isBlank ()
	{
		if ($this->viewer && $this->viewer->countRows === 0)
			return TRUE;
		return parent::isBlank();
	}

	function notificationsBadges()
	{
		$badges = ['ntf-badge-wkf-total' => 0, 'ntf-badge-app-total' => 0];

		$q[] = 'SELECT issues.section, sections.parentSection, COUNT(*) AS [cnt] ';
		array_push($q, ' FROM e10_base_notifications AS ntf');
		array_push($q, ' LEFT JOIN wkf_core_issues AS issues ON ntf.recIdMain = issues.ndx');
		array_push($q, ' LEFT JOIN wkf_base_sections AS sections ON issues.section = sections.ndx');
		array_push($q, ' LEFT JOIN wkf_base_sections AS topSections ON sections.parentSection = topSections.ndx');
		array_push($q, ' WHERE tableId = %s', 'wkf.core.issues', ' AND state = 0 AND ntf.personDest = %s', $this->app()->userNdx());
		array_push($q, ' GROUP BY issues.section, sections.parentSection');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$badges['ntf-badge-wkf-total'] += $r['cnt'];
			$badges['ntf-badge-app-total'] += $r['cnt'];

			$badgeId = 'ntf-badge-wkf-s'.$r['section'];
			if (!isset($badges[$badgeId]))
				$badges[$badgeId] = $r['cnt'];
			else
				$badges[$badgeId] += $r['cnt'];

			$badgeId = 'ntf-badge-wkf-s'.$r['parentSection'];
			if (!isset($badges[$badgeId]))
				$badges[$badgeId] = $r['cnt'];
			else
				$badges[$badgeId] += $r['cnt'];
		}

		$badges['ntf-badge-wkf-start'] = $badges['ntf-badge-wkf-total'];

		// -- unused sections
		/** @var \wkf\base\TableSections $tableSections */
		$tableSections = $this->app->table ('wkf.base.sections');
		$usersSections = $tableSections->usersSections();
		foreach ($usersSections['all'] as $sectionNdx => $s)
		{
			$badgeId = 'ntf-badge-wkf-s'.$sectionNdx;
			if (!isset($badges[$badgeId]))
				$badges[$badgeId] = 0;
		}

		// -- hosting - TODO: move to better place
		if ($this->app->model()->module ('hosting.core') !== FALSE)
		{
			// -- data sources
			$badges['ntf-badge-hosting-dbs'] = 0;

			$q = [];
			array_push ($q, 'SELECT [summary].[cntUnread], [summary].[cntTodo], [ds].[gid]');
			array_push ($q, ' FROM [hosting_core_dsUsersSummary] AS [summary]');
			array_push ($q, ' LEFT JOIN [hosting_core_dataSources] AS [ds] ON [summary].dataSource = [ds].ndx');
			array_push ($q, ' WHERE [user] = %i', $this->app->userNdx());
			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$badges['ntf-badge-hosting-dbs'] += $r['cntUnread'];
				$badges['ntf-badge-app-total'] += $r['cntUnread'];
				$badges['ntf-badge-unread-ds-'.$r['gid']] = $r['cntUnread'];
				$badges['ntf-badge-todo-ds-'.$r['gid']] = $r['cntTodo'];
			}

			// -- helpdesk - total
			$q = [];
			$q[] = 'SELECT COUNT(*) AS [cnt] ';
			array_push($q, ' FROM e10_base_notifications AS ntf');
			array_push($q, ' WHERE tableId = %s', 'helpdesk.core.tickets', ' AND state = 0 AND ntf.personDest = %s', $this->app()->userNdx());
			$hdc = $this->db()->query($q)->fetch();

			$badges['ntf-badge-hhdsk-total'] = intval($hdc['cnt'] ?? 0);
		}
		else
		{ // hosting helpdesk notifications
			if ($this->app()->hasRole('hdhstng'))
				$this->getHostingHelpdeskNotifications($badges);
		}

		return $badges;
	}

	function getHostingHelpdeskNotifications(&$badges)
	{
		$cfgServer = Utils::loadCfgFile(__SHPD_ETC_DIR__.'/server.json');
		if (!$cfgServer)
      return;

		$dsId = $this->app()->cfgItem ('dsid', 0);
		$userLoginHash = md5(strtolower(trim($this->app()->user()->data('login'))));

		$url = 'https://'.$cfgServer['hostingDomain'].'/api/objects/call/hosting-helpdesk-notifications?dsId='.$dsId.'&userLoginHash='.$userLoginHash;

		$ce = new \lib\objects\ClientEngine($this->app());
		$ce->apiKey = $cfgServer['hostingApiKey'];
		$result = $ce->apiCall($url);

		if (isset($result['success']) && $result['success'] === 1)
		{
			if (isset($result['badges']))
			{
				foreach ($result['badges'] as $bk => $bv)
				{
					$badges[$bk] = $bv;
				}
			}
		}
	}
}

