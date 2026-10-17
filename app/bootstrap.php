<?php

declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo 'Arquivo de configuração não encontrado. Copie config/config.example.php para config/config.php.';
    exit(1);
}

$GLOBALS['config'] = require $configFile;

date_default_timezone_set($GLOBALS['config']['timezone'] ?? 'America/Sao_Paulo');

require_once dirname(__DIR__) . '/vendor/autoload.php';

require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Session.php';
require_once __DIR__ . '/core/Csrf.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Areas.php';
require_once __DIR__ . '/core/View.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Upload.php';
require_once __DIR__ . '/models/Produto.php';
require_once __DIR__ . '/controllers/ProdutoController.php';
require_once __DIR__ . '/models/Variacao.php';
require_once __DIR__ . '/controllers/VariacaoController.php';
require_once __DIR__ . '/models/Material.php';
require_once __DIR__ . '/controllers/CustoController.php';
require_once __DIR__ . '/models/Composicao.php';
require_once __DIR__ . '/controllers/ComposicaoController.php';
require_once __DIR__ . '/models/PrecoFormacao.php';
require_once __DIR__ . '/controllers/PrecoController.php';
require_once __DIR__ . '/models/Arquivo.php';
require_once __DIR__ . '/controllers/ArquivoController.php';
require_once __DIR__ . '/models/Colecao.php';
require_once __DIR__ . '/controllers/ColecaoController.php';
require_once __DIR__ . '/models/EstoquePeca.php';
require_once __DIR__ . '/controllers/EstoquePecaController.php';
require_once __DIR__ . '/models/EstoquePapel.php';
require_once __DIR__ . '/controllers/EstoquePapelController.php';
require_once __DIR__ . '/models/TabelaPrecoCalculo.php';
require_once __DIR__ . '/models/TabelaPreco.php';
require_once __DIR__ . '/controllers/TabelaPrecoController.php';
require_once __DIR__ . '/models/Usuario.php';
require_once __DIR__ . '/models/Instalacao.php';
require_once __DIR__ . '/models/LoginTentativa.php';
require_once __DIR__ . '/controllers/ConfiguracaoController.php';
require_once __DIR__ . '/controllers/EtiquetaController.php';
require_once __DIR__ . '/models/AgendaTarefa.php';
require_once __DIR__ . '/models/AgendaPlano.php';
require_once __DIR__ . '/controllers/AgendaController.php';
require_once __DIR__ . '/models/RelatorioEstoque.php';
require_once __DIR__ . '/controllers/RelatorioController.php';
require_once __DIR__ . '/models/Empresa.php';
require_once __DIR__ . '/models/EmpresaConta.php';
require_once __DIR__ . '/models/EmpresaPix.php';
require_once __DIR__ . '/models/EmpresaDocumento.php';
require_once __DIR__ . '/controllers/EmpresaController.php';
require_once __DIR__ . '/pdf/EtiquetaPdf.php';
require_once __DIR__ . '/pdf/DocumentoPdf.php';
require_once __DIR__ . '/pdf/EstoqueRelatoriosPdf.php';
require_once __DIR__ . '/controllers/EstoquePdfController.php';

Session::start($GLOBALS['config']['session'] ?? []);
