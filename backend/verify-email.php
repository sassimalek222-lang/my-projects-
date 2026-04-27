<?php
// ============================================================
// MedRDV – Vérification Email (backend/verify-email.php)
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$token  = $_GET['token'] ?? '';
$result = $token ? verifyEmail($token) : ['success' => false, 'error' => 'Token manquant.'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MedRDV – Vérification Email</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../resources/css/style.css">
<style>
.verify-box {
    display:flex;flex-direction:column;align-items:center;
    justify-content:center;min-height:100vh;
    text-align:center;padding:2rem;position:relative;z-index:10;
}
.verify-card {
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:20px;padding:3rem 2.5rem;
    max-width:480px;width:100%;
}
.verify-icon { font-size:4rem;margin-bottom:1.5rem; }
.verify-title {
    font-family:'Playfair Display',serif;
    font-size:1.8rem;margin-bottom:0.75rem;
}
.verify-msg { color:#8896a7;line-height:1.7;margin-bottom:2rem; }
.btn-verify {
    display:inline-block;background:#0ea5a0;color:#0a1628;
    padding:0.9rem 2rem;border-radius:12px;font-weight:600;
    font-size:1rem;text-decoration:none;transition:all 0.2s;
}
.btn-verify:hover { background:#14c9c4;transform:translateY(-2px); }
.btn-ghost-verify {
    display:inline-block;border:1px solid rgba(255,255,255,0.15);
    color:#fff;padding:0.9rem 2rem;border-radius:12px;font-weight:500;
    font-size:1rem;text-decoration:none;margin-left:0.75rem;transition:all 0.2s;
}
.btn-ghost-verify:hover { border-color:rgba(255,255,255,0.3); }
.pending-box {
    background:rgba(245,158,11,0.08);
    border:1px solid rgba(245,158,11,0.3);
    border-radius:12px;padding:1rem 1.25rem;
    color:#f59e0b;font-size:0.875rem;
    line-height:1.6;margin:1rem 0 1.5rem;
}
</style>
</head>
<body>
<div class="bg-pattern"></div>
<div class="bg-grid"></div>
<nav>
  <a href="../index.html" class="logo"><div class="logo-icon">🏥</div>Med<span>RDV</span></a>
</nav>

<div class="verify-box">
  <div class="verify-card">

    <?php if ($result['success']): ?>
      <div class="verify-icon">✅</div>
      <div class="verify-title" style="color:#0ea5a0;">Email vérifié !</div>

      <?php if (($result['role'] ?? '') === 'medecin'): ?>
        <p class="verify-msg">Votre adresse email a été confirmée avec succès.</p>
        <div class="pending-box">
          ⏳ <strong>Validation en cours</strong><br>
          Notre équipe examinera votre dossier médecin et vous enverra un email de confirmation sous 24h.
        </div>
        <a href="../login-medecin.html" class="btn-verify">Espace médecin →</a>

      <?php else: ?>
        <p class="verify-msg">
          Votre compte patient est maintenant actif.<br>
          Vous pouvez vous connecter et prendre vos rendez-vous.
        </p>
        <a href="../login-patient.html" class="btn-verify">Se connecter →</a>
      <?php endif; ?>

    <?php elseif (!empty($result['already'])): ?>
      <div class="verify-icon">ℹ️</div>
      <div class="verify-title">Déjà vérifié</div>
      <p class="verify-msg">Votre email a déjà été vérifié. Vous pouvez vous connecter.</p>
      <a href="../login-patient.html" class="btn-verify">Se connecter →</a>

    <?php else: ?>
      <div class="verify-icon">❌</div>
      <div class="verify-title" style="color:#ef4444;">Lien invalide</div>
      <p class="verify-msg">
        <?= htmlspecialchars($result['error'] ?? 'Ce lien est invalide ou a expiré.') ?><br><br>
        Vérifiez que vous avez copié le lien complet depuis votre email, ou réinscrivez-vous.
      </p>
      <a href="../register-patient.html" class="btn-verify">S'inscrire</a>
      <a href="../index.html" class="btn-ghost-verify">Accueil</a>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
