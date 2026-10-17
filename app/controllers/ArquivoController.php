<?php

declare(strict_types=1);

final class ArquivoController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $q = trim((string) ($_GET['q'] ?? ''));
        $produtos = Arquivo::listarProdutosComContagem($q);

        View::render('arquivos/index', [
            'title' => 'Arquivos',
            'page' => 'arquivos',
            'produtosLista' => $produtos,
            'q' => $q,
            'success' => flash('success'),
            'avisosLimite' => Upload::avisosLimiteServidor(),
        ]);
    }

    public static function produto(int $produtoId): void
    {
        Auth::requireLogin();

        $produto = Produto::find($produtoId);
        if (!$produto) {
            self::notFound();
            return;
        }

        $arquivos = Arquivo::listarPorProduto($produtoId);

        View::render('arquivos/produto', [
            'title' => 'Arquivos · ' . $produto['codigo_interno'],
            'page' => 'arquivos',
            'produto' => $produto,
            'arquivos' => $arquivos,
            'grupos' => Arquivo::agruparPorTipo($arquivos),
            'total' => count($arquivos),
            'success' => flash('success'),
            'avisosLimite' => Upload::avisosLimiteServidor(),
        ]);
    }

    public static function create(): void
    {
        Auth::requireLogin();
        $produtoId = (int) ($_GET['produto_id'] ?? 0);
        if ($produtoId <= 0) {
            flash('errors', ['Selecione uma caixa/produto antes de adicionar o arquivo.']);
            redirect('/arquivos');
        }
        if (!Produto::find($produtoId)) {
            self::notFound();
            return;
        }
        self::form(null, $produtoId);
    }

    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        self::save(null);
    }

    public static function edit(int $id): void
    {
        Auth::requireLogin();
        $arquivo = Arquivo::find($id);
        if (!$arquivo) {
            self::notFound();
            return;
        }
        self::form($arquivo, null);
    }

    public static function update(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $arquivo = Arquivo::find($id);
        if (!$arquivo) {
            self::notFound();
            return;
        }
        self::save($arquivo);
    }

    public static function destroy(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $arquivo = Arquivo::find($id);
        if (!$arquivo) {
            self::notFound();
            return;
        }

        $produtoId = (int) $arquivo['produto_id'];
        $caminho = (string) $arquivo['caminho'];

        Arquivo::delete($id);
        Upload::removerArquivoFisico($caminho);

        flash('success', 'Arquivo excluído.');
        redirect('/arquivos/produto/' . $produtoId);
    }

    public static function download(int $id): void
    {
        Auth::requireLogin();
        $arquivo = Arquivo::find($id);
        if (!$arquivo) {
            http_response_code(404);
            echo 'Arquivo não encontrado.';
            return;
        }

        $abs = Upload::absolutePath((string) $arquivo['caminho']);
        $root = realpath(Upload::storageRoot());
        $real = realpath($abs);
        if ($root === false || $real === false || !str_starts_with($real, $root) || !is_file($real)) {
            http_response_code(404);
            echo 'Arquivo físico não encontrado.';
            return;
        }

        $inline = isset($_GET['inline']) && Arquivo::podeVisualizarInline($arquivo);
        $mime = (string) ($arquivo['mime'] ?: 'application/octet-stream');
        $downloadName = (string) ($arquivo['nome_original'] ?: $arquivo['arquivo_fisico']);
        $downloadName = str_replace(["\r", "\n", '"'], '', $downloadName);

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($real));
        header(
            ($inline ? 'Content-Disposition: inline' : 'Content-Disposition: attachment')
            . '; filename="' . $downloadName . '"'
        );
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfile($real);
        exit;
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private static function form(?array $existing, ?int $produtoPrefill): void
    {
        $old = flash('old') ?? [];
        $produtoId = (int) ($old['produto_id'] ?? $existing['produto_id'] ?? $produtoPrefill ?? 0);
        $produto = $produtoId > 0 ? Produto::find($produtoId) : null;

        View::render('arquivos/form', [
            'title' => $existing ? 'Editar arquivo' : 'Novo arquivo',
            'page' => 'arquivos',
            'arquivo' => $existing,
            'produtoFixo' => $produto,
            'tipos' => Arquivo::TIPOS,
            'errors' => flash('errors') ?? [],
            'old' => $old,
            'produtoPrefill' => $produtoId,
            'avisosLimite' => Upload::avisosLimiteServidor(),
            'limiteMb' => round(Upload::limiteEfetivoBytes() / 1048576, 1),
        ]);
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private static function save(?array $existing): void
    {
        $isEdit = $existing !== null;
        $produtoId = (int) ($_POST['produto_id'] ?? 0);
        $tipo = (string) ($_POST['tipo'] ?? '');
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        $substituir = isset($_FILES['arquivo']) && (int) ($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        // Em edição, produto não muda — preserva vínculo original.
        if ($isEdit) {
            $produtoId = (int) $existing['produto_id'];
        }

        $errors = [];
        if ($produtoId <= 0 || !Produto::find($produtoId)) {
            $errors[] = 'Produto/caixa inválido para o arquivo.';
        }
        if (!Arquivo::isTipoValido($tipo)) {
            $errors[] = 'Selecione o tipo de arquivo.';
        }
        if ($nome === '') {
            $errors[] = 'Informe o nome / descrição do arquivo.';
        } elseif (mb_strlen($nome) > 180) {
            $errors[] = 'O nome deve ter no máximo 180 caracteres.';
        }
        if (mb_strlen($observacao) > 2000) {
            $errors[] = 'A observação deve ter no máximo 2000 caracteres.';
        }

        $upload = null;
        if (!$isEdit || $substituir) {
            $upload = Upload::arquivoProduto($_FILES['arquivo'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => ''], true);
            if (!$upload['ok']) {
                $errors[] = $upload['error'] ?? 'Falha no envio do arquivo.';
            }
        }

        if ($errors) {
            if ($upload && !empty($upload['caminho'])) {
                Upload::removerArquivoFisico($upload['caminho']);
            }
            flash('errors', $errors);
            flash('old', [
                'produto_id' => $produtoId,
                'tipo' => $tipo,
                'nome' => $nome,
                'observacao' => $observacao,
            ]);
            if ($isEdit) {
                redirect('/arquivos/' . (int) $existing['id'] . '/editar');
            }
            redirect('/arquivos/novo?produto_id=' . $produtoId);
        }

        if (!$isEdit) {
            Arquivo::create([
                'produto_id' => $produtoId,
                'tipo' => $tipo,
                'nome' => $nome,
                'nome_original' => $upload['nome_original'],
                'arquivo_fisico' => $upload['arquivo_fisico'],
                'caminho' => $upload['caminho'],
                'extensao' => $upload['extensao'],
                'mime' => $upload['mime'],
                'tamanho' => $upload['tamanho'],
                'observacao' => $observacao !== '' ? $observacao : null,
                'usuario_id' => Auth::id(),
            ]);
            flash('success', 'Arquivo enviado com sucesso.');
        } else {
            $id = (int) $existing['id'];
            Arquivo::updateMeta($id, [
                'produto_id' => $produtoId,
                'tipo' => $tipo,
                'nome' => $nome,
                'observacao' => $observacao !== '' ? $observacao : null,
            ]);

            if ($substituir && $upload) {
                $caminhoAntigo = (string) $existing['caminho'];
                Arquivo::replaceFile($id, [
                    'nome_original' => $upload['nome_original'],
                    'arquivo_fisico' => $upload['arquivo_fisico'],
                    'caminho' => $upload['caminho'],
                    'extensao' => $upload['extensao'],
                    'mime' => $upload['mime'],
                    'tamanho' => $upload['tamanho'],
                ]);
                if ($caminhoAntigo !== $upload['caminho']) {
                    Upload::removerArquivoFisico($caminhoAntigo);
                }
                flash('success', 'Arquivo atualizado e substituído.');
            } else {
                flash('success', 'Informações do arquivo atualizadas.');
            }
        }

        redirect('/arquivos/produto/' . $produtoId);
    }

    private static function notFound(): void
    {
        http_response_code(404);
        View::render('pages/not_found', ['title' => 'Arquivo não encontrado', 'page' => 'arquivos']);
    }
}
