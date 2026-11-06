<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);

$errors = [];
$successMsg = '';
$diffs = [];
$explanation = '';
$tab = (string)($_GET['tab'] ?? 'manual');
if (!in_array($tab, ['manual', 'agent'], true)) {
    $tab = 'manual';
}

$openrouterReady = openrouter_is_configured($pdo);

// Estados do formulário manual
$manualSearch = trim((string)($_POST['search'] ?? ''));
$manualReplace = trim((string)($_POST['replace'] ?? ''));
$manualTarget = (string)($_POST['target'] ?? 'both');
$manualMode = (string)($_POST['mode'] ?? 'prefix');

// Estado do agente
$agentPrompt = trim((string)($_POST['agent_prompt'] ?? ''));

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    // 1. Simulação Manual (Sem IA)
    if ($action === 'manual_preview') {
        $tab = 'manual';
        if ($manualSearch === '') {
            $errors[] = 'Informe o domínio, caminho ou padrão que deseja buscar.';
        } else {
            $preview = channels_mass_replace_preview($pdo, $manualSearch, $manualReplace, $manualTarget, $manualMode);
            $diffs = $preview['diffs'];
            if (!$diffs) {
                $errors[] = 'Nenhum canal no banco corresponde ao padrão informado: ' . $manualSearch;
            }
        }
    }

    // 2. Execução Manual (Sem IA)
    elseif ($action === 'manual_execute') {
        $tab = 'manual';
        if ($manualSearch === '') {
            $errors[] = 'Dados insuficientes para executar a substituição.';
        } else {
            try {
                $updated = channels_mass_replace_execute($pdo, $manualSearch, $manualReplace, $manualTarget, $manualMode);
                $successMsg = sprintf('%d canais foram atualizados com sucesso no banco de dados!', $updated);
                $manualSearch = '';
                $manualReplace = '';
            } catch (Throwable $e) {
                $errors[] = 'Erro ao atualizar no banco de dados: ' . $e->getMessage();
            }
        }
    }

    // 3. Simulação Agente IA (OpenRouter)
    elseif ($action === 'agent_plan') {
        $tab = 'agent';
        if (!$openrouterReady) {
            $errors[] = 'A chave da API OpenRouter não está configurada. Acesse Configurações para cadastrar sua chave.';
        } elseif ($agentPrompt === '') {
            $errors[] = 'Digite uma instrução para o Agente IA.';
        } else {
            $plan = llm_channels_mass_replace_plan($pdo, $agentPrompt);
            if (!$plan['ok']) {
                $errors[] = 'Falha no Agente IA: ' . ($plan['error'] ?? 'Erro desconhecido');
            } else {
                $explanation = $plan['explanation'];
                $diffs = $plan['diffs'];
                if (!$diffs) {
                    $errors[] = 'O Agente IA analisou o banco, mas nenhum canal precisou ser alterado com base na sua instrução.';
                }
            }
        }
    }

    // 4. Execução Agente IA
    elseif ($action === 'agent_execute') {
        $tab = 'agent';
        $encodedDiffs = (string)($_POST['diffs_json'] ?? '');
        $incomingDiffs = json_decode($encodedDiffs, true);

        if (!is_array($incomingDiffs) || !$incomingDiffs) {
            $errors[] = 'Nenhuma alteração pendente para aplicar.';
        } else {
            try {
                $applied = channels_apply_diffs($pdo, $incomingDiffs);
                $successMsg = sprintf('%d campos de canais foram atualizados com sucesso pelas diretrizes da IA!', $applied);
            } catch (Throwable $e) {
                $errors[] = 'Erro ao salvar alterações no banco: ' . $e->getMessage();
            }
        }
    }
}

admin_header('Mudar Links em Massa', $user);
?>

<?php if ($successMsg): ?>
    <div class="alert alert-ok"><?= e($successMsg) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul class="errors">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="toolbar" style="margin-bottom:16px;">
    <div style="display:flex;gap:8px;">
        <a href="mass-replace.php?tab=manual" class="btn <?= $tab === 'manual' ? 'primary' : 'ghost' ?>">
            ⚡ Substituição Direta (Sem IA)
        </a>
        <a href="mass-replace.php?tab=agent" class="btn <?= $tab === 'agent' ? 'primary' : 'ghost' ?>">
            🤖 Agente IA (OpenRouter)
        </a>
    </div>
    <span class="badge <?= $openrouterReady ? 'badge-ok' : 'badge-off' ?>">
        <?= $openrouterReady ? 'OpenRouter Conectado' : 'OpenRouter Sem Chave' ?>
    </span>
</div>

<?php if ($tab === 'manual'): ?>
    <div class="card">
        <h2 class="section-title">Substituição em Massa por Padrão de URL</h2>
        <p class="hint">
            Altera o domínio, caminho ou query de <strong>todos os canais cadastrados</strong> (incluindo todas as opções: <code>espn</code>, <code>espnop2</code>, <code>espnop3</code>, <code>espnop4</code>... sem limite).
            Exemplo: de <code>site.com/canal.php?id=</code> para <code>sitecanal.com/canais.php?v=</code>
            (o identificador final do canal é preservado em todas as opções).
        </p>

        <form method="post" class="form mt-16">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="manual_preview">

            <div class="form-grid">
                <div class="field">
                    <label for="search">Padrão / Domínio / Caminho Atual (Buscar)</label>
                    <input id="search" name="search" type="text" required
                           value="<?= e($manualSearch) ?>"
                           placeholder="ex: site.com/canal.php?id=">
                    <p class="hint">Aceita com ou sem <code>http://</code>.</p>
                </div>

                <div class="field">
                    <label for="replace">Novo Domínio / Caminho / Parâmetro (Substituir por)</label>
                    <input id="replace" name="replace" type="text"
                           value="<?= e($manualReplace) ?>"
                           placeholder="ex: sitecanal.com/canais.php?v=">
                    <p class="hint">Para onde os links devem apontar.</p>
                </div>
            </div>

            <div class="form-grid mt-12">
                <div class="field">
                    <label for="mode">Modo de Substituição</label>
                    <select id="mode" name="mode">
                        <option value="prefix" <?= $manualMode === 'prefix' ? 'selected' : '' ?>>
                            Substituição de Prefixo & Caminho (Preserva o final do canal)
                        </option>
                        <option value="replace" <?= $manualMode === 'replace' ? 'selected' : '' ?>>
                            Busca e Troca Simples (String exata)
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label for="target">Quais links alterar em cada canal?</label>
                    <select id="target" name="target">
                        <option value="both" <?= $manualTarget === 'both' ? 'selected' : '' ?>>Todos os links (Principal e Reserva de emergência)</option>
                        <option value="url" <?= $manualTarget === 'url' ? 'selected' : '' ?>>Apenas o Link Principal do Player (url)</option>
                        <option value="url_backup" <?= $manualTarget === 'url_backup' ? 'selected' : '' ?>>Apenas o Link Reserva de Emergência (url_backup)</option>
                    </select>
                    <p class="hint">Aplica-se a todos os canais cadastrados no banco (opção 1, op2, op3, op4, etc., sem limite).</p>
                </div>
            </div>

            <div class="row-actions mt-16">
                <button type="submit" class="btn primary">Simular Alterações (Ver Prévia)</button>
                <a href="channels.php" class="btn ghost">Cancelar</a>
            </div>
        </form>
    </div>

<?php elseif ($tab === 'agent'): ?>
    <div class="card">
        <h2 class="section-title">Agente IA de Banco de Dados</h2>
        <p class="hint">
            Diga em linguagem natural como deseja modificar os links dos canais.
            O Agente LLM lê os registros do banco de dados, calcula as transformações ideais e mostra a prévia para você aprovar.
        </p>

        <?php if (!$openrouterReady): ?>
            <div class="alert alert-warn mt-12">
                Chave da API OpenRouter não configurada. <a href="settings.php">Configure a API aqui</a> para utilizar o Agente IA.
            </div>
        <?php endif; ?>

        <form method="post" class="form mt-16">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="agent_plan">

            <div class="field">
                <label for="agent_prompt">Instrução para a IA</label>
                <textarea id="agent_prompt" name="agent_prompt" rows="5" class="code-area"
                          placeholder="Exemplo: Troque todos os links que apontam para site.com/canal.php?id= para sitecanal.com/canais.php?v= mantendo o identificador do canal no final."><?= e($agentPrompt) ?></textarea>
                <p class="hint">Seja específico. A IA analisará os canais existentes e gerará uma proposta de modificação segura.</p>
            </div>

            <div class="row-actions mt-16">
                <button type="submit" class="btn primary" <?= !$openrouterReady ? 'disabled' : '' ?>>
                    Executar Análise com Agente IA
                </button>
                <a href="channels.php" class="btn ghost">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($diffs): ?>
    <div class="card mt-16">
        <div class="toolbar" style="margin-bottom:12px;">
            <div>
                <h3 class="section-title">
                    <?= $tab === 'agent' ? 'Proposta de Alterações do Agente IA' : 'Prévia das Alterações Identificadas' ?>
                </h3>
                <p class="hint">
                    <strong><?= count($diffs) ?> modificações</strong> serão realizadas.
                    Revise as URLs antigas e novas antes de confirmar.
                </p>
                <?php if ($explanation): ?>
                    <div class="mt-8" style="padding:8px 12px;background:var(--panel-2);border-radius:6px;border-left:3px solid var(--ok);font-size:14px;">
                        <strong>Análise da IA:</strong> <?= e($explanation) ?>
                    </div>
                <?php endif; ?>
            </div>
            <span class="badge badge-warn"><?= count($diffs) ?> Alterações</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Canal</th>
                    <th>Campo</th>
                    <th>URL Atual (Antes)</th>
                    <th>Nova URL (Depois)</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($diffs as $d): ?>
                    <tr>
                        <td>
                            <strong><?= e($d['name']) ?></strong>
                            <div class="sub mono">?v=<?= e($d['slug']) ?></div>
                        </td>
                        <td>
                            <span class="badge badge-muted">
                                <?= $d['field'] === 'url' ? 'Link Principal' : 'Link Reserva' ?>
                            </span>
                        </td>
                        <td class="sub mono" style="color:var(--danger);word-break:break-all;max-width:300px;">
                            <?= e($d['old_url']) ?>
                        </td>
                        <td class="sub mono" style="color:var(--ok);text-shadow:0 0 10px rgba(0,255,157,0.25);word-break:break-all;max-width:300px;">
                            <?= e($d['new_url']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="mt-16" data-confirm="Confirmar a aplicação de <?= count($diffs) ?> alterações no banco de dados?">
            <?= csrf_field() ?>
            <?php if ($tab === 'manual'): ?>
                <input type="hidden" name="action" value="manual_execute">
                <input type="hidden" name="search" value="<?= e($manualSearch) ?>">
                <input type="hidden" name="replace" value="<?= e($manualReplace) ?>">
                <input type="hidden" name="target" value="<?= e($manualTarget) ?>">
                <input type="hidden" name="mode" value="<?= e($manualMode) ?>">
            <?php else: ?>
                <input type="hidden" name="action" value="agent_execute">
                <input type="hidden" name="diffs_json" value="<?= e(json_encode($diffs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
            <?php endif; ?>

            <div class="row-actions">
                <button type="submit" class="btn primary">
                    Aplicar <?= count($diffs) ?> Alterações no Banco de Dados
                </button>
                <a href="mass-replace.php?tab=<?= e($tab) ?>" class="btn ghost">Descartar / Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php admin_footer(); ?>
