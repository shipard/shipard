<?php
declare(strict_types=1);

namespace Shipard\Api;

/**
 * Klasifikace rout pro DS ve stavu `read_only` (#56 fáze 2, R1).
 *
 * Centrální tabulka klíčovaná (controller, action) — ne flag na `Route`,
 * Router je ručně psaný if-chain s desítkami konstrukcí. Klasifikuje se
 * **per routa, ne per HTTP metoda** (D7): SSE chat je GET a spouští LLM,
 * login je POST a fungovat musí.
 *
 * **Fail-closed:** controller nebo akce mimo tabulku = `Deny403`. Nová
 * routa přidaná bez rozmyslu je v read-only zavřená, dokud ji někdo vědomě
 * nepovolí sem. Zdroj pravdy o akcích je `Router`.
 */
final class ReadOnlyPolicy
{
	/** Zástupný klíč: verdikt pro všechny akce controlleru. */
	private const string ANY = '*';

	/**
	 * controller → (action | '*') → verdikt. Chybějící akce = Deny403.
	 *
	 * @var array<string, array<string, ReadOnlyVerdict>>
	 */
	private const array TABLE = [
		// Přihlášení, refresh, logout, OIDC — read-only DS se musí dát otevřít.
		'auth'     => [self::ANY => ReadOnlyVerdict::Allow],
		// Samoobsluha účtu (heslo, sessions, pozvánky) — uživatelská data,
		// ne účetní; klient „jen kouká" si musí umět spravovat přístup.
		'password' => [self::ANY => ReadOnlyVerdict::Allow],

		'app' => [
			'info'        => ReadOnlyVerdict::Allow,
			'manifest'    => ReadOnlyVerdict::Allow,
			'brandingGet' => ReadOnlyVerdict::Allow,
			'avatarGet'   => ReadOnlyVerdict::Allow,
			// brandingUpload/Delete, avatarUpload/Delete → default 403
		],

		'meta'    => [self::ANY => ReadOnlyVerdict::Allow],
		'openapi' => [self::ANY => ReadOnlyVerdict::Allow],
		'dsAbout' => [self::ANY => ReadOnlyVerdict::Allow],

		'ui' => [
			'navigation' => ReadOnlyVerdict::Allow,
		],
		'settings' => [
			'navigation'        => ReadOnlyVerdict::Allow,
			'accountNavigation' => ReadOnlyVerdict::Allow,
			'page'              => ReadOnlyVerdict::Allow,
			// savePage → 403
		],
		'dashboard' => [
			'index'         => ReadOnlyVerdict::Allow,
			'sectionBadges' => ReadOnlyVerdict::Allow,
			// summary spouští LLM → 403 (D5)
		],

		'crud' => [
			'list'            => ReadOnlyVerdict::Allow,
			'show'            => ReadOnlyVerdict::Allow,
			'docStateOptions' => ReadOnlyVerdict::Allow,
			// create/update/patch/delete → 403
		],
		'viewer' => [self::ANY => ReadOnlyVerdict::Allow],
		'form' => [
			'meta'     => ReadOnlyVerdict::Allow,
			// Sloupce + řádky sub-tabulky (issue #53) — čtení, bez ní by tab
			// Řádky / Adresy na read-only DS zůstal prázdný.
			'subtable' => ReadOnlyVerdict::Allow,
			// save, recalculate, subtableMove → 403
		],
		'lookup' => [self::ANY => ReadOnlyVerdict::Allow],
		'attachment' => [
			'download'  => ReadOnlyVerdict::Allow,
			'thumbnail' => ReadOnlyVerdict::Allow,
			'list'      => ReadOnlyVerdict::Allow,
			// upload/patch/delete/restore → 403
		],
		// Reporty v read-only fungují (D7).
		'reports' => [self::ANY => ReadOnlyVerdict::Allow],
		// Tisk = čtení záznamu (#90 D21); návrh odeslání nic nemění.
		'prints' => [
			'run'           => ReadOnlyVerdict::Allow,
			'sendDraft'     => ReadOnlyVerdict::Allow,
			'textVariables' => ReadOnlyVerdict::Allow,
			// send → 403: odeslání vytváří zprávu a řádek fronty (D38)
		],
		'alerts' => [
			'registry' => ReadOnlyVerdict::Allow,
			// runDue, runCheck → 403
		],
		'setup' => [
			'checklist' => ReadOnlyVerdict::Allow,
			// ostatní setup akce fail-closed — read-only povaha neověřena
		],
		'contentTags' => [
			'overview' => ReadOnlyVerdict::Allow,
			'tagItems' => ReadOnlyVerdict::Allow,
			// materialize → 403
		],
		// Akce mají tvar `validate|preview|apply` nebo `person:apply`,
		// `item:preview`, `bank:validate` — matchuje se suffix za dvojtečkou.
		'exchange' => [
			'validate' => ReadOnlyVerdict::Allow,
			'preview'  => ReadOnlyVerdict::Allow,
			// apply → 403
		],
		'personsRegistry' => [
			'search'      => ReadOnlyVerdict::Allow,
			'fetchPerson' => ReadOnlyVerdict::Allow,
			// import → 403
		],

		// Příchozí pošta (D4): read_only poštu nepřijímá, router frontuje.
		'mail' => [
			'receiveIncoming' => ReadOnlyVerdict::Deny503,
			// Nabídka adres odesílatele — čtení.
			'senderAddresses' => ReadOnlyVerdict::Allow,
			// importMessage, uploadMessages, setSenderPassword,
			// archiveInformational, restoreArchived (Archivovat vše / Vrátit
			// v Ostatních) → 403
		],
		// Odeslaná pošta: Odeslat znovu (nový průchod frontou) i import
		// ze starého systému jsou zápis → 403.
		'sentMessages' => [],
		// Callbacky AI analyzeru — stroj, retryuje; uživatelské akce nad
		// návrhem dokumentu 403, preview je GET bez zápisu.
		'analysis' => [
			'queue'             => ReadOnlyVerdict::Deny503,
			'claim'             => ReadOnlyVerdict::Deny503,
			'payload'           => ReadOnlyVerdict::Deny503,
			'attachmentContent' => ReadOnlyVerdict::Deny503,
			'result'            => ReadOnlyVerdict::Deny503,
			'failed'            => ReadOnlyVerdict::Deny503,
			'previewMessage'    => ReadOnlyVerdict::Allow,
			// reanalyze, applyMessage, unapplyMessage, rejectMessage, saveDecisions → 403
		],

		// Chat celý vypnutý (D5) — i list/show, entry point server skrývá.
		'chat' => [],

		// MCP: filtr na read-tier nástroje dělá McpController (JSON-RPC
		// chyby, ne HTTP 403 — MCP klienti čtou JSON-RPC).
		'mcp' => [
			'rpc' => ReadOnlyVerdict::Allow,
		],

		// Majetek — náhled a nabídka odpisů za období jen čtou, provedení
		// zapisuje události (depreciationRun → 403). Zaúčtování stejně:
		// náhled čte, posting / postingCancel zapisují doklad → 403.
		'assets' => [
			'depreciationRunOptions' => ReadOnlyVerdict::Allow,
			'depreciationRunPreview' => ReadOnlyVerdict::Allow,
			'postingPreview'         => ReadOnlyVerdict::Allow,
		],

		// Zakázky — periodická fakturace: vystavení, přegenerování i obnovení
		// zapisují doklady a evidenci období → vše 403 (#110 D24).
		'workOrders' => [],

		// senderRules, registry, bank, accounting, accbal → vše 403 (default)
	];

	public function verdict(Route $route): ReadOnlyVerdict
	{
		// Hosting endpointy řídí lifecycle *jiných* DS a mají vlastní
		// klíčovou auth. Read-only hosting DS, který by zablokoval
		// rekonciliaci celé flotily, je horší selhání než teoretická mutace
		// hosting dat — proto ALLOW.
		if (str_starts_with($route->controller, 'hosting')) {
			return ReadOnlyVerdict::Allow;
		}

		$actions = self::TABLE[$route->controller] ?? null;
		if ($actions === null) {
			return ReadOnlyVerdict::Deny403;
		}
		if (isset($actions[self::ANY])) {
			return $actions[self::ANY];
		}

		$action = $route->action;
		if ($route->controller === 'exchange') {
			$colon = strrpos($action, ':');
			if ($colon !== false) {
				$action = substr($action, $colon + 1);
			}
		}

		return $actions[$action] ?? ReadOnlyVerdict::Deny403;
	}
}
