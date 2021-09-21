<?php

namespace wkf\core\libs;
use Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;

class ContactForm extends \Shipard\Base\WebForm
{
	var $spamScore = '';

	public function fields ()
	{
		return ['email', 'name', 'msg'];
	}

	public function createFormCode ($options = 0)
	{
		$useReCaptcha = ($this->template && isset($this->template->pageParams['recaptcha-v3-site-key']));

		$this->disableAutofocus = TRUE;
		$sendMsgTxt = $this->dictText('Odeslat zprávu');

		$c = '';

		if ($useReCaptcha)
		{
			$c .= "<noscript><p>";
			$c .= Utils::es('Kontaktní formulář vyžaduje javascript...');
			$c .= "</p></noscript>";
		}

		$c .= "<form class='form-horizontal' method='POST'";
		if ($useReCaptcha)
			$c .= "style='display: none;'";
		$c .= ">";

		$c .= "<input type='hidden' name='webFormState' value='1'/>";
		$c .= "<input type='hidden' name='webFormId' value='e10.web.contactForm'/>";

		if ($useReCaptcha)
		{
			$c .= "<input type='hidden' id='recaptcha-response' name='webFormReCaptchtaResponse' value=''/>";
		}

		$c .= $this->addFormInput ('Váš e-mail', 'email', 'email', ['icon' => 'system/iconEmail']);
		$c .= $this->addFormInput ('Vaše jméno', 'text', 'name', ['icon' => 'system/iconUser']);
		$c .= $this->addFormInput ('Zpráva', 'memo', 'msg', ['icon' => 'icon-pencil']);

		if ($this->fw === 'bs4')
		{
			$c .= "<div class='text-xs-center'>";
			$c .= "<button type='submit' class='btn btn-primary'>".Utils::es($sendMsgTxt)."</button>";
			$c .= '</div>';
		}
		else
			$c .= "<div class='form-group'><div class='col-sm-offset-2 col-sm-10'><button type='submit' class='btn btn-primary'>".Utils::es($sendMsgTxt)."</button></div></div>";
		$c .= '</form>';

		return $c;
	}

	public function validate ()
	{
		if ($this->app->testPostParam ('email') == '')
		{
			$this->formErrors ['email'] = 'E-email není vyplněn';
			return FALSE;
		}

		if ($this->app->testPostParam ('name') == '')
		{
			$this->formErrors ['name'] = 'Jméno není vyplněno';
			return FALSE;
		}

		if ($this->app->testPostParam ('msg') == '')
		{
			$this->formErrors ['msg'] = 'Text zprávy není vyplněn';
			return FALSE;
		}

		$reCaptchaResponse = $this->app->testPostParam ('webFormReCaptchtaResponse', NULL);
		if ($reCaptchaResponse !== NULL)
		{
			if ($reCaptchaResponse === '')
			{
				$this->formErrors ['msg'] = 'Odeslání formuláře se nezdařilo.';
				return FALSE;
			}

			$validateUrl = 'https://www.google.com/recaptcha/api/siteverify?secret='.$this->template->pageParams['recaptcha-v3-secret-key'].'&response='.$reCaptchaResponse.'&remoteip='.$_SERVER ['REMOTE_ADDR'];
			$validateResult =  \E10\http_post ($validateUrl, '');
			$validateResultData = json_decode($validateResult['content'], TRUE);
			if ($validateResultData && isset($validateResultData['success']))
			{
				if ($validateResultData['success'])
				{
					if ($validateResultData['score'] < 0.5)
					{
						$this->formErrors ['msg'] = 'Vaše zpráva bohužel vypadá jako SPAM.';
						return FALSE;
					}
					$this->spamScore = strval($validateResultData['score']);
				}
				else
				{
					$this->formErrors ['msg'] = 'Odeslání formuláře se nezdařilo.';
					return FALSE;
				}
			}
		}

		return TRUE;
	}

	public function doIt ()
	{
		$useNewWorkflow = $this->app->useNewWorkflow();
		if ($useNewWorkflow)
		{
			/*
			$issue = new \wkf\core\libs\InboxIssue($this->app);
			$issue->init();
			$issue->setSubject('Kontaktní formulář');
			$issue->setBody($this->data['msg']);
			$issue->setSystemInfo('webForm', 'from', $this->data['email']);
			$issue->setSystemInfo('webForm', 'from-name', $this->data['name']);
			$issue->setSystemInfo('webForm', 'server', $this->data['regSrv']);
			$issue->setSystemInfo('webForm', 'server-url', $this->data['regURL']);
			if ($this->spamScore !== '')
				$issue->setSystemInfo('webForm', 'spam-score', $this->spamScore);
			$issue->recData['source'] = 1;
			$issue->save();
			*/

			/** @var \wkf\core\TableIssues $tableIssues */
			$tableIssues = $this->app->table('wkf.core.issues');

			$sectionNdx = 0;
			$issueKindNdx = 0;

			//if (!$issueKindNdx)
			$issueKindNdx = $tableIssues->defaultSystemKind(2); // inbox record
			//if (!$sectionNdx)
			$sectionNdx = $tableIssues->defaultSection(20); // secretariat

			$issueRecData = [
				'subject' => 'Kontaktní formulář',
				'body' => $this->data['msg'],
				'structVersion' => $tableIssues->currentStructVersion,
				'source' => TableIssues::msWebForm,
				'section' => $sectionNdx, 'issueKind' => $issueKindNdx,
				'docState' => 1200, 'docStateMain' => 1,
			];

			$issue = ['recData' => $issueRecData];

			$issue['systemInfo']['webForm']['from'] = ['address' => $this->data['email'], 'name' => $this->data['name']];
			$issue['systemInfo']['webForm']['srcIPAddress'] = $_SERVER ['REMOTE_ADDR'];
			$issue['systemInfo']['webForm']['server'] = intval($this->data['regSrv']);
			if ($this->spamScore !== '')
				$issue['systemInfo']['webForm']['spam-score'] = $this->spamScore;

			$tableIssues->addIssue($issue);
		}
		else
		{
			$msg = new \Shipard\Report\MailMessage($this->app);

			$msg->setFrom($this->data['name'], $this->data['email']);
			$q = 'SELECT recid, valueString FROM [e10_base_properties] where [group] = %s AND property = %s AND valueString = %s';
			$sender = $this->app->db()->query($q, 'contacts', 'email', $this->data['email'])->fetch();
			if ($sender)
				$msg->documentInfo['persons']['from'][] = $sender['recid'];

			$msg->setTo($this->app->cfgItem('options.core.ownerEmail'));
			$msg->documentInfo['persons']['to'][] = $this->app->cfgItem('options.core.ownerPerson');

			$msg->setSubject('Kontaktní formulář');
			$msg->setBody($this->data['msg']);

			$msg->saveToOutbox('inbox');

			$newProperty = ['tableid' => 'e10pro.wkf.messages', 'recid' => $msg->newMsgNdx,
				'group' => 'wfinfo', 'property' => 'wf-from',
				'valueString' => $this->data['email'], 'note' => $this->data['name']];
			$this->app->db()->query("insert into e10_base_properties ", $newProperty);

			$newProperty = ['tableid' => 'e10pro.wkf.messages', 'recid' => $msg->newMsgNdx,
				'group' => 'wfinfo', 'property' => 'wf-ipaddr',
				'valueString' => $_SERVER ['REMOTE_ADDR']];
			$this->app->db()->query("insert into e10_base_properties ", $newProperty);
		}

		// -- notify via email?
		$formNotifyEmail = $this->app->cfgItem ('options.webcomm.contactFormNotifyEmail', '');
		if ($formNotifyEmail !== '')
		{
			$fromEmail = $this->app->cfgItem ('options.core.ownerEmail');
			$toEmail = $formNotifyEmail;

			$subject = 'Kontaktní formulář';
			$body = '';
			$body .= 'Jméno: '.$this->data['name']."\n";
			$body .= 'Email: '.$this->data['email']."\n";
			$body .= 'Zpráva:'."\n";
			$body .= "--------------------------------\n";
			$body .= $this->data['msg'];
			$body .= "\n--------------------------------\n\n";
			UtilsBase::sendEmail ($this->app, $subject, $body, $fromEmail, $toEmail, 'Kontaktní formulář', 'Kontaktní formulář');
		}

		return TRUE;
	}
}
