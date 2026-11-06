<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);
$errors = [];
$msg = (string)($_GET['msg'] ?? '');

if (is_post()) {
    csrf_check();

    $newUsername = trim((string)($_POST['username'] ?? ''));
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!password_verify($currentPassword, (string)$user['password_hash'])) {
        $errors[] = 'Senha atual incorreta.';
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $newUsername)) {
        $errors[] = 'Nome de usuário inválido. Use de 3 a 50 caracteres (letras, números, ponto, hífen ou underline).';
    } elseif ($newUsername !== $user['username']) {
        $checkStmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $checkStmt->execute([$newUsername, (int)$user['id']]);
        if ($checkStmt->fetchColumn()) {
            $errors[] = 'Este nome de usuário já está em uso.';
        }
    }

    $changingPassword = ($newPassword !== '' || $confirmPassword !== '');
    if ($changingPassword) {
        if (strlen($newPassword) < 8) {
            $errors[] = 'A nova senha precisa ter pelo menos 8 caracteres.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'A confirmação da nova senha não confere.';
        }
    }

    if (!$errors) {
        if ($changingPassword) {
            $stmt = $pdo->prepare(
                'UPDATE users SET username = ?, password_hash = ?, must_change_password = 0 WHERE id = ?'
            );
            $stmt->execute([
                $newUsername,
                password_hash($newPassword, PASSWORD_DEFAULT),
                (int)$user['id'],
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users SET username = ? WHERE id = ?'
            );
            $stmt->execute([
                $newUsername,
                (int)$user['id'],
            ]);
        }

        session_regenerate_id(true);
        redirect('account.php?msg=saved');
    }
}

admin_header('Minha conta', $user);
?>

<?php if ($msg === 'saved'): ?>
    <div class="alert alert-ok">Dados da conta atualizados com sucesso.</div>
<?php endif; ?>

<?php if ((int)$user['must_change_password'] === 1): ?>
    <div class="alert alert-warn">Esta conta ainda usa a senha padrão. Troque agora.</div>
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

<form method="post" class="card form">
    <?= csrf_field() ?>

    <div class="form-grid">
        <div class="field">
            <label for="username">Nome de usuário</label>
            <input id="username" name="username" type="text"
                   value="<?= e($user['username']) ?>" required
                   autocomplete="username" minlength="3" maxlength="50">
            <p class="hint">3 a 50 caracteres (letras, números, hífen, underline).</p>
        </div>

        <div class="field">
            <label for="current_password">Senha atual (necessária para salvar)</label>
            <input id="current_password" name="current_password" type="password"
                   autocomplete="current-password" required>
            <p class="hint">Confirme sua senha atual para autorizar as alterações.</p>
        </div>
    </div>

    <div class="form-grid mt-16">
        <div class="field">
            <label for="new_password">Nova senha (opcional)</label>
            <input id="new_password" name="new_password" type="password"
                   autocomplete="new-password" minlength="8"
                   placeholder="Deixe em branco para manter a atual">
            <p class="hint">Mínimo de 8 caracteres caso queira alterar.</p>
        </div>

        <div class="field">
            <label for="confirm_password">Confirmar nova senha</label>
            <input id="confirm_password" name="confirm_password" type="password"
                   autocomplete="new-password" minlength="8"
                   placeholder="Repita a nova senha se for alterar">
        </div>
    </div>

    <div class="row-actions mt-16">
        <button class="btn primary" type="submit">Salvar alterações</button>
    </div>
</form>

<?php admin_footer(); ?>
