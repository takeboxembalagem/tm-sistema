<?php

declare(strict_types=1);

/**
 * Cadastro central da empresa. Registro único: sempre id = 1.
 */
final class Empresa
{
    public const ID = 1;

    public const REGIMES = [
        'mei' => 'MEI',
        'simples' => 'Simples Nacional',
        'presumido' => 'Lucro Presumido',
        'real' => 'Lucro Real',
        'outro' => 'Outro',
    ];

    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /** Campos editáveis do cadastro principal. */
    public const CAMPOS = [
        'razao_social', 'nome_fantasia', 'cnpj', 'inscricao_estadual', 'inscricao_municipal',
        'cnae_principal', 'regime_tributario', 'data_abertura',
        'cep', 'endereco', 'numero', 'complemento', 'bairro', 'cidade', 'estado',
        'telefone', 'whatsapp', 'email', 'site', 'pessoa_contato',
        'observacoes',
    ];

    public static function find(): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM empresa WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => self::ID]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Cria o registro único na primeira vez e atualiza nas seguintes. */
    public static function salvar(array $data): void
    {
        $colunas = implode(', ', self::CAMPOS);
        $valores = implode(', ', array_map(static fn (string $c): string => ':' . $c, self::CAMPOS));
        $updates = implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})", self::CAMPOS));

        $params = ['id' => self::ID];
        foreach (self::CAMPOS as $campo) {
            $params[$campo] = $data[$campo] ?? null;
        }

        $stmt = Database::pdo()->prepare(
            "INSERT INTO empresa (id, {$colunas}) VALUES (:id, {$valores})
             ON DUPLICATE KEY UPDATE {$updates}"
        );
        $stmt->execute($params);
    }

    public static function soDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }

    /** CNPJ sem máscara, em maiúsculas (aceita o formato alfanumérico da Receita). */
    public static function cnpjNormalizado(string $cnpj): string
    {
        return strtoupper(preg_replace('/[\s.\/-]+/', '', $cnpj) ?? '');
    }

    /** Numérico ou alfanumérico: 12 caracteres [A-Z0-9] + 2 dígitos verificadores (valor = ASCII − 48). */
    public static function cnpjValido(string $cnpj): bool
    {
        $d = self::cnpjNormalizado($cnpj);
        if (preg_match('/^[A-Z0-9]{12}\d{2}$/', $d) !== 1 || preg_match('/^(\d)\1{13}$/', $d) === 1) {
            return false;
        }
        foreach ([12, 13] as $pos) {
            $soma = 0;
            $peso = $pos - 7;
            for ($i = 0; $i < $pos; $i++) {
                $soma += (ord($d[$i]) - 48) * $peso;
                $peso = $peso === 2 ? 9 : $peso - 1;
            }
            $dv = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
            if ((int) $d[$pos] !== $dv) {
                return false;
            }
        }
        return true;
    }

    public static function formatarCnpj(string $cnpj): string
    {
        $d = self::cnpjNormalizado($cnpj);
        return strlen($d) === 14
            ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2)
            : $cnpj;
    }

    /** Endereço do site com protocolo (sem protocolo, assume https). */
    public static function urlSite(string $site): string
    {
        $site = trim($site);
        return preg_match('#^https?://#i', $site) === 1 ? $site : 'https://' . $site;
    }

    public static function regimeLabel(?string $regime): string
    {
        return self::REGIMES[(string) $regime] ?? '';
    }

    /**
     * Texto organizado para envio. Omite campos vazios e seções sem dados;
     * contas e chaves PIX: só as ativas. Observações internas não entram.
     *
     * @param list<array<string, mixed>> $contas
     * @param list<array<string, mixed>> $pix
     */
    public static function textoEnvio(?array $empresa, array $contas, array $pix): string
    {
        $e = $empresa ?? [];
        $v = static fn (string $k): string => trim((string) ($e[$k] ?? ''));
        $cidadeUf = trim($v('cidade') . ($v('cidade') !== '' && $v('estado') !== '' ? '/' : '') . $v('estado'));
        $abertura = $v('data_abertura') !== '' ? date('d/m/Y', strtotime($v('data_abertura'))) : '';

        $secoes = [
            'DADOS CADASTRAIS DA EMPRESA' => [[
                'Razão Social' => $v('razao_social'),
                'Nome Fantasia' => $v('nome_fantasia'),
                'CNPJ' => $v('cnpj'),
                'Inscrição Estadual' => $v('inscricao_estadual'),
                'Inscrição Municipal' => $v('inscricao_municipal'),
                'CNAE Principal' => $v('cnae_principal'),
                'Regime Tributário' => self::regimeLabel($v('regime_tributario')),
                'Data de Abertura' => $abertura,
            ]],
            'ENDEREÇO' => [[
                'Endereço' => $v('endereco'),
                'Número' => $v('numero'),
                'Complemento' => $v('complemento'),
                'Bairro' => $v('bairro'),
                'CEP' => $v('cep'),
                'Cidade/UF' => $cidadeUf,
            ]],
            'CONTATO' => [[
                'Telefone' => $v('telefone'),
                'WhatsApp' => $v('whatsapp'),
                'E-mail' => $v('email'),
                'Site' => $v('site'),
                'Pessoa de Contato' => $v('pessoa_contato'),
            ]],
            'DADOS BANCÁRIOS' => [],
            'PIX' => [],
        ];

        foreach ($contas as $c) {
            if ((int) $c['ativo'] !== 1) {
                continue;
            }
            $secoes['DADOS BANCÁRIOS'][] = [
                'Banco' => trim((string) $c['banco']),
                'Agência' => trim((string) $c['agencia']),
                'Conta' => trim((string) $c['conta']),
                'Tipo' => EmpresaConta::TIPOS[$c['tipo_conta']] ?? '',
                'Titular' => trim((string) ($c['titular'] ?? '')),
                'CPF/CNPJ do Titular' => trim((string) ($c['documento_titular'] ?? '')),
            ];
        }
        foreach ($pix as $p) {
            if ((int) $p['ativo'] !== 1) {
                continue;
            }
            $secoes['PIX'][] = [
                'Tipo' => EmpresaPix::TIPOS[$p['tipo_chave']] ?? '',
                'Chave' => trim((string) $p['chave']),
                'Identificação' => trim((string) ($p['identificacao'] ?? '')),
                'Banco' => trim((string) ($p['banco'] ?? '')),
            ];
        }

        $blocosTexto = [];
        foreach ($secoes as $titulo => $blocos) {
            $partes = [];
            foreach ($blocos as $campos) {
                $linhas = [];
                foreach ($campos as $rotulo => $valor) {
                    if ($valor !== '') {
                        $linhas[] = $rotulo . ': ' . $valor;
                    }
                }
                if ($linhas) {
                    $partes[] = implode("\n", $linhas);
                }
            }
            if ($partes) {
                $blocosTexto[] = $titulo . "\n\n" . implode("\n\n", $partes);
            }
        }
        return implode("\n\n", $blocosTexto);
    }
}
