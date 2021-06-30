<?php

namespace lib\objects;
use \Shipard\Base\Service, e10\Response, \e10\str, \e10\json;


/**
 * Class ObjectsAlert
 * @package lib\objects
 */
class ObjectsAlert extends Service
{
	const psOK = 0, psEmptyData = 1, psParseError = 2, psUnknownType = 1;

	protected $status = ObjectsAlert::psOK;

	var $requestDataString = '';
	var $requestData = NULL;
	var $alertTypeCfg = NULL;
	var $alertId = '';

	var $alertData = [];

	var $result = [];

	protected function setStatus ($status, $msg = '')
	{
		$this->status = $status;
		$this->result ['errorCode'] = $status;
		$this->result ['errorMsg'] = $msg;

		return $status;
	}

	protected function setRequestData ($requestData = NULL)
	{
		if (!$requestData)
		{
			$this->requestDataString = $this->app->postData();
			if ($this->requestDataString == '')
				return $this->setStatus(self::psEmptyData, 'Empty POST data');

			$this->requestData = json_decode($this->requestDataString, TRUE);
			if ($this->requestData === NULL)
				return $this->setStatus(self::psParseError, 'Parse error: ' . json_last_error_msg());
		}
		else
			$this->requestData = $requestData;

		if (!isset($this->requestData['alertType']))
			return $this->setStatus(self::psUnknownType, 'Param `alertType` not found.');

		if (!isset($this->requestData['alertId']))
			return $this->setStatus(self::psUnknownType, 'Param `alertId` not found.');

		$this->alertId = $this->requestData['alertId'];

		$at = $this->requestData['alertType'];

		$alerts = $this->app()->cfgItem ('alerts', []);
		if (!isset($alerts[$at]))
			return $this->setStatus(self::psUnknownType, 'Unknown `alertType`.');

		$this->alertTypeCfg = $alerts[$at];

		$this->result ['status'] = 1;
	}

	function prepareData()
	{
		if (!$this->result ['status'])
			return;

		$this->alertData['subject'] = isset($this->requestData['alertSubject']) ? str::upToLen($this->requestData['alertSubject'], 100) : '';
		$this->alertData['data'] = [];

		if (isset($this->requestData['content']))
			$this->alertData['data']['content'] = $this->requestData['content'];
		if (isset($this->requestData['payload']))
			$this->alertData['data']['payload'] = $this->requestData['payload'];

		$alertObject = $this->app()->createObject($this->alertTypeCfg['classId']);
		if ($alertObject)
		{
			$alertObject->checkAlert($this->requestData, $this->alertData);
		}
	}

	protected function response ()
	{
		if ($this->status === self::psOK)
			$this->result ['status'] = 1;
		$r = new Response($this->app, json_encode($this->result, JSON_PRETTY_PRINT));
		$r->setMimeType('application/json');
		return $r;
	}

	public function saveAlert()
	{
		$q[] = 'SELECT * FROM [wkf_core_issues]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [issueType] = %i', /*TableIssues::mtAlert*/5);
		array_push($q, ' AND [linkId] = %s', $this->alertId);
		array_push($q, ' AND [docState] = %i', 1200);

		$existedIssue = $this->db()->query($q)->fetch();

		if ($existedIssue)
		{
			// -- add comment
			/** @var \wkf\core\TableComments $tableComments */
			$tableComments = $this->app()->table('wkf.core.comments');
			$commentRecData = [
				'issue' => $existedIssue['ndx'],
				'commentType' => /*TableIssues::ctAlert*/2,
				'docState' => 4000, 'docStateMain' => 2,
				'dateTouch' => new \DateTime()
			];
			$tableComments->checkNewRec($commentRecData);
			$commentRecData['text'] = json::lint($this->alertData['data']);

			$commentNdx = $tableComments->dbInsertRec($commentRecData);
			$commentRecData = $tableComments->loadItem($commentNdx);
			$tableComments->checkAfterSave2($recData);
			$tableComments->docsLog ($commentNdx);

			if (isset($this->alertData['data']['solved']) && $this->alertData['data']['solved'])
			{
				/** @var \wkf\core\TableIssues $tableIssues */
				$tableIssues = $this->app()->table('wkf.core.issues');

				$issueRecData = $tableIssues->loadItem($existedIssue['ndx']);
				$issueRecData['docState'] = 4000;
				$issueRecData['docStateMain'] = 2;
				$issueNdx = $tableIssues->dbUpdateRec($issueRecData);
				$issueRecData = $tableIssues->loadItem($issueNdx);
				$tableIssues->checkAfterSave2($issueRecData);
				$tableIssues->docsLog ($issueNdx);
			}
		}
		else
		{
			// -- add new issue
			/** @var \wkf\core\TableIssues $tableIssues */
			$tableIssues = $this->app()->table('wkf.core.issues');

			$sectionNdx = 0;
			$issueKindNdx = 0;

			if (isset($this->alertData['systemSection']) && $this->alertData['systemSection'])
				$sectionNdx = $tableIssues->defaultSection($this->alertData['systemSection']);

			if (!$issueKindNdx)
				$issueKindNdx = $tableIssues->defaultSystemKind(6); // alert record
			if (!$sectionNdx)
				$sectionNdx = $tableIssues->defaultSection(20); // secretariat

			$issueKindCfg = $this->app()->cfgItem ('wkf.issues.kinds.'.$issueKindNdx, NULL);
			$issueType = $issueKindCfg['issueType'];

			$issueRecData = [
				'section' => $sectionNdx, 'issueKind' => $issueKindNdx, 'issueType' => $issueType,
				'source' => /*TableIssues::msAlert*/4,
				'structVersion' => $tableIssues->currentStructVersion,
				'subject' => str::upToLen($this->alertData['subject'], 100),
				'linkId' => $this->alertId,
				'docState' => 1200, 'docStateMain' => 1, 'dateTouch' => new \DateTime()
			];

			if (isset($this->alertData['data']['priority']))
				$issueRecData['priority'] = $this->alertData['data']['priority'];

			if (isset($this->alertData['data']['solved']) && $this->alertData['data']['solved'])
			{
				$issueRecData['docState'] = 4000;
				$issueRecData['docStateMain'] = 2;
			}

			$tableIssues->checkNewRec($issueRecData);
			$issueRecData['body'] = json::lint($this->alertData['data']);

			$issueNdx = $tableIssues->dbInsertRec($issueRecData);
			$issueRecData = $tableIssues->loadItem($issueNdx);
			$tableIssues->checkAfterSave2($issueRecData);
			$tableIssues->docsLog ($issueNdx);
		}
	}

	public function run ()
	{
		$this->result ['status'] = 0;

		$this->setRequestData();
		$this->prepareData();

		if ($this->result ['status'])
		{
			$this->saveAlert();
		}

		return $this->response();
	}
}
