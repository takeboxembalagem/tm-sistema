<?php

declare(strict_types=1);

final class Arquivo
{
    public const TIPOS = [
        'imagem' => 'Imagem / Foto',
        'corel' => 'Corel',
        'pdf' => 'PDF',
        'faca' => 'Faca',
        'nesting' => 'Nesting',
        'mockup' => 'Mockup',
        'outro' => 'Outro',
    ];

    public const TIPO_ICONES = [
        'imagem' => '📷',
        'corel' => '🎨',
        'pdf' => '📄',
        'faca' => '📐',
        'nesting' => '📦',
        'mockup' => '🖼',
        'outro' => '📎',
    ];

    public static function tipoLabel(string $tipo): string
    {
        return self::TIPOS[$tipo] ?? $tipo;
    }

    public static function tipoIcone(string $tipo): string
    {
        return self::TIPO_ICONES[$tipo] ?? '📎';
    }

    public static function isTipoValido(string $tipo): bool
    {
        return isset(self::TIPOS[$tipo]);
    }

    public static function isImagem(?string $extensao, ?string $mime = null): bool
    {
        $ext = strtolower((string) $extensao);
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return true;
        }
        // Não tratar SVG (image/svg+xml) nem image/* genérico como preview inline.
        $mime = strtolower((string) $mime);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && $ext !== 'svg'
            && $ext !== 'svgz';
    }

    public static function isPdf(?string $extensao, ?string $mime = null): bool
    {
        if (strtolower((string) $extensao) === 'pdf') {
            return true;
        }
        return strtolower((string) $mime) === 'application/pdf';
    }

    public static function podeVisualizarInline(array $arquivo): bool
    {
        return self::isImagem($arquivo['extensao'] ?? null, $arquivo['mime'] ?? null)
            || self::isPdf($arquivo['extensao'] ?? null, $arquivo['mime'] ?? null);
    }

    public static function formatTamanho(?int $bytes): string
    {
        $b = (int) $bytes;
        if ($b < 1024) {
            return $b . ' B';
        }
        if ($b < 1048576) {
            return number_format($b / 1024, 1, ',', '.') . ' KB';
        }
        return number_format($b / 1048576, 2, ',', '.') . ' MB';
    }

    /** Ordem canônica dos tipos na listagem por produto. */
    private static function orderByTipoSql(string $alias = 'a'): string
    {
        $keys = array_keys(self::TIPOS);
        $quoted = array_map(static fn (string $k): string => "'" . str_replace("'", "''", $k) . "'", $keys);
        return 'FIELD(' . $alias . '.tipo, ' . implode(', ', $quoted) . ')';
    }

    /**
     * Lista produtos/caixas com contagem de arquivos (módulo Arquivos).
     * Busca por código, nome do produto ou nome do arquivo.
     *
     * @return list<array{id:int|string,codigo_interno:string,nome:string,status:string,total_arquivos:int|string}>
     */
    public static function listarProdutosComContagem(string $q = ''): array
    {
        $q = trim($q);
        $params = [];
        $where = [];

        if ($q !== '') {
            $where[] = '(
                p.codigo_interno LIKE :q_codigo
                OR p.nome LIKE :q_nome
                OR EXISTS (
                    SELECT 1 FROM arquivos_produtos a
                    WHERE a.produto_id = p.id
                      AND (a.nome LIKE :q_arq OR a.nome_original LIKE :q_orig)
                )
            )';
            $params['q_codigo'] = '%' . $q . '%';
            $params['q_nome'] = '%' . $q . '%';
            $params['q_arq'] = '%' . $q . '%';
            $params['q_orig'] = '%' . $q . '%';
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $stmt = Database::pdo()->prepare(
            "SELECT p.id, p.codigo_interno, p.nome, p.status,
                    (SELECT COUNT(*) FROM arquivos_produtos a WHERE a.produto_id = p.id) AS total_arquivos
             FROM produtos p
             {$sqlWhere}"
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        usort($items, 'cmp_natural_produto');

        return $items;
    }

    public static function contarPorProduto(int $produtoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM arquivos_produtos WHERE produto_id = :produto_id'
        );
        $stmt->execute(['produto_id' => $produtoId]);
        return (int) $stmt->fetchColumn();
    }

    public static function listarPorProduto(int $produtoId): array
    {
        $orderTipo = self::orderByTipoSql('a');
        $stmt = Database::pdo()->prepare(
            "SELECT a.*
             FROM arquivos_produtos a
             WHERE a.produto_id = :produto_id
             ORDER BY {$orderTipo} ASC, a.nome ASC, a.created_at DESC, a.id DESC"
        );
        $stmt->execute(['produto_id' => $produtoId]);
        return $stmt->fetchAll();
    }

    /**
     * Agrupa arquivos do produto pela ordem canônica de tipos.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function agruparPorTipo(array $arquivos): array
    {
        $grupos = [];
        foreach (array_keys(self::TIPOS) as $tipo) {
            $grupos[$tipo] = [];
        }
        foreach ($arquivos as $arq) {
            $tipo = (string) ($arq['tipo'] ?? 'outro');
            if (!isset($grupos[$tipo])) {
                $grupos[$tipo] = [];
            }
            $grupos[$tipo][] = $arq;
        }
        return $grupos;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.*, p.nome AS produto_nome, p.codigo_interno AS produto_codigo
             FROM arquivos_produtos a
             INNER JOIN produtos p ON p.id = a.produto_id
             WHERE a.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO arquivos_produtos
                (produto_id, tipo, nome, nome_original, arquivo_fisico, caminho, extensao, mime, tamanho, observacao, usuario_id)
             VALUES
                (:produto_id, :tipo, :nome, :nome_original, :arquivo_fisico, :caminho, :extensao, :mime, :tamanho, :observacao, :usuario_id)'
        );
        $stmt->execute([
            'produto_id' => $data['produto_id'],
            'tipo' => $data['tipo'],
            'nome' => $data['nome'],
            'nome_original' => $data['nome_original'],
            'arquivo_fisico' => $data['arquivo_fisico'],
            'caminho' => $data['caminho'],
            'extensao' => $data['extensao'],
            'mime' => $data['mime'],
            'tamanho' => $data['tamanho'],
            'observacao' => $data['observacao'],
            'usuario_id' => $data['usuario_id'],
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function updateMeta(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE arquivos_produtos
             SET produto_id = :produto_id,
                 tipo = :tipo,
                 nome = :nome,
                 observacao = :observacao
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'produto_id' => $data['produto_id'],
            'tipo' => $data['tipo'],
            'nome' => $data['nome'],
            'observacao' => $data['observacao'],
        ]);
    }

    public static function replaceFile(int $id, array $fileData): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE arquivos_produtos
             SET nome_original = :nome_original,
                 arquivo_fisico = :arquivo_fisico,
                 caminho = :caminho,
                 extensao = :extensao,
                 mime = :mime,
                 tamanho = :tamanho
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'nome_original' => $fileData['nome_original'],
            'arquivo_fisico' => $fileData['arquivo_fisico'],
            'caminho' => $fileData['caminho'],
            'extensao' => $fileData['extensao'],
            'mime' => $fileData['mime'],
            'tamanho' => $fileData['tamanho'],
        ]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM arquivos_produtos WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
