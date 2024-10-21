<?php

namespace wkf\core\libs;
use Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;
use \wkf\core\TableIssues;


/**
 * class ContactForm2
 */
class ContactForm2 extends \Shipard\Base\WebForm2
{
	var $spamScore = '';

	public function fields ()
	{
		$this->checkFormParamsList('fields', TRUE);
		if (isset($this->formParams['fields']) && is_array($this->formParams['fields']))
			return $this->formParams['fields'];
		return [];
	}

	public function validate ()
	{
		return TRUE;

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
		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app->table('wkf.core.issues');

		$bodyText = '';
		$dataFields = $this->fields();
		foreach ($dataFields as $key)
		{
			$bodyText .= $key.': '.$this->data[$key]."\n\n";
		}

		$bodyText .= "\n\n\n\n";

		$sectionNdx = 0;
		$issueKindNdx = 0;

		//if (!$issueKindNdx)
		$issueKindNdx = $tableIssues->defaultSystemKind(2); // inbox record
		//if (!$sectionNdx)
		$sectionNdx = $tableIssues->defaultSection(20); // secretariat

		$issueRecData = [
			'subject' => 'Kontaktní formulář',
			'body' => $bodyText,
			'structVersion' => $tableIssues->currentStructVersion,
			'source' => TableIssues::msWebForm,
			'section' => $sectionNdx, 'issueKind' => $issueKindNdx,
			'docState' => 1200, 'docStateMain' => 1,
		];

		$issue = ['recData' => $issueRecData];

		//$issue['systemInfo']['webForm']['from'] = ['address' => $this->data['email'], 'name' => $this->data['name']];
		$issue['systemInfo']['webForm']['srcIPAddress'] = $_SERVER ['REMOTE_ADDR'];
		$issue['systemInfo']['webForm']['server'] = $_SERVER['HTTP_HOST']; // intval($this->data['regSrv']);
		$issue['systemInfo']['webForm']['data'] = $this->data;
		$issue['systemInfo']['webForm']['fields'] = $this->fields();
		if ($this->spamScore !== '')
			$issue['systemInfo']['webForm']['spam-score'] = $this->spamScore;

		$tableIssues->addIssue($issue);


		// -- notify via email?
		$formNotifyEmail = $this->app->cfgItem ('options.webcomm.contactFormNotifyEmail', '');
		if ($formNotifyEmail !== '')
		{
			$fromEmail = $this->app->cfgItem ('options.core.ownerEmail');
			$toEmail = $formNotifyEmail;

			$subject = 'Kontaktní formulář';
			$body = '';
			$body .= $bodyText;

			UtilsBase::sendEmail ($this->app, $subject, $body, $fromEmail, $toEmail, 'Kontaktní formulář', 'Kontaktní formulář');
		}

		return TRUE;
	}

	public function successMsg ()
	{
		return $this->dictText('Hotovo. Ozveme se co nejdříve.');
	}
}
