<?php
declare(strict_types=1);

/**
 * Evo Links TV · Arquivo de Configuração de Exemplo
 * 
 * O instalador automático (install.php) gera o arquivo config.php automaticamente.
 * Caso configure manualmente, copie este arquivo para config.php e preencha os dados.
 */

return [
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'nome_do_banco',
        'user'    => 'usuario_do_banco',
        'pass'    => 'senha_do_banco',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'         => 'Evo Links TV',
        'url'          => '', // Vazio para auto-detectar qualquer domínio e subpasta (ou ex: https://meusite.com)
        'timezone'     => 'America/Sao_Paulo',
        'session_name' => 'player_admin',
        'key'          => 'CHAVE_DE_64_CARACTERES_GERADA_NO_INSTALL_PHP',
        'debug'        => false, // false em produção para não exibir caminhos internos
    ],
];
