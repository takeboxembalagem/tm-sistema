<?php

declare(strict_types=1);

final class EmpresaPix
{
    public const TIPOS = [
        'cnpj' => 'CNPJ',
        'telefone' => 'Telefone',
        'email' => 'E-mail',
        'aleatoria' => 'Chave aleatória',
        'outro' => 'Outro',
    ];

    /** @return list<array<string, mixed>> */
    public static function listar(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM empresa_pix WHERE empresa_id = :empresa
             ORDER BY ativo DESC, FIELD(tipo_chave, 'cnpj', 'email', 'telefone', 'aleatoria', 'outro'), id ASC"
        );
        $stmt->execute(['empresa' => Empresa::ID]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM empresa_pix WHERE id = :id AND empresa_id = :empresa LIMIT 1');
        $stmt->execute(['id' => $id, 'empresa' => Empresa::ID]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function chaveExiste(string $chave, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM empresa_pix WHERE empresa_id = :empresa AND chave = :chave';
        $params = ['empresa' => Empresa::ID, 'chave' => $chave];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Valida e normaliza a chave conforme o tipo.
     *
     * @return array{0:?string, 1:?string} [chave normalizada, erro]
     */
    public static function normalizarChave(string $tipo, string $chave): array
    {
        $chave = trim($chave);
        if ($chave === '') {
            return [null, 'Informe a chave PIX.'];
        }
        switch ($tipo) {
            case 'cnpj':
                return Empresa::cnpjValido($chave)
                    ? [Empresa::cnpjNormalizado($chave), null]
                    : [null, 'Chave PIX de CNPJ inválida.'];
            case 'telefone':
                $d = Empresa::soDigitos($chave);
                if (strlen($d) === 10 || strlen($d) === 11) {
                    $d = '55' . $d;
                }
                return preg_match('/^55\d{10,11}$/', $d) === 1
                    ? ['+' . $d, null]
                    : [null, 'Telefone PIX inválido. Use DDD + número.'];
            case 'email':
                $email = mb_strtolower($chave);
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 77
                    ? [$email, null]
                    : [null, 'E-mail da chave PIX inválido.'];
            case 'aleatoria':
                $evp = strtolower($chave);
                return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $evp) === 1
                    ? [$evp, null]
                    : [null, 'Chave aleatória inválida (formato 00000000-0000-0000-0000-000000000000).'];
            case 'outro':
                return mb_strlen($chave) <= 150 ? [$chave, null] : [null, 'A chave deve ter no máximo 150 caracteres.'];
        }
        return [null, 'Selecione o tipo da chave.'];
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO empresa_pix (empresa_id, tipo_chave, chave, identificacao, banco, observacao)
             VALUES (:empresa, :tipo_chave, :chave, :identificacao, :banco, :observacao)'
        );
        $stmt->execute(['empresa' => Empresa::ID] + self::params($data));
        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE empresa_pix
             SET tipo_chave = :tipo_chave, chave = :chave, identificacao = :identificacao, banco = :banco, observacao = :observacao
             WHERE id = :id AND empresa_id = :empresa'
        );
        $stmt->execute(['id' => $id, 'empresa' => Empresa::ID] + self::params($data));
    }

    public static function setAtivo(int $id, bool $ativo): void
    {
        $stmt = Database::pdo()->prepare('UPDATE empresa_pix SET ativo = :ativo WHERE id = :id AND empresa_id = :empresa');
        $stmt->execute(['ativo' => $ativo ? 1 : 0, 'id' => $id, 'empresa' => Empresa::ID]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM empresa_pix WHERE id = :id AND empresa_id = :empresa');
        $stmt->execute(['id' => $id, 'empresa' => Empresa::ID]);
    }

    private static function params(array $data): array
    {
        return [
            'tipo_chave' => $data['tipo_chave'],
            'chave' => $data['chave'],
            'identificacao' => $data['identificacao'],
            'banco' => $data['banco'],
            'observacao' => $data['observacao'],
        ];
    }
}
