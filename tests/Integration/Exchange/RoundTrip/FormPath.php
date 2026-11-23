<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\RoundTrip;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\FormController;
use Shipard\Api\FormLoader;
use Shipard\Api\LookupLoader;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Module\ModulePathResolver;

/**
 * Cesta formuláře tak, jak ji jde prohlížeč (#166 U10): `GET …/meta` nového
 * záznamu (výchozí hodnoty sloupců, prefill z vieweru a hook formuláře),
 * `POST …/recalculate` po změně pole a `POST …/save`. Volá skutečný
 * `FormController` — test invariantu „minimální pokyn = formulář bez zásahu
 * uživatele“ tak nekopíruje, co controller s daty nového záznamu dělá.
 */
final class FormPath
{
    private readonly FormController $controller;
    private readonly FormRegistry $forms;
    private readonly LookupRegistry $lookups;
    private readonly AuthContext $auth;

    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly array $tables,
        private readonly ConfigRuntime $config,
        private readonly DataSourceConfig $dsConfig,
        private readonly ModulePathResolver $resolver,
        private readonly DocumentRegistry $documents,
        private readonly ?DocumentEventDispatcher $dispatcher,
    ) {
        $this->controller = new FormController();
        $this->forms = FormLoader::load($dsConfig, $resolver);
        $this->forms->setLanguage('cs');
        $this->lookups = LookupLoader::load($dsConfig, $resolver);
        $this->lookups->setLanguage('cs');
        $this->auth = new AuthContext(true, null, 'session', null, true);
    }

    /**
     * Data nového záznamu, jak je formulář dostane při otevření.
     *
     * @param array<string, mixed> $prefill `defaults[…]` z vieweru (typ dokladu, řada, hlavička řádku)
     * @return array<string, mixed>
     */
    public function open(string $table, array $prefill): array
    {
        return self::data($this->controller->meta(
            $table, null, $this->tables, $this->db, $this->forms, $this->config, $this->lookups,
            $this->resolver, 'cs', $prefill, $this->auth, $this->documents,
        ), "otevření formuláře {$table}");
    }

    /**
     * Uživatel změnil pole — přepočet formuláře.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function change(string $table, array $data, string $column, mixed $value): array
    {
        $data[$column] = $value;
        return self::data($this->controller->recalculate(
            $table, $this->request(['changedColumn' => $column, 'data' => $data]), $this->tables, $this->db,
            $this->forms, $this->config, $this->lookups, $this->resolver, 'cs', $this->auth, $this->documents,
        ), "přepočet {$table}.{$column}");
    }

    /**
     * Uložení formuláře; vrací id záznamu.
     *
     * @param array<string, mixed> $data
     */
    public function save(string $table, array $data, ?int $id = null): int
    {
        $saved = self::data($this->controller->save(
            $table, $id, $this->request($data), $this->tables, $this->db, $this->config, $this->forms,
            $this->resolver, $this->lookups, 'cs', $this->documents, $this->dsConfig, $this->auth, $this->dispatcher,
        ), "uložení {$table}");
        return (int) ($saved['id'] ?? $id ?? throw new \RuntimeException("Uložení {$table} nevrátilo id."));
    }

    /** Přechod stavu — tělo jen se stavem, jako tlačítko formuláře. */
    public function transition(string $table, int $id, int $docState): void
    {
        $this->save($table, ['docState' => $docState], $id);
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): Request
    {
        return Request::fromArray('POST', '/', [], (string) json_encode($body), []);
    }

    /**
     * Data záznamu z odpovědi; chyba controlleru shodí test s nálezy.
     *
     * @return array<string, mixed>
     */
    private static function data(Response $response, string $step): array
    {
        $payload = $response->getPayload();
        if ($response->getStatus() >= 400 || !is_array($payload) || empty($payload['success'])) {
            throw new \RuntimeException("Formulář — {$step}: " . json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
        $data = $payload['data']['data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException("Formulář — {$step}: odpověď nenese data záznamu.");
        }
        return $data;
    }
}
