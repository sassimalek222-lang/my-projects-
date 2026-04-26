<?php
// ============================================================
// MedRDV – Réinitialisation Mot de Passe (backend/reset-password.php)
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$token  = $_GET['token'] ?? '';
$result = null;

// Traitement du formulaire POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = resetPassword(
        $_POST['token']            ?? '',
        $_POST['password']         ?? '',
        $_POST['password_confirm'] ?? ''
    );
}

// Vérifier que le token est valide avant d'afficher le formulaire
$tokenValid = false;
if ($token && !$result) {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE token_email = ? LIMIT 1");
    $stmt->execute([$token]);
    $tokenValid = (bool) $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MedRDV – Nouveau mot de passe</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../resources/css/style.css">
</head>
<body>
<div class="bg-pattern"></div>
<div class="bg-grid"></div>
<nav>
  <a href="../index.html" class="logo"><div class="logo-icon">🏥</div>Med<span>RDV</span></a>
</nav>

<div class="auth-wrap">
  <div class="auth-box">

    <?php if ($result && $result['success']): ?>
      <!-- ── Succès ── -->
      <div class="auth-logo">✅</div>
      <div class="auth-title">Mot de passe modifié !</div>
      <div class="auth-sub">Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.</div>
      <a href="../login-patient.html" class="btn-auth" style="display:block;text-align:center;text-decoration:none;margin-top:1.5rem;">
        Se connecter →
      </a>

    <?php elseif ($result && !$result['success']): ?>
      <!-- ── Erreur formulaire ── -->
      <div class="auth-logo">❌</div>
      <div class="auth-title">Erreur</div>
      <div class="alert-box" style="border-color:rgba(239,68,68,0.3);background:rgba(239,68,68,0.06);color:#f87171;">
        <?= htmlspecialchars($result['error'] ?? 'Une erreur est survenue.') ?>
      </div>
      <a href="../index.html" class="btn-auth" style="display:block;text-align:center;text-decoration:none;margin-top:1.5rem;">
        Retour à l'accueil
      </a>

    <?php elseif ($tokenValid): ?>
      <!-- ── Formulaire nouveau mot de passe ── -->
      <div class="auth-logo">🔐</div>
      <div class="auth-title">Nouveau mot de passe</div>
      <div class="auth-sub">Choisissez un mot de passe sécurisé (minimum 8 caractères)</div>

      <form method="POST" action="reset-password.php">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="form-group">
          <label>Nouveau mot de passe</label>
          <input type="password" name="password" placeholder="Minimum 8 caractères" required minlength="8">
        </div>
        <div class="form-group">
          <label>Confirmer le mot de passe</label>
          <input type="password" name="password_confirm" placeholder="Répétez le mot de passe" required minlength="8">
        </div>

        <button type="submit" class="btn-auth">Enregistrer le nouveau mot de passe →</button>
      </form>

    <?php else: ?>
      <!-- ── Token invalide / manquant ── -->
      <div class="auth-logo">⚠️</div>
      <div class="auth-title">Lien invalide</div>
      <div class="auth-sub">Ce lien de réinitialisation est invalide ou a expiré (valide 1 heure).</div>
      <div style="margin-top:1.5rem;display:flex;gap:0.75rem;flex-direction:column;">
        <a href="../login-patient.html" class="btn-auth" style="display:block;text-align:center;text-decoration:none;">
          Se connecter
        </a>
        <a href="../index.html" class="btn-ghost" style="display:block;text-align:center;">
          Retour à l'accueil
        </a>
      </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
