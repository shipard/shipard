<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

use Dibi\Connection;
use Shipard\Core\Config\ServerConfig;

class DatabaseManager
{
    private Connection $connection;

    public function __construct(private readonly ServerConfig $config)
    {
        $this->connection = new Connection([
            'driver'   => 'mysqli',
            'host'     => $config->getHost(),
            'port'     => $config->getPort(),
            'username' => $config->getAdminUser(),
            'password' => $config->getAdminPassword(),
            'charset'  => 'utf8mb4',
        ]);
    }

    public function createDatabase(string $dbName): void
    {
        $this->connection->query(
            'CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci',
            $dbName
        );
    }

    public function createUser(string $user, string $password, string $dbName): void
    {
        $this->connection->query(
            "CREATE USER %s@'localhost' IDENTIFIED BY %s",
            $user,
            $password
        );

        $this->connection->query(
            "GRANT ALL PRIVILEGES ON %n.* TO %s@'localhost'",
            $dbName,
            $user
        );

        $this->connection->query('FLUSH PRIVILEGES');
    }

    /** Databáze existuje — pro výstup „already gone“ při opakovaném běhu `ds-delete`. */
    public function databaseExists(string $dbName): bool
    {
        return $this->connection->query(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s',
            $dbName
        )->fetch() !== null;
    }

    /** DB uživatel zdroje dat (`<user>@localhost`) existuje. */
    public function userExists(string $user): bool
    {
        return $this->connection->query(
            "SELECT User FROM mysql.user WHERE User = %s AND Host = 'localhost'",
            $user
        )->fetch() !== null;
    }

    /**
     * Granty účtů na databázi v `mysql.db` — `DROP DATABASE` je neodebere
     * (#103 D68). Název se hledá surový i s escapovanými podtržítky
     * (`a\_b`: tak ho ukládá phpMyAdmin i ruční GRANT s escapem; názvy
     * databází zdrojů dat podtržítka mají vždy). `db` se vrací přesně
     * tak, jak je uložený — REVOKE musí použít stejný tvar.
     *
     * @return list<array{user: string, host: string, db: string, grantOption: bool}>
     */
    public function listDatabaseGrants(string $dbName): array
    {
        $rows = $this->connection->query(
            'SELECT User, Host, Db, Grant_priv FROM mysql.db WHERE Db = %s OR Db = %s ORDER BY User, Host',
            $dbName,
            str_replace('_', '\\_', $dbName)
        )->fetchAll();
        $grants = [];
        foreach ($rows as $row) {
            $grants[] = [
                'user'        => (string) $row['User'],
                'host'        => (string) $row['Host'],
                'db'          => (string) $row['Db'],
                'grantOption' => strtoupper((string) $row['Grant_priv']) === 'Y',
            ];
        }
        return $grants;
    }

    /**
     * Odebere účtu všechna práva na databázi; `$dbAsStored` ve tvaru
     * z {@see listDatabaseGrants()}. Prázdný host = role (MariaDB) bez `@host`.
     */
    public function revokeDatabasePrivileges(string $dbAsStored, string $user, string $host, bool $grantOption): void
    {
        $statements = ['REVOKE ALL PRIVILEGES ON %n.*'];
        if ($grantOption) {
            $statements[] = 'REVOKE GRANT OPTION ON %n.*';
        }
        foreach ($statements as $statement) {
            if ($host === '') {
                $this->connection->query($statement . ' FROM %n', $dbAsStored, $user);
            } else {
                $this->connection->query($statement . ' FROM %s@%s', $dbAsStored, $user, $host);
            }
        }
        $this->connection->query('FLUSH PRIVILEGES');
    }

    public function dropDatabase(string $dbName): void
    {
        $this->connection->query('DROP DATABASE IF EXISTS %n', $dbName);
    }

    public function dropUser(string $user): void
    {
        $this->connection->query("DROP USER IF EXISTS %s@'localhost'", $user);
        $this->connection->query('FLUSH PRIVILEGES');
    }

    public function generatePassword(): string
    {
        $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|';
        $length = 32;
        $charsetLength = strlen($charset);
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $charset[random_int(0, $charsetLength - 1)];
        }

        return $password;
    }
}
