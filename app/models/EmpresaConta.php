<?php

declare(strict_types=1);

/**
 * Contas bancárias da empresa. Nunca armazenar senhas, tokens ou códigos de segurança.
 */
final class EmpresaConta
{
    public const TIPOS = [
        'corrente' => 'Corrente',
        'poupanca' => 'Poupança',
        'outro' => 'Outro',
    ];

    /** @return list<array<string, mixed>> */
    public static function listar(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM empresa_contas WHERE empresa_id = :empresa ORDER BY ativo DESC, banco ASC, id ASC'
        );
        $stmt->execute(['empresa' => Empresa::ID]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM empresa_contas WHERE id = :id AND empresa_id = :empresa LIMIT 1');
        $stmt->execute(['id' => $id, 'empresa' => Empresa::ID]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO empresa_contas (empresa_id, banco, agencia, conta, tipo_conta, titular, documento_titular, observacao)
             VALUES (:empresa, :banco, :agencia, :conta, :tipo_conta, :titular, :documento_titular, :observacao)'
        );
        $stmt->execute(['empresa' => Empresa::ID] + self::params($data));
        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE empresa_contas
             SET banco = :banco, agencia = :agencia, conta = :conta, tipo_conta = :tipo_conta,
                 titular = :titular, documento_titular = :documento_titular, observacao = :observacao
             WHERE id = :id AND empresa_id = :empresa'
        );
        $stmt->execute(['id' => $id, 'empresa' => Empresa::ID] + self::params($data));
    }

    public static function setAtivo(int $id, bool $ativo): void
    {
        $stmt = Database::pdo()->prepare('UPDATE empresa_contas SET ativo = :ativo WHERE id = :id AND empresa_id = :empresa');
        $stmt->execute(['ativo' => $ativo ? 1 : 0, 'id' => $id, 'empresa' => Empresa::ID]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM empresa_contas WHERE id = :id AND empresa_id = :empresa');
        $stmt->execute(['id' => $id, 'empresa' => Empresa::ID]);
    }

    private static function params(array $data): array
    {
        return [
            'banco' => $data['banco'],
            'agencia' => $data['agencia'],
            'conta' => $data['conta'],
            'tipo_conta' => $data['tipo_conta'],
            'titular' => $data['titular'],
            'documento_titular' => $data['documento_titular'],
            'observacao' => $data['observacao'],
        ];
    }
}
