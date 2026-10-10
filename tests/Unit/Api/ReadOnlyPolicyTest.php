<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Api\ReadOnlyPolicy;
use Shipard\Api\ReadOnlyVerdict;
use Shipard\Api\Route;

class ReadOnlyPolicyTest extends TestCase
{
	/** @return iterable<string, array{string, string, ReadOnlyVerdict}> */
	public static function verdicts(): iterable
	{
		$allow = ReadOnlyVerdict::Allow;
		$d403 = ReadOnlyVerdict::Deny403;
		$d503 = ReadOnlyVerdict::Deny503;

		$rows = [
			// auth / password — vše
			['auth', 'login', $allow], ['auth', 'refresh', $allow], ['auth', 'logout', $allow],
			['auth', 'oidcStart', $allow], ['auth', 'oidcCallback', $allow], ['auth', 'oidcExchange', $allow],
			['password', 'change', $allow], ['password', 'forgot', $allow], ['password', 'reset', $allow],
			['password', 'invite', $allow], ['password', 'sessions', $allow],
			['password', 'sessionDelete', $allow], ['password', 'sessionsRevokeOthers', $allow],
			// app
			['app', 'info', $allow], ['app', 'manifest', $allow], ['app', 'brandingGet', $allow], ['app', 'avatarGet', $allow],
			['app', 'brandingUpload', $d403], ['app', 'brandingDelete', $d403],
			['app', 'avatarUpload', $d403], ['app', 'avatarDelete', $d403],
			// meta / openapi / dsAbout
			['meta', 'tables', $allow], ['meta', 'table', $allow], ['openapi', 'spec', $allow], ['dsAbout', 'about', $allow],
			// ui / settings / dashboard
			['ui', 'navigation', $allow],
			['settings', 'navigation', $allow], ['settings', 'accountNavigation', $allow],
			['settings', 'page', $allow], ['settings', 'savePage', $d403],
			['dashboard', 'index', $allow], ['dashboard', 'sectionBadges', $allow], ['dashboard', 'summary', $d403],
			// crud
			['crud', 'list', $allow], ['crud', 'show', $allow], ['crud', 'docStateOptions', $allow],
			['crud', 'create', $d403], ['crud', 'update', $d403], ['crud', 'patch', $d403], ['crud', 'delete', $d403],
			// viewer / form / lookup / attachment
			['viewer', 'meta', $allow], ['viewer', 'rows', $allow], ['viewer', 'detail', $allow],
			['form', 'meta', $allow], ['form', 'subtable', $allow],
			['form', 'save', $d403], ['form', 'recalculate', $d403], ['form', 'subtableMove', $d403],
			['form', 'subtableDelete', $d403],
			['lookup', 'search', $allow], ['lookup', 'resolve', $allow],
			// POST, ale jen počítá návrh nového záznamu — nic nezapisuje.
			['lookup', 'createDefaults', $allow],
			['attachment', 'download', $allow], ['attachment', 'thumbnail', $allow], ['attachment', 'list', $allow],
			['attachment', 'upload', $d403], ['attachment', 'patch', $d403],
			['attachment', 'delete', $d403], ['attachment', 'restore', $d403],
			// reports (D7) / alerts / setup / contentTags
			['reports', 'catalog', $allow], ['reports', 'run', $allow],
			['prints', 'run', $allow], ['prints', 'sendDraft', $allow], ['prints', 'send', $d403],
			['prints', 'textVariables', $allow],
			['alerts', 'registry', $allow], ['alerts', 'runDue', $d403], ['alerts', 'runCheck', $d403],
			['setup', 'checklist', $allow], ['setup', 'parameters', $d403],
			['setup', 'generateAccountingItems', $d403], ['setup', 'bridgeBankAccounts', $d403],
			['contentTags', 'overview', $allow], ['contentTags', 'tagItems', $allow], ['contentTags', 'materialize', $d403],
			// exchange — suffix match
			['exchange', 'validate', $allow], ['exchange', 'preview', $allow], ['exchange', 'apply', $d403],
			['exchange', 'person:validate', $allow], ['exchange', 'person:apply', $d403],
			['exchange', 'item:preview', $allow], ['exchange', 'item:apply', $d403],
			['exchange', 'bank:validate', $allow], ['exchange', 'bank:apply', $d403],
			['exchange', 'user:validate', $allow], ['exchange', 'user:apply', $d403],
			['exchange', 'asset:validate', $allow], ['exchange', 'asset:apply', $d403],
			['exchange', 'workOrder:validate', $allow], ['exchange', 'workOrder:apply', $d403],
			// personsRegistry
			['personsRegistry', 'search', $allow], ['personsRegistry', 'fetchPerson', $allow], ['personsRegistry', 'import', $d403],
			// mail (D4)
			['mail', 'receiveIncoming', $d503],
			['mail', 'importMessage', $d403], ['mail', 'uploadMessages', $d403], ['mail', 'setSenderPassword', $d403],
			['mail', 'archiveInformational', $d403], ['mail', 'restoreArchived', $d403],
			['mail', 'senderAddresses', $allow],
			// Odeslaná pošta — Odeslat znovu i import ze starého systému jsou zápis
			['sentMessages', 'resend', $d403], ['sentMessages', 'import', $d403],
			// analysis — uživatelské akce 403, preview GET allow; zrušené callbacky
			// analyzeru (#85 D20) fail-closed 403 jako každá neznámá akce
			['analysis', 'previewMessage', $allow],
			['analysis', 'queue', $d403], ['analysis', 'result', $d403],
			['analysis', 'reanalyze', $d403], ['analysis', 'applyMessage', $d403],
			['analysis', 'unapplyMessage', $d403], ['analysis', 'rejectMessage', $d403],
			['analysis', 'saveDecisions', $d403],
			// chat celý (D5)
			['chat', 'list', $d403], ['chat', 'show', $d403], ['chat', 'create', $d403],
			['chat', 'rename', $d403], ['chat', 'delete', $d403], ['chat', 'sendMessage', $d403],
			// mcp — filtr uvnitř
			['mcp', 'rpc', $allow],
			// zápisové controllery bez výjimek
			['senderRules', 'confirmRule', $d403], ['senderRules', 'rejectRule', $d403], ['senderRules', 'undoAutoArchive', $d403],
			['registry', 'import', $d403], ['registry', 'fromMessage', $d403], ['registry', 'extractText', $d403],
			['bank', 'importStatement', $d403], ['bank', 'reaccount', $d403],
			['accounting', 'reaccount', $d403], ['accbal', 'match', $d403],
			['vat', 'reportPeriodLock', $d403], ['vat', 'filingCompose', $d403], ['vat', 'registrationTaxOffice', $d403], ['vat', 'filingAccount', $d403],
			['vat', 'filingImport', $d403], ['vat', 'filingImportFinish', $d403],
			// majetek — odpisy za období
			['assets', 'depreciationRunOptions', $allow], ['assets', 'depreciationRunPreview', $allow], ['assets', 'depreciationRun', $d403],
			['assets', 'postingPreview', $allow], ['assets', 'posting', $d403], ['assets', 'postingCancel', $d403],
			// hosting* — vše (řídí jiné DS)
			['hostingPortal', 'myDatasources', $allow], ['hostingPortal', 'createDatasource', $allow],
			['hostingOidc', 'token', $allow], ['hostingServer', 'reconcile', $allow],
			['hostingMail', 'lookup', $allow], ['hostingAiGateway', 'messages', $allow],
		];

		foreach ($rows as [$controller, $action, $verdict]) {
			yield "{$controller}.{$action}" => [$controller, $action, $verdict];
		}
	}

	#[DataProvider('verdicts')]
	public function testVerdictTable(string $controller, string $action, ReadOnlyVerdict $expected): void
	{
		$this->assertSame($expected, (new ReadOnlyPolicy())->verdict(new Route($controller, $action)));
	}

	public function testUnknownControllerFailsClosed(): void
	{
		$this->assertSame(ReadOnlyVerdict::Deny403, (new ReadOnlyPolicy())->verdict(new Route('madeUp', 'list')));
	}

	public function testUnknownActionOfKnownControllerFailsClosed(): void
	{
		$policy = new ReadOnlyPolicy();
		$this->assertSame(ReadOnlyVerdict::Deny403, $policy->verdict(new Route('crud', 'bogus')));
		$this->assertSame(ReadOnlyVerdict::Deny403, $policy->verdict(new Route('mail', 'bogus')));
		$this->assertSame(ReadOnlyVerdict::Deny403, $policy->verdict(new Route('exchange', 'person:bogus')));
	}

	public function testWildcardDoesNotLeakAcrossControllers(): void
	{
		// `viewer` má '*' ALLOW; jiný controller s neznámou akcí zůstává 403.
		$policy = new ReadOnlyPolicy();
		$this->assertSame(ReadOnlyVerdict::Allow, $policy->verdict(new Route('viewer', 'anything')));
		$this->assertSame(ReadOnlyVerdict::Deny403, $policy->verdict(new Route('form', 'anything')));
	}
}
