<?php
namespace wkf\core\libs;

use e10\Utility, e10\utils, wkf\core\TableIssues, \lib\persons\LinkedPersons, \e10\str, \e10\json;


/**
 * Class IssueFiltering
 * @package wkf\core\libs
 */
class IssueFiltering extends Utility
{
	var $issueRecData = NULL;
	var $issueSystemInfo = NULL;

	var $protocol = [];

	var $debug = 0;
	var $dryRun = 0;


	var $filtersQueryTypes;
	var $issueDocStates;

	var $personsLinksIds = [
		'wkf-filters-add-from' => 'wkf-issues-from',
		'wkf-filters-add-to' => 'wkf-issues-to',
		'wkf-filters-add-notify' => 'wkf-issues-notify',
		'wkf-filters-add-assigned' => 'wkf-issues-assigned',
	];

	public function setIssue($issueRecData)
	{
		$this->issueRecData = $issueRecData;
		if (isset($this->issueRecData['systemInfo']) && $this->issueRecData['systemInfo'] !== '' && $this->issueRecData['systemInfo'])
			$this->issueSystemInfo = json_decode($this->issueRecData['systemInfo'], TRUE);
		if (!$this->issueSystemInfo)
			$this->issueSystemInfo = [];

		$this->filtersQueryTypes = $this->app()->cfgItem ('wkf.filters.queryTypes');
		$this->issueDocStates = $this->app()->cfgItem ('wkf.issues.docStates.default');
	}

	public function applyFilters()
	{
		$q[] = 'SELECT * FROM [wkf_core_filters]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docState] = %i', 4000);
		array_push($q, ' AND (qrySectionType = %i', 0, ' OR qrySectionValue = %i', $this->issueRecData['section'], ')');
		array_push($q, ' ORDER BY [order], [fullName], [stopAfterApply] DESC, [ndx]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->addProtocolItem("-- filter #{$r['ndx']}: {$r['fullName']}");
			if (!$this->test($r))
				continue;

			$this->apply($r);

			if ($r['stopAfterApply'])
			{
				$this->addProtocolItem("   STOP; filter has `stopAfterApply`");
				break;
			}
		}
	}

	function test($filter)
	{
		if ($filter['qrySectionType'] == 1 && $this->issueRecData['section'] != $filter['qrySectionValue'])
			return FALSE;

		if (!$this->testStringValue('subject', $filter['qrySubjectType'], $filter['qrySubjectValue'], $this->issueRecData['subject']))
			return FALSE;
		if (!$this->testStringValue('text', $filter['qryTextType'], $filter['qryTextValue'], $this->issueRecData['text']) &&
				!$this->testStringValue('text', $filter['qryTextType'], $filter['qryTextValue'], $this->issueRecData['body']))
			return FALSE;

		// -- emails
		if (!$this->testEmailAddress ('email-from', 'from', $filter['qryEmailFromType'], $filter['qryEmailFromValue']))
			return FALSE;
		if (!$this->testEmailAddress ('email-to', 'to', $filter['qryEmailToType'], $filter['qryEmailToValue']))
			return FALSE;

		return TRUE;
	}

	function apply($filter)
	{
		$update = [];
		if ($filter['actionSetSection'])
		{
			$update['section'] = $filter['actionSetSectionValue'];
			$this->addProtocolItem("   SET `section` to #{$filter['actionSetSectionValue']}");
		}
		if ($filter['actionSetIssueKind'])
		{
			$update['issueKind'] = $filter['actionSetIssueKindValue'];
			$this->addProtocolItem("   SET `issueKind` to #{$filter['actionSetIssueKindValue']}");

			$issueKindCfg = $this->app()->cfgItem ('wkf.issues.kinds.'.$update['issueKind'], NULL);
			$update['issueType'] = $issueKindCfg['issueType'];
			$this->addProtocolItem("   SET `issueType` to #{$update['issueType']}");
		}
		if ($filter['actionSetPriority'])
		{
			$update['priority'] = $filter['actionSetPriorityValue'];
			$this->addProtocolItem("   SET `priority` to #{$filter['actionSetPriorityValue']}");
		}
		if ($filter['actionSetDocState'])
		{
			$update['docState'] = $filter['actionSetDocStateValue'];
			$update['docStateMain'] = $this->issueDocStates[$update['docState']]['mainState'];
			$this->addProtocolItem("   SET `docState` to {$filter['actionSetDocStateValue']}");
			$this->addProtocolItem("   SET `docStateMain` to {$update['docStateMain']}");
		}

		$updateSql = ['UPDATE [wkf_core_issues] SET ', $update, ' WHERE [ndx] = %i', $this->issueRecData['ndx']];
		if ($this->dryRun)
		{
			$this->addProtocolItem("  DRY RUN: ");
			$this->db()->test($updateSql);
		}
		else
		{
			$this->db()->query($updateSql);
			$this->addProtocolItem("   SQL COMMAND: ".\dibi::$sql);
		}

		// -- persons
		$pq[] = 'SELECT * FROM [e10_base_doclinks]';
		array_push ($pq, ' WHERE [srcRecId] = %i', $filter['ndx']);
		array_push ($pq, ' AND [srcTableId] = %s', 'wkf.core.filters');
		array_push ($pq, ' AND [dstTableId] IN %in', ['e10.persons.persons', 'e10.persons.groups']);

		$rows = $this->db()->query($pq);
		foreach ($rows as $r)
		{
			$linkId = $this->personsLinksIds[$r['linkId']];

			$newLink = [
				'linkId' => $linkId,
				'srcTableId' => 'wkf.core.issues', 'srcRecId' => $this->issueRecData['ndx'],
				'dstTableId' => $r['dstTableId'], 'dstRecId' => $r['dstRecId']
			];

			$this->addProtocolItem("     persons: add link ".json_encode($newLink));

			$exist = $this->db()->query('SELECT ndx FROM [e10_base_doclinks]',
				' WHERE [linkId] = %s', $newLink['linkId'],
				' AND [srcTableId] = %s', $newLink['srcTableId'], ' AND [srcRecId] = %i', $newLink['srcRecId'],
				' AND [dstTableId] = %s', $newLink['dstTableId'], ' AND [dstRecId] = %i', $newLink['srcRecId'])->fetch();

			if ($exist)
			{
				$this->addProtocolItem("       --> link exists");
				continue;
			}

			$insertSql = ['INSERT INTO [e10_base_doclinks] ', $newLink];
			if ($this->dryRun)
			{
				$this->addProtocolItem("       DRY RUN: ");
				$this->db()->test($insertSql);
			}
			else
			{
				$this->db()->query($insertSql);
				$this->addProtocolItem("        SQL COMMAND: ".\dibi::$sql);
			}
		}
	}

	function testStringValue ($testId, $qryType, $settingsValue, $docValue)
	{
		$res = FALSE;
		if ($qryType == 0)
			$res = TRUE; // not important
		elseif ($qryType == 1 && str::strcasecmp($settingsValue, $docValue) === 0)
			$res = TRUE; // is equal
		elseif ($qryType == 2 && str::stristr($docValue, $settingsValue) !== FALSE)
			$res = TRUE; // has
		elseif ($qryType == 3 && str::strStartsI($docValue, $settingsValue))
			$res = TRUE; // starts with
		elseif ($qryType == 4 && str::strEndsI($docValue, $settingsValue))
			$res = TRUE; // ends with

		if ($qryType !== 0)
			$this->addProtocolItem("   test `{$testId}`[{$docValue}] {$this->filtersQueryTypes['string'][$qryType]['name']} `{$settingsValue}`: ".($res ? 'TRUE':'FALSE'));

		return $res;
	}

	function testEmailAddress ($testId, $emailKey, $qryType, $settingsValue)
	{
		$res = FALSE;

		if ($qryType == 0)
			$res = TRUE; // not important

		$addrs = [];
		if (isset($this->issueSystemInfo['email']) && isset($this->issueSystemInfo['email'][$emailKey]))
		{
			foreach ($this->issueSystemInfo['email'][$emailKey] as $ea)
			{
				if (isset($ea['address']) && $ea['address'] !== '')
					$addrs[] = $ea['address'];
				if (isset($ea['name']) && $ea['name'] !== '')
					$addrs[] = $ea['name'];
			}
		}

		foreach ($addrs as $a)
		{
			if ($this->testStringValue($testId, $qryType, $settingsValue, $a))
				return TRUE;
		}

		return $res;
	}

	function addProtocolItem ($item)
	{
		$this->protocol[] = $item;
		if ($this->debug)
			echo $item."\n";
	}
}
