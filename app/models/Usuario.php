<?php

declare(strict_types=1);

final class Usuario
{
    public static function all(): array
    {
        return Database::pdo()
            ->query(
                'SELECT id, nome, email, status, perfil, created_at, updated_at
                 FROM usuarios
                 ORDER BY nome ASC, id ASC'
            )
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, nome, email, status, perfil, senha_hash, created_at, updated_at
             FROM usuarios
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findPublic(int $id): ?array
    {
        $u = self::find($id);
        if (!$u) {
            return null;
        }
        unset($u['senha_hash']);
        return $u;
    }

    public static function emailExiste(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM usuarios WHERE email = :email';
        $params = ['email' => $email];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public static function contarTodos(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    }

    public static function tabelaVazia(): bool
    {
        return self::contarTodos() === 0;
    }

    public static function contarAtivos(?int $exceptId = null): int
    {
        $sql = "SELECT COUNT(*) FROM usuarios WHERE status = 'ativo'";
        $params = [];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function contarAdminsAtivos(?int $exceptId = null): int
    {
        $sql = "SELECT COUNT(*) FROM usuarios WHERE status = 'ativo' AND perfil = 'admin'";
        $params = [];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function normalizePerfil(?string $perfil): string
    {
        return $perfil === 'admin' ? 'admin' : 'usuario';
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash, status, perfil)
             VALUES (:nome, :email, :senha_hash, :status, :perfil)'
        );
        $stmt->execute([
            'nome' => $data['nome'],
            'email' => $data['email'],
            'senha_hash' => $data['senha_hash'],
            'status' => $data['status'],
            'perfil' => self::normalizePerfil($data['perfil'] ?? 'usuario'),
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE usuarios
             SET nome = :nome,
                 email = :email,
                 status = :status,
                 perfil = :perfil
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'nome' => $data['nome'],
            'email' => $data['email'],
            'status' => $data['status'],
            'perfil' => self::normalizePerfil($data['perfil'] ?? 'usuario'),
        ]);
    }

    public static function setStatus(int $id, string $status): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE usuarios SET status = :status WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'status' => $status]);
    }

    public static function setSenha(int $id, string $senhaHash): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE usuarios SET senha_hash = :senha_hash WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'senha_hash' => $senhaHash]);
    }

    public static function hashSenha(string $senha): string
    {
        return password_hash($senha, PASSWORD_DEFAULT);
    }

    public static function verificarSenha(string $senha, string $hash): bool
    {
        return password_verify($senha, $hash);
    }
}
