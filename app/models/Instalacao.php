<?php

declare(strict_types=1);

/**
 * Criação do primeiro administrador (banco sem usuários).
 *
 * Autorização: a chave install_key do config/config.php (própria de cada instalação, fora do Git)
 * precisa ser informada no formulário. Sem chave configurada, a instalação pela web fica bloqueada.
 *
 * Concorrência: um lock nomeado do MySQL, exclusivo do banco atual, envolve a contagem e o INSERT;
 * pedidos simultâneos são serializados e só o primeiro encontra a tabela vazia.
 */
final class Instalacao
{
    public const CRIADO = 'criado';
    public const JA_INSTALADO = 'ja_instalado';
    public const OCUPADO = 'ocupado';

    public const TAMANHO_MIN_CHAVE = 32;
    public const ESPERA_LOCK_SEG = 10;

    /** Nome do lock por banco (máx. 64 caracteres no MySQL), para instalações no mesmo servidor não colidirem. */
    private const SQL_NOME_LOCK = "CONCAT('pm_instalacao_', SHA1(DATABASE()))";

    public static function chaveConfigurada(): bool
    {
        return strlen(trim((string) config('install_key', ''))) >= self::TAMANHO_MIN_CHAVE;
    }

    public static function chaveConfere(string $informada): bool
    {
        if (!self::chaveConfigurada()) {
            return false;
        }
        return hash_equals(trim((string) config('install_key')), trim($informada));
    }

    /**
     * Cria o administrador inicial (perfil admin, status ativo) somente se usuarios estiver vazia.
     *
     * @param array{nome: string, email: string, senha_hash: string} $dados
     * @return string CRIADO, JA_INSTALADO ou OCUPADO (lock não obtido a tempo; nada foi gravado)
     */
    public static function criarPrimeiroAdmin(array $dados): string
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT GET_LOCK(' . self::SQL_NOME_LOCK . ', :espera)');
        $stmt->execute(['espera' => self::ESPERA_LOCK_SEG]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return self::OCUPADO;
        }

        try {
            if (!Usuario::tabelaVazia()) {
                return self::JA_INSTALADO;
            }
            try {
                Usuario::create([
                    'nome' => $dados['nome'],
                    'email' => $dados['email'],
                    'senha_hash' => $dados['senha_hash'],
                    'status' => 'ativo',
                    'perfil' => 'admin',
                ]);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    return self::JA_INSTALADO;
                }
                throw $e;
            }
            return self::CRIADO;
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . self::SQL_NOME_LOCK . ')');
        }
    }
}
