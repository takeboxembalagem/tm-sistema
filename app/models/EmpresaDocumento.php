<?php

declare(strict_types=1);

/**
 * Documentos da empresa (independentes do módulo Arquivos de produtos).
 * Arquivos em uploads/empresa/documentos (fora do document root).
 */
final class EmpresaDocumento
{
    public const TIPOS = [
        'cnpj' => 'CNPJ / Comprovante',
        'contrato_social' => 'Contrato Social',
        'alteracao_contratual' => 'Alteração Contratual',
        'inscricao_estadual' => 'Inscrição Estadual',
        'inscricao_municipal' => 'Inscrição Municipal',
        'certidao' => 'Certidão',
        'comprovante_bancario' => 'Comprovante Bancário',
        'outro' => 'Outro',
    ];

    /** Tipo de conteúdo enviado no download, pela extensão validada no upload. */
    public const MIMES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    /** @return list<array<string, mixed>> */
    public static function listar(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM empresa_documentos WHERE empresa_id = :empresa ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['empresa' => Empresa::ID]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM empresa_documentos WHERE id = :id AND empresa_id = :empresa LIMIT 1');
        $stmt->execute(['id' => $id, 'empresa' => Empresa::ID]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO empresa_documentos
                (empresa_id, nome, tipo_documento, nome_arquivo, caminho_arquivo, mime_type, tamanho, observacao)
             VALUES
                (:empresa, :nome, :tipo_documento, :nome_arquivo, :caminho_arquivo, :mime_type, :tamanho, :observacao)'
        );
        $stmt->execute([
            'empresa' => Empresa::ID,
            'nome' => $data['nome'],
            'tipo_documento' => $data['tipo_documento'],
            'nome_arquivo' => $data['nome_arquivo'],
            'caminho_arquivo' => $data['caminho_arquivo'],
            'mime_type' => $data['mime_type'],
            'tamanho' => $data['tamanho'],
            'observacao' => $data['observacao'],
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM empresa_documentos WHERE id = :id AND empresa_id = :empresa');
        $stmt->execute(['id' => $id, 'empresa' => Empresa::ID]);
    }

    public static function extensao(array $doc): string
    {
        return strtolower(pathinfo((string) $doc['caminho_arquivo'], PATHINFO_EXTENSION));
    }
}
