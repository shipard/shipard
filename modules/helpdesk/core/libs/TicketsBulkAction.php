<?php
namespace helpdesk\core\libs;
use \Shipard\Base\ApiObject;


/**
 * class TicketsBulkAction
 */
class TicketsBulkAction extends ApiObject
{
	var $userNdx = 0;
	var $actionType = '';
	var $commentNdx = 0;
  var $ticketNdx = 0;

	var $paramsError = 1;

	public function init ()
	{
		$this->userNdx = $this->app()->userNdx();

		$this->actionType = $this->requestParam ('action-type', '');
		if ($this->actionType === '')
		{
			return;
		}

		$this->commentNdx = intval($this->requestParam ('comment-ndx', 0));
		$this->ticketNdx = intval($this->requestParam ('ticket-ndx', 0));

		$this->paramsError = 0;
	}

	function runAction()
	{
		if ($this->paramsError)
			return FALSE;

		switch ($this->actionType)
		{
			case 'markCommentAsRead': return $this->runAction_MarkAsRead('comment');
		}

    return FALSE;
	}

	function runAction_MarkAsRead($type)
	{
		if (!$this->commentNdx)
			return FALSE;

		$q[] = 'UPDATE [e10_base_notifications] SET [state] = 1';
		array_push($q, ' WHERE tableId = %s', 'helpdesk.core.tickets');
		array_push($q, ' AND recId = %i', $this->commentNdx);
    array_push($q, ' AND recIdMain = %i', $this->ticketNdx);
		array_push($q, ' AND personDest = %i', $this->app()->userNdx());
		$this->db()->query ($q);

		return TRUE;
	}

	public function createResponseContent($response)
	{
		$this->init();
		$this->runAction();

		$response->add ('reloadNotifications', 1);
		$response->add ('success', 1);
	}
}
