<?php

declare(strict_types=1);

final class Upload
{
    private const MAX_BYTES_IMAGEM = 2097152;
    private const MAX_BYTES_ARQUIVO = 20971520; // 20 MB

    private const MIMES_IMAGEM = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** Extensões permitidas no módulo Arquivos (tipo é escolhido pelo usuário). SVG não é permitido (XSS). */
    private const EXTENSOES_ARQUIVO = [
        'jpg', 'jpeg', 'png', 'webp',
        'cdr', 'ai', 'eps',
        'pdf',
        'zip', 'rar',
    ];

    private const EXTENSOES_DOCUMENTO_EMPRESA = ['pdf', 'jpg', 'jpeg', 'png'];

    private const EXTENSOES_BLOQUEADAS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'phps',
        'html', 'htm', 'shtml', 'js', 'mjs',
        'exe', 'bat', 'cmd', 'com', 'msi', 'scr',
        'sh', 'bash', 'cgi', 'pl', 'py', 'rb',
        'asp', 'aspx', 'jsp', 'htaccess',
        'svg', 'svgz',
    ];

    /**
     * @param array<string, mixed> $file
     * @return array{ok:bool, path:?string, error:?string}
     */
    public static function produtoImagem(array $file): array
    {
        return self::imagemCapa($file, 'produtos');
    }

    /**
     * Capa da coleção (mesmo padrão da foto do produto: JPG/PNG/WEBP, máx. 2 MB).
     *
     * @param array<string, mixed> $file
     * @return array{ok:bool, path:?string, error:?string}
     */
    public static function colecaoImagem(array $file): array
    {
        return self::imagemCapa($file, 'colecoes');
    }

    /**
     * Imagem de peça de estoque (mesmo padrão: JPG/PNG/WEBP, máx. 2 MB).
     *
     * @param array<string, mixed> $file
     * @return array{ok:bool, path:?string, error:?string}
     */
    public static function pecaImagem(array $file): array
    {
        return self::imagemCapa($file, 'estoque/pecas');
    }

    /**
     * Imagem de papel de estoque (mesmo padrão: JPG/PNG/WEBP, máx. 2 MB).
     *
     * @param array<string, mixed> $file
     * @return array{ok:bool, path:?string, error:?string}
     */
    public static function papelImagem(array $file): array
    {
        return self::imagemCapa($file, 'estoque/papeis');
    }

    /**
     * @param array<string, mixed> $file
     * @return array{ok:bool, path:?string, error:?string}
     */
    private static function imagemCapa(array $file, string $subdir): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE || ($file['tmp_name'] ?? '') === '') {
            return ['ok' => true, 'path' => null, 'error' => null];
        }

        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'path' => null, 'error' => 'Não foi possível enviar a imagem.'];
        }

        $tmp = (string) $file['tmp_name'];
        $uploadOk = is_uploaded_file($tmp);
        if (!$uploadOk && !(defined('PM_TEST_UPLOAD') && PM_TEST_UPLOAD && is_file($tmp))) {
            return ['ok' => false, 'path' => null, 'error' => 'Upload inválido.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES_IMAGEM) {
            return ['ok' => false, 'path' => null, 'error' => 'A imagem deve ter no máximo 2 MB.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!isset(self::MIMES_IMAGEM[$mime])) {
            return ['ok' => false, 'path' => null, 'error' => 'Envie uma imagem JPG, PNG ou WEBP.'];
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            return ['ok' => false, 'path' => null, 'error' => 'O arquivo enviado não é uma imagem válida.'];
        }

        $ext = self::MIMES_IMAGEM[$mime];
        $relativeDir = $subdir . '/' . date('Y') . '/' . date('m');
        $absoluteDir = dirname(__DIR__, 2) . '/public/uploads/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return ['ok' => false, 'path' => null, 'error' => 'Não foi possível salvar a imagem.'];
        }

        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $absolute = $absoluteDir . '/' . $name;
        $moved = $uploadOk
            ? move_uploaded_file($tmp, $absolute)
            : (@rename($tmp, $absolute) || @copy($tmp, $absolute));
        if (!$moved || !is_file($absolute)) {
            return ['ok' => false, 'path' => null, 'error' => 'Não foi possível salvar a imagem.'];
        }
        if (!$uploadOk && is_file($tmp)) {
            @unlink($tmp);
        }

        return ['ok' => true, 'path' => $relativeDir . '/' . $name, 'error' => null];
    }

    public static function removerImagemPublica(?string $caminhoRelativo): void
    {
        if ($caminhoRelativo === null || $caminhoRelativo === '') {
            return;
        }
        $rel = ltrim(str_replace(['\\', "\0"], ['/', ''], $caminhoRelativo), '/');
        $root = realpath(dirname(__DIR__, 2) . '/public/uploads');
        $abs = dirname(__DIR__, 2) . '/public/uploads/' . $rel;
        $real = realpath($abs);
        if ($root === false || $real === false || !str_starts_with($real, $root) || !is_file($real)) {
            return;
        }
        @unlink($real);
    }

    public static function maxBytesArquivo(): int
    {
        return self::MAX_BYTES_ARQUIVO;
    }

    /**
     * Limite efetivo considerando PHP (upload_max_filesize / post_max_size).
     */
    public static function limiteEfetivoBytes(): int
    {
        $phpUpload = self::iniToBytes((string) ini_get('upload_max_filesize'));
        $phpPost = self::iniToBytes((string) ini_get('post_max_size'));
        $limites = [self::MAX_BYTES_ARQUIVO];
        if ($phpUpload > 0) {
            $limites[] = $phpUpload;
        }
        if ($phpPost > 0) {
            // reserva mínima para campos do formulário
            $limites[] = max(0, $phpPost - 1048576);
        }
        return max(1, min($limites));
    }

    /**
     * @return list<string>
     */
    public static function avisosLimiteServidor(): array
    {
        $avisos = [];
        $app = self::MAX_BYTES_ARQUIVO;
        $upload = self::iniToBytes((string) ini_get('upload_max_filesize'));
        $post = self::iniToBytes((string) ini_get('post_max_size'));

        if ($upload > 0 && $upload < $app) {
            $avisos[] = 'PHP upload_max_filesize=' . ini_get('upload_max_filesize')
                . ' (menor que 20 MB). Ajuste no php.ini ou painel da hospedagem.';
        }
        if ($post > 0 && $post < $app) {
            $avisos[] = 'PHP post_max_size=' . ini_get('post_max_size')
                . ' (menor que 20 MB). Recomendado: post_max_size=22M.';
        }
        return $avisos;
    }

    /**
     * Upload de arquivo técnico do produto (fora do document root).
     *
     * @param array<string, mixed> $file
     * @return array{
     *   ok:bool,
     *   error:?string,
     *   caminho:?string,
     *   arquivo_fisico:?string,
     *   extensao:?string,
     *   mime:?string,
     *   tamanho:?int,
     *   nome_original:?string
     * }
     */
    public static function arquivoProduto(array $file, bool $obrigatorio = true): array
    {
        return self::arquivoPrivado($file, $obrigatorio, self::EXTENSOES_ARQUIVO, 'arquivos');
    }

    /**
     * Documento da empresa (PDF/JPG/JPEG/PNG), mesmas validações do arquivo de produto, fora do document root.
     *
     * @param array<string, mixed> $file
     * @return array{ok:bool, error:?string, caminho:?string, arquivo_fisico:?string, extensao:?string, mime:?string, tamanho:?int, nome_original:?string}
     */
    public static function documentoEmpresa(array $file): array
    {
        $r = self::arquivoPrivado($file, true, self::EXTENSOES_DOCUMENTO_EMPRESA, 'empresa/documentos');
        if ($r['ok'] && !in_array($r['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            self::removerArquivoFisico($r['caminho']);
            return self::failUpload('Envie um arquivo PDF, JPG ou PNG.');
        }
        return $r;
    }

    /**
     * @param array<string, mixed> $file
     * @param list<string> $extensoes
     */
    private static function arquivoPrivado(array $file, bool $obrigatorio, array $extensoes, string $subdir): array
    {
        $vazio = [
            'ok' => !$obrigatorio,
            'error' => $obrigatorio ? 'Selecione um arquivo para enviar.' : null,
            'caminho' => null,
            'arquivo_fisico' => null,
            'extensao' => null,
            'mime' => null,
            'tamanho' => null,
            'nome_original' => null,
        ];

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE || ($file['tmp_name'] ?? '') === '') {
            return $vazio;
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return self::failUpload('O arquivo excede o limite permitido (' . self::formatMb(self::limiteEfetivoBytes()) . ').');
        }
        if ($error !== UPLOAD_ERR_OK) {
            return self::failUpload('Não foi possível enviar o arquivo.');
        }

        $tmp = (string) $file['tmp_name'];
        $uploadOk = is_uploaded_file($tmp);
        if (!$uploadOk && !(defined('PM_TEST_UPLOAD') && PM_TEST_UPLOAD && is_file($tmp))) {
            return self::failUpload('Upload inválido.');
        }

        $size = (int) ($file['size'] ?? 0);
        $limite = self::limiteEfetivoBytes();
        if ($size <= 0) {
            return self::failUpload('Arquivo vazio.');
        }
        if ($size > self::MAX_BYTES_ARQUIVO || $size > $limite) {
            return self::failUpload('O arquivo deve ter no máximo ' . self::formatMb(min(self::MAX_BYTES_ARQUIVO, $limite)) . '.');
        }

        $original = (string) ($file['name'] ?? 'arquivo');
        $original = self::sanitizeOriginalName($original);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if ($ext === '' || in_array($ext, self::EXTENSOES_BLOQUEADAS, true)) {
            return self::failUpload('Formato de arquivo não permitido.');
        }
        if (!in_array($ext, $extensoes, true)) {
            return self::failUpload('Formato de arquivo não permitido.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: 'application/octet-stream');

        $mimeCheck = self::validarMimePorExtensao($ext, $mime, $tmp);
        if ($mimeCheck !== null) {
            return self::failUpload($mimeCheck);
        }

        $relativeDir = $subdir . '/' . date('Y') . '/' . date('m');
        $absoluteDir = self::storageRoot() . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return self::failUpload('Não foi possível salvar o arquivo.');
        }

        $fisico = bin2hex(random_bytes(16)) . '.' . $ext;
        $absolute = $absoluteDir . '/' . $fisico;
        $moved = $uploadOk
            ? move_uploaded_file($tmp, $absolute)
            : (@rename($tmp, $absolute) || @copy($tmp, $absolute));
        if (!$moved || !is_file($absolute)) {
            return self::failUpload('Não foi possível salvar o arquivo.');
        }
        if (!$uploadOk && is_file($tmp)) {
            @unlink($tmp);
        }
        @chmod($absolute, 0644);

        return [
            'ok' => true,
            'error' => null,
            'caminho' => $relativeDir . '/' . $fisico,
            'arquivo_fisico' => $fisico,
            'extensao' => $ext,
            'mime' => $mime,
            'tamanho' => $size,
            'nome_original' => $original,
        ];
    }

    public static function storageRoot(): string
    {
        return dirname(__DIR__, 2) . '/uploads';
    }

    public static function absolutePath(string $caminhoRelativo): string
    {
        $rel = str_replace(['\\', "\0"], ['/', ''], $caminhoRelativo);
        $rel = ltrim($rel, '/');
        return self::storageRoot() . '/' . $rel;
    }

    public static function removerArquivoFisico(?string $caminhoRelativo): void
    {
        if ($caminhoRelativo === null || $caminhoRelativo === '') {
            return;
        }
        $abs = self::absolutePath($caminhoRelativo);
        $root = realpath(self::storageRoot());
        $real = realpath($abs);
        if ($root === false || $real === false) {
            return;
        }
        if (!str_starts_with($real, $root) || !is_file($real)) {
            return;
        }
        @unlink($real);
    }

    private static function validarMimePorExtensao(string $ext, string $mime, string $tmp): ?string
    {
        $mime = strtolower(trim($mime));

        // Imagens: validar conteúdo real
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $info = @getimagesize($tmp);
            if ($info === false) {
                return 'O arquivo enviado não é uma imagem válida.';
            }
            $map = [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
            ];
            if (!in_array($mime, $map[$ext], true) && $mime !== 'application/octet-stream') {
                // aceita se getimagesize passou (alguns servidores reportam MIME genérico)
                if (!isset($info[2])) {
                    return 'Tipo de imagem incompatível.';
                }
            }
            return null;
        }

        if ($ext === 'pdf') {
            $head = (string) @file_get_contents($tmp, false, null, 0, 5);
            if ($head !== '%PDF-') {
                return 'O arquivo PDF é inválido.';
            }
            return null;
        }

        // CDR / AI / EPS / ZIP / RAR: MIME do navegador/servidor costuma ser genérico ou incorreto.
        // Aceitar com base na extensão permitida + ausência de assinatura de script.
        if (in_array($ext, ['cdr', 'ai', 'eps', 'zip', 'rar'], true)) {
            if (self::pareceScript($tmp)) {
                return 'Arquivo rejeitado por segurança.';
            }
            return null;
        }

        return null;
    }

    private static function pareceScript(string $tmp): bool
    {
        $head = (string) @file_get_contents($tmp, false, null, 0, 512);
        $lower = strtolower($head);
        return str_contains($lower, '<?php')
            || str_starts_with(ltrim($lower), '<?=')
            || str_contains($lower, '<script');
    }

    private static function sanitizeOriginalName(string $name): string
    {
        $name = basename(str_replace(["\0", '\\'], '', $name));
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = trim($name);
        if ($name === '') {
            $name = 'arquivo';
        }
        if (strlen($name) > 180) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $name = substr($base, 0, 170 - strlen($ext)) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    /**
     * @return array{
     *   ok:bool,
     *   error:string,
     *   caminho:null,
     *   arquivo_fisico:null,
     *   extensao:null,
     *   mime:null,
     *   tamanho:null,
     *   nome_original:null
     * }
     */
    private static function failUpload(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'caminho' => null,
            'arquivo_fisico' => null,
            'extensao' => null,
            'mime' => null,
            'tamanho' => null,
            'nome_original' => null,
        ];
    }

    private static function iniToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $num = (float) $value;
        return (int) match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (float) $value,
        };
    }

    private static function formatMb(int $bytes): string
    {
        $mb = $bytes / 1048576;
        return rtrim(rtrim(number_format($mb, 2, ',', '.'), '0'), ',') . ' MB';
    }
}
