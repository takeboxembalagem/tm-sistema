<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['port'] ?? '3306',
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(503);
            $host = (string) ($db['host'] ?? '');
            $isLocal = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
            if ($isLocal) {
                echo 'O MySQL local não está disponível. Se usa XAMPP, inicie o MySQL e tente novamente.';
            } else {
                echo 'Não foi possível conectar ao banco de dados. Verifique host, nome do banco, usuário e senha em config/config.php no servidor.';
            }
            exit(1);
        }

        $tz = self::$pdo->prepare('SET time_zone = :tz');
        $tz->execute(['tz' => self::offsetSessao()]);

        return self::$pdo;
    }

    /**
     * Offset atual (ex.: -03:00) do timezone configurado, para a sessão MySQL não depender do fuso do servidor.
     * Usa offset em vez do nome porque hospedagem compartilhada pode não ter as tabelas de timezone do MySQL.
     */
    private static function offsetSessao(): string
    {
        try {
            $zona = new DateTimeZone((string) config('timezone', date_default_timezone_get()));
        } catch (Exception) {
            $zona = new DateTimeZone(date_default_timezone_get());
        }
        $offset = (new DateTimeImmutable('now', $zona))->format('P');
        if (preg_match('/^[+-](0\d|1[0-4]):[0-5]\d$/', $offset) !== 1) {
            throw new RuntimeException('Offset de timezone inválido: ' . $offset);
        }
        return $offset;
    }
}
