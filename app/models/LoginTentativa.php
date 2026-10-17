<?php

declare(strict_types=1);

/**
 * Contadores de falhas de login (tabela login_tentativas), uma linha por e-mail digitado e uma por IP.
 *
 * Regra: LIMITE_EMAIL falhas do mesmo e-mail ou LIMITE_IP falhas do mesmo IP dentro de JANELA_MIN
 * minutos bloqueiam novas tentativas daquela chave por BLOQUEIO_MIN minutos. Horários sempre pelo
 * NOW() do MySQL (fuso da sessão fixado em Database).
 *
 * Concorrência: travar() precisa rodar dentro de transação; as duas linhas ficam com lock exclusivo
 * (sempre na ordem e-mail → IP, evitando deadlock entre logins) até o commit, então requisições
 * simultâneas da mesma chave são serializadas e cada uma enxerga o contador já gravado pela anterior.
 */
final class LoginTentativa
{
    public const LIMITE_EMAIL = 5;
    public const LIMITE_IP = 30;
    public const JANELA_MIN = 15;
    public const BLOQUEIO_MIN = 15;
    /** Linhas cuja janela começou há mais que isso (e sem bloqueio vigente) já não influenciam nada. */
    public const RETENCAO_HORAS = 24;

    /** Mesmo e-mail que o login usa (trim); a coluna é _ci como usuarios.email. Acima de 180 não existe conta. */
    public static function chaveEmail(string $email): string
    {
        return mb_substr($email, 0, 180);
    }

    /** Somente REMOTE_ADDR: cabeçalhos como X-Forwarded-For são do navegador e não são confiáveis. */
    public static function ipRequisicao(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'desconhecido';
    }

    /**
     * Trava as linhas do e-mail e do IP (criando-as se preciso) e zera janelas vencidas.
     *
     * @return array{bloqueado: bool, linhas: array<string, array{id: int, tentativas: int}>}
     */
    public static function travar(string $email, string $ip): array
    {
        $linhas = [
            'email' => self::travarLinha('email', self::chaveEmail($email)),
            'ip' => self::travarLinha('ip', $ip),
        ];
        $bloqueado = $linhas['email']['bloqueado'] || $linhas['ip']['bloqueado'];

        return ['bloqueado' => $bloqueado, 'linhas' => $linhas];
    }

    public static function registrarFalha(array $travado): void
    {
        $limites = ['email' => self::LIMITE_EMAIL, 'ip' => self::LIMITE_IP];
        $stmt = Database::pdo()->prepare(
            'UPDATE login_tentativas
             SET tentativas = :tentativas,
                 bloqueado_ate = IF(:atingiu = 1, NOW() + INTERVAL ' . self::BLOQUEIO_MIN . ' MINUTE, NULL)
             WHERE id = :id'
        );
        foreach ($travado['linhas'] as $tipo => $linha) {
            $novo = $linha['tentativas'] + 1;
            $stmt->execute(['tentativas' => $novo, 'atingiu' => $novo >= $limites[$tipo] ? 1 : 0, 'id' => $linha['id']]);
        }
    }

    /**
     * Zera só a conta que acertou a senha. O contador do IP é mantido: zerá-lo deixaria quem tem
     * uma conta válida "limpar" o IP entre rodadas de tentativas contra outras contas.
     */
    public static function registrarSucesso(array $travado): void
    {
        Database::pdo()->prepare('DELETE FROM login_tentativas WHERE id = :id')
            ->execute(['id' => $travado['linhas']['email']['id']]);
    }

    /** Limpeza oportunística (chamada a cada POST /login, fora da transação do login). */
    public static function limparAntigos(): void
    {
        Database::pdo()->exec(
            'DELETE FROM login_tentativas
             WHERE janela_inicio < NOW() - INTERVAL ' . self::RETENCAO_HORAS . ' HOUR
               AND (bloqueado_ate IS NULL OR bloqueado_ate < NOW())
             LIMIT 500'
        );
    }

    /** @return array{id: int, tentativas: int, bloqueado: bool} */
    private static function travarLinha(string $tipo, string $chave): array
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO login_tentativas (tipo, chave, tentativas, janela_inicio)
             VALUES (:tipo, :chave, 0, NOW())
             ON DUPLICATE KEY UPDATE tentativas = tentativas'
        )->execute(['tipo' => $tipo, 'chave' => $chave]);

        $stmt = $pdo->prepare(
            'SELECT id, tentativas,
                    (bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()) AS bloqueado,
                    (bloqueado_ate IS NOT NULL) AS tem_bloqueio,
                    (janela_inicio <= NOW() - INTERVAL ' . self::JANELA_MIN . ' MINUTE) AS janela_vencida
             FROM login_tentativas
             WHERE tipo = :tipo AND chave = :chave
             FOR UPDATE'
        );
        $stmt->execute(['tipo' => $tipo, 'chave' => $chave]);
        $row = $stmt->fetch();

        $linha = ['id' => (int) $row['id'], 'tentativas' => (int) $row['tentativas'], 'bloqueado' => (bool) $row['bloqueado']];
        if (!$linha['bloqueado'] && ((bool) $row['janela_vencida'] || (bool) $row['tem_bloqueio'])) {
            $pdo->prepare('UPDATE login_tentativas SET tentativas = 0, janela_inicio = NOW(), bloqueado_ate = NULL WHERE id = :id')
                ->execute(['id' => $linha['id']]);
            $linha['tentativas'] = 0;
        }

        return $linha;
    }
}
