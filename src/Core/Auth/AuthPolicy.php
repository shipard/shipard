<?php
declare(strict_types=1);

namespace Shipard\Core\Auth;

/**
 * Per-DS auth politika z `config/main.json` klíče `auth`. Klíč je celý
 * volitelný — bez něj platí dnešní chování (jen lokální login, žádní
 * provideři). Validuje se lazy při prvním použití (DataSourceConfig::
 * getAuthPolicy()), ne při načtení configu — rozbitá auth sekce nesmí
 * zablokovat CLI (break-glass musí fungovat vždy).
 *
 * Politika PINu (`auth.pin`, #177 U21) výjimku nevyhazuje nikdy: neplatná
 * sekce jsou výchozí hodnoty a nález v `shpd-server doctor` — viz PinPolicy.
 */
final readonly class AuthPolicy
{
	public PinPolicy $pin;

	/** @param OidcProviderConfig[] $providers */
	public function __construct(
		public bool $localLogin = true,
		public array $providers = [],
		?PinPolicy $pin = null,
	) {
		$this->pin = $pin ?? PinPolicy::defaults();

		if (!$this->localLogin && $this->providers === []) {
			throw new \RuntimeException("Auth policy: 'local: false' requires at least one OIDC provider");
		}

		$seen = [];
		foreach ($this->providers as $provider) {
			if (isset($seen[$provider->id])) {
				throw new \RuntimeException("Auth policy: duplicate provider id '{$provider->id}'");
			}
			$seen[$provider->id] = true;
		}
	}

	public static function fromArray(array $data): self
	{
		$providersData = $data['providers'] ?? [];
		if (!is_array($providersData)) {
			throw new \RuntimeException("Auth policy: 'providers' must be an array");
		}

		return new self(
			localLogin: (bool) ($data['local'] ?? true),
			providers: array_map(
				static fn (array $p) => OidcProviderConfig::fromArray($p),
				array_values($providersData),
			),
			pin: PinPolicy::fromConfig($data['pin'] ?? null),
		);
	}

	public function getProvider(string $id): ?OidcProviderConfig
	{
		foreach ($this->providers as $provider) {
			if ($provider->id === $id) {
				return $provider;
			}
		}
		return null;
	}
}
