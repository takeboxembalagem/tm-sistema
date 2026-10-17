<?php

declare(strict_types=1);

$router = new Router();

$router->get('/instalacao', static function (): void {
    if (!Usuario::tabelaVazia()) {
        redirect('/login');
    }
    if (Auth::check()) {
        redirect('/produtos');
    }
    View::render('auth/instalacao', [
        'title' => 'Criar administrador inicial',
        'habilitada' => Instalacao::chaveConfigurada(),
        'errors' => flash('errors') ?? [],
        'old' => flash('old') ?? [],
    ], 'auth');
});

$router->post('/instalacao', static function (): void {
    Csrf::verify();

    if (!Usuario::tabelaVazia()) {
        redirect('/login');
    }
    if (!Instalacao::chaveConfigurada()) {
        redirect('/instalacao');
    }

    $nome = trim((string) ($_POST['nome'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $senha = (string) ($_POST['senha'] ?? '');
    $confirma = (string) ($_POST['confirmar_senha'] ?? '');
    $chave = $_POST['chave_instalacao'] ?? '';

    $errors = [];
    if (!is_string($chave) || !Instalacao::chaveConfere($chave)) {
        $errors[] = 'Chave de instalação inválida.';
    } else {
        if ($nome === '' || mb_strlen($nome) > 120) {
            $errors[] = 'Informe um nome válido.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
            $errors[] = 'Informe um e-mail válido.';
        }
        if (mb_strlen($senha) < 8) {
            $errors[] = 'A senha deve ter no mínimo 8 caracteres.';
        } elseif ($senha !== $confirma) {
            $errors[] = 'A confirmação da senha não confere.';
        }
    }

    if ($errors) {
        flash('errors', $errors);
        flash('old', ['nome' => $nome, 'email' => $email]);
        redirect('/instalacao');
    }

    $resultado = Instalacao::criarPrimeiroAdmin([
        'nome' => $nome,
        'email' => $email,
        'senha_hash' => Usuario::hashSenha($senha),
    ]);
    if ($resultado === Instalacao::OCUPADO) {
        flash('errors', ['Outra instalação está em andamento. Aguarde alguns segundos e tente novamente.']);
        flash('old', ['nome' => $nome, 'email' => $email]);
        redirect('/instalacao');
    }
    if ($resultado !== Instalacao::CRIADO) {
        redirect('/login');
    }

    $_SESSION['flash_success'] = 'Administrador criado com sucesso. Faça login para continuar.';
    redirect('/login');
});

$router->get('/login', static function (): void {
    if (Usuario::tabelaVazia()) {
        redirect('/instalacao');
    }
    if (Auth::check()) {
        redirect('/produtos');
    }
    View::render('auth/login', [
        'title' => 'Entrar',
        'error' => $_SESSION['flash_error'] ?? null,
        'success' => $_SESSION['flash_success'] ?? null,
    ], 'auth');
    unset($_SESSION['flash_error'], $_SESSION['flash_success']);
});

$router->post('/login', static function (): void {
    Csrf::verify();

    if (Usuario::tabelaVazia()) {
        redirect('/instalacao');
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = 'Informe um e-mail e senha válidos.';
        redirect('/login');
    }

    if (!Auth::chaveConfigurada()) {
        $_SESSION['flash_error'] = Auth::MSG_SEM_CHAVE;
        redirect('/login');
    }

    $resultado = Auth::tentarLogin($email, $password, LoginTentativa::ipRequisicao());
    if ($resultado === Auth::LOGIN_BLOQUEADO) {
        $_SESSION['flash_error'] = 'Muitas tentativas de login. Tente novamente mais tarde.';
        redirect('/login');
    }
    if ($resultado !== Auth::LOGIN_OK) {
        $_SESSION['flash_error'] = 'E-mail ou senha incorretos.';
        redirect('/login');
    }

    redirect('/produtos');
});

/** GET nunca encerra a sessão (link externo/pré-carregamento não pode deslogar); só o POST com CSRF. */
$router->get('/logout', static function (): void {
    redirect(Auth::check() ? '/produtos' : '/login');
});

$router->post('/logout', static function (): void {
    Csrf::verify();
    Auth::logout();
    redirect('/login');
});

$router->get('/', static function (): void {
    Auth::requireLogin();
    redirect('/produtos');
});

$protected = [];

$meta = [];

$router->get('/produtos', static fn () => ProdutoController::index());
$router->get('/produtos/novo', static fn () => ProdutoController::create());
$router->post('/produtos', static fn () => ProdutoController::store());
$router->get('/produtos/{id}', static fn (int $id) => ProdutoController::show($id));
$router->get('/produtos/{id}/editar', static fn (int $id) => ProdutoController::edit($id));
$router->post('/produtos/{id}', static fn (int $id) => ProdutoController::update($id));
$router->post('/produtos/{id}/status', static fn (int $id) => ProdutoController::toggle($id));
$router->post('/produtos/{id}/duplicar', static fn (int $id) => ProdutoController::duplicar($id));
$router->get('/produtos/{id}/excluir', static fn (int $id) => ProdutoController::confirmDelete($id));
$router->post('/produtos/{id}/excluir', static fn (int $id) => ProdutoController::destroy($id));
$router->post('/produtos/{id}/tiragem', static fn (int $id) => ComposicaoController::updateTiragem($id));
$router->post('/produtos/{id}/preco', static fn (int $id) => PrecoController::update($id));

$router->get('/produtos/{id}/variacoes/novo', static fn (int $id) => VariacaoController::create($id));
$router->post('/produtos/{id}/variacoes', static fn (int $id) => VariacaoController::store($id));
$router->get('/produtos/{id}/variacoes/{variacaoId}/editar', static fn (int $id, int $variacaoId) => VariacaoController::edit($id, $variacaoId));
$router->post('/produtos/{id}/variacoes/{variacaoId}', static fn (int $id, int $variacaoId) => VariacaoController::update($id, $variacaoId));
$router->post('/produtos/{id}/variacoes/{variacaoId}/excluir', static fn (int $id, int $variacaoId) => VariacaoController::destroy($id, $variacaoId));
$router->get('/produtos/{id}/variacoes/{variacaoId}/estoque', static fn (int $id, int $variacaoId) => VariacaoController::adjustForm($id, $variacaoId));
$router->post('/produtos/{id}/variacoes/{variacaoId}/estoque', static fn (int $id, int $variacaoId) => VariacaoController::adjustStore($id, $variacaoId));
$router->post('/produtos/{id}/variacoes/{variacaoId}/custo-modo', static fn (int $id, int $variacaoId) => VariacaoController::updateCustoModo($id, $variacaoId));
$router->post('/produtos/{id}/variacoes/{variacaoId}/composicao/recalcular', static fn (int $id, int $variacaoId) => VariacaoController::updateComposicaoQuantidades($id, $variacaoId));
$router->get('/produtos/{id}/variacoes/{variacaoId}/composicao/adicionar', static fn (int $id, int $variacaoId) => VariacaoController::composicaoEscolher($id, $variacaoId));
$router->get('/produtos/{id}/variacoes/{variacaoId}/composicao/novo', static fn (int $id, int $variacaoId) => VariacaoController::composicaoEscolher($id, $variacaoId));
$router->get('/produtos/{id}/variacoes/{variacaoId}/composicao/cadastrado/novo', static fn (int $id, int $variacaoId) => VariacaoController::composicaoCreate($id, $variacaoId));
$router->get('/produtos/{id}/variacoes/{variacaoId}/composicao/manual/novo', static fn (int $id, int $variacaoId) => VariacaoController::composicaoCreateManual($id, $variacaoId));
$router->post('/produtos/{id}/variacoes/{variacaoId}/composicao/manual', static fn (int $id, int $variacaoId) => VariacaoController::composicaoStoreManual($id, $variacaoId));
$router->post('/produtos/{id}/variacoes/{variacaoId}/composicao', static fn (int $id, int $variacaoId) => VariacaoController::composicaoStore($id, $variacaoId));
$router->get('/produtos/{id}/variacoes/{variacaoId}/composicao/{linhaId}/editar', static fn (int $id, int $variacaoId, int $linhaId) => VariacaoController::composicaoEdit($id, $variacaoId, $linhaId));
$router->post('/produtos/{id}/variacoes/{variacaoId}/composicao/{linhaId}', static fn (int $id, int $variacaoId, int $linhaId) => VariacaoController::composicaoUpdate($id, $variacaoId, $linhaId));
$router->post('/produtos/{id}/variacoes/{variacaoId}/composicao/{linhaId}/remover', static fn (int $id, int $variacaoId, int $linhaId) => VariacaoController::composicaoDestroy($id, $variacaoId, $linhaId));

$router->get('/produtos/{id}/composicao/adicionar', static fn (int $id) => ComposicaoController::escolher($id));
$router->get('/produtos/{id}/composicao/novo', static fn (int $id) => ComposicaoController::escolher($id));
$router->get('/produtos/{id}/composicao/cadastrado/novo', static fn (int $id) => ComposicaoController::create($id));
$router->get('/produtos/{id}/composicao/manual/novo', static fn (int $id) => ComposicaoController::createManual($id));
$router->post('/produtos/{id}/composicao/manual', static fn (int $id) => ComposicaoController::storeManual($id));
$router->post('/produtos/{id}/composicao', static fn (int $id) => ComposicaoController::store($id));
$router->get('/produtos/{id}/composicao/{composicaoId}/editar', static fn (int $id, int $composicaoId) => ComposicaoController::edit($id, $composicaoId));
$router->post('/produtos/{id}/composicao/{composicaoId}', static fn (int $id, int $composicaoId) => ComposicaoController::update($id, $composicaoId));
$router->post('/produtos/{id}/composicao/{composicaoId}/remover', static fn (int $id, int $composicaoId) => ComposicaoController::destroy($id, $composicaoId));

$router->get('/custos', static fn () => CustoController::index());
$router->get('/custos/novo', static fn () => CustoController::create());
$router->post('/custos', static fn () => CustoController::store());
$router->get('/custos/{id}', static fn (int $id) => CustoController::show($id));
$router->get('/custos/{id}/editar', static fn (int $id) => CustoController::edit($id));
$router->post('/custos/{id}', static fn (int $id) => CustoController::update($id));
$router->get('/custos/{id}/confirmar-custo', static fn (int $id) => CustoController::confirmForm($id));
$router->post('/custos/{id}/confirmar-custo', static fn (int $id) => CustoController::confirmStore($id));
$router->post('/custos/{id}/status', static fn (int $id) => CustoController::toggle($id));

$router->get('/arquivos', static fn () => ArquivoController::index());
$router->get('/arquivos/produto/{id}', static fn (int $id) => ArquivoController::produto($id));
$router->get('/arquivos/novo', static fn () => ArquivoController::create());
$router->post('/arquivos', static fn () => ArquivoController::store());
$router->get('/arquivos/{id}/editar', static fn (int $id) => ArquivoController::edit($id));
$router->post('/arquivos/{id}', static fn (int $id) => ArquivoController::update($id));
$router->post('/arquivos/{id}/excluir', static fn (int $id) => ArquivoController::destroy($id));
$router->get('/arquivos/{id}/download', static fn (int $id) => ArquivoController::download($id));

$router->get('/colecoes', static fn () => ColecaoController::index());
$router->get('/colecoes/novo', static fn () => ColecaoController::create());
$router->post('/colecoes', static fn () => ColecaoController::store());
$router->get('/colecoes/{id}', static fn (int $id) => ColecaoController::show($id));
$router->get('/colecoes/{id}/editar', static fn (int $id) => ColecaoController::edit($id));
$router->post('/colecoes/{id}', static fn (int $id) => ColecaoController::update($id));
$router->post('/colecoes/{id}/status', static fn (int $id) => ColecaoController::toggle($id));
$router->get('/colecoes/{id}/excluir', static fn (int $id) => ColecaoController::confirmDelete($id));
$router->post('/colecoes/{id}/excluir', static fn (int $id) => ColecaoController::destroy($id));
$router->post('/colecoes/{id}/vincular', static fn (int $id) => ColecaoController::vincularProduto($id));
$router->post('/colecoes/{id}/vincular-variacao', static fn (int $id) => ColecaoController::vincularVariacao($id));
$router->post('/colecoes/{id}/desvincular-variacao', static fn (int $id) => ColecaoController::desvincularVariacao($id));
$router->post('/colecoes/{id}/variacoes/{variacaoId}/resultado', static fn (int $id, int $variacaoId) => ColecaoController::salvarResultadoVariacao($id, $variacaoId));
$router->post('/colecoes/{id}/tarefas', static fn (int $id) => ColecaoController::criarTarefa($id));
$router->post('/colecoes/{id}/tarefas/{tarefaId}/editar', static fn (int $id, int $tarefaId) => ColecaoController::atualizarTarefa($id, $tarefaId));
$router->post('/colecoes/{id}/tarefas/{tarefaId}/concluir', static fn (int $id, int $tarefaId) => ColecaoController::concluirTarefa($id, $tarefaId));
$router->post('/colecoes/{id}/tarefas/{tarefaId}/reabrir', static fn (int $id, int $tarefaId) => ColecaoController::reabrirTarefa($id, $tarefaId));
$router->post('/colecoes/{id}/tarefas/{tarefaId}/excluir', static fn (int $id, int $tarefaId) => ColecaoController::excluirTarefa($id, $tarefaId));

$router->get('/estoque', static fn () => redirect('/estoque/pecas'));
$router->get('/estoque/pecas', static fn () => EstoquePecaController::index());
$router->get('/estoque/pecas/pdf/inventario', static fn () => EstoquePdfController::inventario('pecas'));
$router->get('/estoque/pecas/pdf/movimentacoes', static fn () => EstoquePdfController::movimentacoes('pecas'));
$router->get('/estoque/pecas/novo', static fn () => EstoquePecaController::create());
$router->post('/estoque/pecas', static fn () => EstoquePecaController::store());
$router->get('/estoque/pecas/{id}', static fn (int $id) => EstoquePecaController::show($id));
$router->get('/estoque/pecas/{id}/editar', static fn (int $id) => EstoquePecaController::edit($id));
$router->post('/estoque/pecas/{id}', static fn (int $id) => EstoquePecaController::update($id));
$router->post('/estoque/pecas/{id}/status', static fn (int $id) => EstoquePecaController::toggle($id));
$router->post('/estoque/pecas/{id}/excluir', static fn (int $id) => EstoquePecaController::destroy($id));
$router->post('/estoque/pecas/{id}/movimentos', static fn (int $id) => EstoquePecaController::movimento($id));

$router->get('/estoque/papeis', static fn () => EstoquePapelController::index());
$router->get('/estoque/papeis/pdf/inventario', static fn () => EstoquePdfController::inventario('papeis'));
$router->get('/estoque/papeis/pdf/movimentacoes', static fn () => EstoquePdfController::movimentacoes('papeis'));
$router->get('/estoque/papeis/novo', static fn () => EstoquePapelController::create());
$router->post('/estoque/papeis', static fn () => EstoquePapelController::store());
$router->get('/estoque/papeis/{id}', static fn (int $id) => EstoquePapelController::show($id));
$router->get('/estoque/papeis/{id}/editar', static fn (int $id) => EstoquePapelController::edit($id));
$router->post('/estoque/papeis/{id}', static fn (int $id) => EstoquePapelController::update($id));
$router->post('/estoque/papeis/{id}/status', static fn (int $id) => EstoquePapelController::toggle($id));
$router->post('/estoque/papeis/{id}/excluir', static fn (int $id) => EstoquePapelController::destroy($id));
$router->post('/estoque/papeis/{id}/movimentos', static fn (int $id) => EstoquePapelController::movimento($id));

$router->get('/tabela-precos', static fn () => TabelaPrecoController::index());
$router->post('/tabela-precos/config', static fn () => TabelaPrecoController::salvarConfig());
$router->post('/tabela-precos/margens', static fn () => TabelaPrecoController::addMargem());
$router->post('/tabela-precos/margens/{margemId}', static fn (int $margemId) => TabelaPrecoController::updateMargem($margemId));
$router->post('/tabela-precos/margens/{margemId}/excluir', static fn (int $margemId) => TabelaPrecoController::deleteMargem($margemId));

$router->get('/etiquetas', static fn () => EtiquetaController::index());
$router->get('/etiquetas/variacoes', static fn () => EtiquetaController::variacoesJson());
$router->get('/etiquetas/preview', static fn () => EtiquetaController::preview());
$router->get('/etiquetas/imprimir', static fn () => EtiquetaController::imprimir());
$router->get('/etiquetas/pdf', static fn () => EtiquetaController::pdf());

$router->get('/agenda', static fn () => AgendaController::index());
$router->post('/agenda/criar', static fn () => AgendaController::store());
$router->post('/agenda/{id}/editar', static fn (int $id) => AgendaController::update($id));
$router->post('/agenda/{id}/concluir', static fn (int $id) => AgendaController::concluir($id));
$router->post('/agenda/{id}/andamento', static fn (int $id) => AgendaController::andamento($id));
$router->post('/agenda/{id}/reabrir', static fn (int $id) => AgendaController::reabrir($id));
$router->post('/agenda/{id}/excluir', static fn (int $id) => AgendaController::destroy($id));
$router->post('/agenda/planos/criar', static fn () => AgendaController::planoStore());
$router->post('/agenda/planos/{id}/editar', static fn (int $id) => AgendaController::planoUpdate($id));
$router->post('/agenda/planos/{id}/status', static fn (int $id) => AgendaController::planoStatus($id));
$router->post('/agenda/planos/{id}/excluir', static fn (int $id) => AgendaController::planoDestroy($id));

$router->get('/relatorios', static fn () => RelatorioController::index());
$router->get('/relatorios/estoque', static fn () => RelatorioController::estoque());
$router->get('/relatorios/estoque/pdf', static fn () => RelatorioController::estoquePdf());

$router->get('/empresa', static fn () => EmpresaController::index());
$router->get('/empresa/editar', static fn () => EmpresaController::edit());
$router->post('/empresa', static fn () => EmpresaController::update());
$router->get('/empresa/envio', static fn () => EmpresaController::envio());
$router->post('/empresa/contas', static fn () => EmpresaController::contaStore());
$router->post('/empresa/contas/{id}/editar', static fn (int $id) => EmpresaController::contaUpdate($id));
$router->post('/empresa/contas/{id}/status', static fn (int $id) => EmpresaController::contaStatus($id));
$router->post('/empresa/contas/{id}/excluir', static fn (int $id) => EmpresaController::contaDestroy($id));
$router->post('/empresa/pix', static fn () => EmpresaController::pixStore());
$router->post('/empresa/pix/{id}/editar', static fn (int $id) => EmpresaController::pixUpdate($id));
$router->post('/empresa/pix/{id}/status', static fn (int $id) => EmpresaController::pixStatus($id));
$router->post('/empresa/pix/{id}/excluir', static fn (int $id) => EmpresaController::pixDestroy($id));
$router->post('/empresa/documentos', static fn () => EmpresaController::documentoStore());
$router->get('/empresa/documentos/{id}/download', static fn (int $id) => EmpresaController::documentoDownload($id));
$router->post('/empresa/documentos/{id}/excluir', static fn (int $id) => EmpresaController::documentoDestroy($id));

$router->get('/configuracoes', static fn () => ConfiguracaoController::index());
$router->get('/configuracoes/senha', static fn () => ConfiguracaoController::senhaForm());
$router->post('/configuracoes/senha', static fn () => ConfiguracaoController::senhaStore());
$router->get('/configuracoes/usuarios/novo', static fn () => ConfiguracaoController::create());
$router->post('/configuracoes/usuarios', static fn () => ConfiguracaoController::store());
$router->get('/configuracoes/usuarios/{id}/editar', static fn (int $id) => ConfiguracaoController::edit($id));
$router->post('/configuracoes/usuarios/{id}', static fn (int $id) => ConfiguracaoController::update($id));
$router->post('/configuracoes/usuarios/{id}/status', static fn (int $id) => ConfiguracaoController::toggle($id));
$router->get('/configuracoes/usuarios/{id}/senha', static fn (int $id) => ConfiguracaoController::senhaUsuarioForm($id));
$router->post('/configuracoes/usuarios/{id}/senha', static fn (int $id) => ConfiguracaoController::senhaUsuarioStore($id));

foreach ($protected as $path => [$view, $title, $page]) {
    $router->get($path, static function () use ($view, $title, $page, $meta): void {
        Auth::requireLogin();
        $data = [
            'title' => $title,
            'page' => $page,
        ];
        if (isset($meta[$page])) {
            $data['heading'] = $meta[$page]['heading'];
            $data['lead'] = $meta[$page]['lead'];
        }
        View::render($view, $data);
    });
}

$router->dispatch();