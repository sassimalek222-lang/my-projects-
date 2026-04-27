<?php
// ============================================================
// MedRDV – Fonctions d'authentification
// Version complète : email vérification + forgot/reset password
// ============================================================

require_once __DIR__ . '/config.php';

// ─────────────────────────────────────────────────────────────
//  SESSION
// ─────────────────────────────────────────────────────────────

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ? AND statut = 'actif'");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function requireAuth(string $role = ''): void {
    startSession();
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login-patient.html');
        exit;
    }
    if ($role && ($_SESSION['user_role'] ?? '') !== $role) {
        header('Location: ' . BASE_URL . '/index.html');
        exit;
    }
}

// ─────────────────────────────────────────────────────────────
//  ENVOI EMAIL (PHPMailer SMTP ou fallback mail())
// ─────────────────────────────────────────────────────────────

function sendEmail(string $to, string $toName, string $subject, string $htmlBody): bool {
    // DEV MODE : on logue dans error_log, pas d'envoi réel
    if (defined('DEV_MODE') && DEV_MODE) {
        error_log("[MedRDV] EMAIL vers $to | $subject | " . strip_tags($htmlBody));
        return true;
    }

    // PRODUCTION : PHPMailer (composer require phpmailer/phpmailer)
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USER;
            $mail->Password   = MAIL_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = MAIL_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->addAddress($to, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("[MedRDV] PHPMailer error: " . $e->getMessage());
        }
    }

    // Fallback : mail() natif
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    return mail($to, $subject, $htmlBody, $headers);
}

// ─────────────────────────────────────────────────────────────
//  TEMPLATE EMAIL
// ─────────────────────────────────────────────────────────────

function emailTemplate(string $titre, string $contenu, string $btnUrl = '', string $btnTexte = ''): string {
    $btn = $btnUrl ? "
      <div style='text-align:center;margin:2rem 0;'>
        <a href='$btnUrl'
           style='background:#0ea5a0;color:#0a1628;padding:14px 36px;border-radius:10px;
                  font-weight:700;font-size:1rem;text-decoration:none;display:inline-block;'>
          $btnTexte
        </a>
      </div>" : '';
    return "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'></head>
    <body style='margin:0;padding:0;background:#0a1628;font-family:Arial,sans-serif;'>
      <div style='max-width:560px;margin:40px auto;background:#112240;
                  border:1px solid rgba(255,255,255,0.08);border-radius:16px;overflow:hidden;'>
        <div style='background:linear-gradient(135deg,#0ea5a0,#0d9488);padding:2rem;text-align:center;'>
          <div style='font-size:2rem;'>🏥</div>
          <div style='font-size:1.4rem;font-weight:700;color:#fff;'>Med<span style='color:#ccfbf1;'>RDV</span></div>
        </div>
        <div style='padding:2rem 2.5rem;color:#e2e8f0;'>
          <h2 style='margin:0 0 1rem;font-size:1.25rem;color:#fff;'>$titre</h2>
          $contenu
          $btn
          <hr style='border:none;border-top:1px solid rgba(255,255,255,0.08);margin:1.5rem 0;'>
          <p style='font-size:0.72rem;color:#4a5568;margin:0;'>
            Message automatique de MedRDV · Ne pas répondre
          </p>
        </div>
      </div>
    </body></html>";
}

// ─── Email : vérification compte ──────────────────────────────
function sendVerificationEmail(string $email, string $prenom, string $token, string $role): void {
    $link = BASE_URL . '/backend/verify-email.php?token=' . urlencode($token);

    // Toujours logger le lien (utile en DEV)
    error_log("[MedRDV] Lien vérification pour $email ($role): $link");

    $extra = ($role === 'medecin')
        ? "<p style='background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);
               border-radius:8px;padding:12px;color:#f59e0b;font-size:0.85rem;margin:1rem 0;'>
               ⏳ Après vérification, votre dossier médecin sera examiné par notre équipe sous 24h.
           </p>" : '';

    $contenu = "
      <p style='color:#94a3b8;line-height:1.7;'>
        Bonjour <strong style='color:#fff;'>$prenom</strong>,<br><br>
        Merci de vous être inscrit sur MedRDV. Cliquez ci-dessous pour activer votre compte.
      </p>
      $extra
      <p style='font-size:0.8rem;color:#64748b;'>
        Ce lien expire dans <strong>24 heures</strong>.
        Si vous n'avez pas créé de compte, ignorez cet email.
      </p>";

    $html = emailTemplate(
        'Confirmez votre adresse email ✉️',
        $contenu,
        $link,
        'Vérifier mon email →'
    );
    sendEmail($email, $prenom, '✅ MedRDV – Confirmez votre email', $html);
}

// ─── Email : reset password ────────────────────────────────────
function sendResetPasswordEmail(string $email, string $prenom, string $token): void {
    $link = BASE_URL . '/backend/reset-password.php?token=' . urlencode($token);

    // Logger le lien (utile en DEV)
    error_log("[MedRDV] Lien reset password pour $email: $link");

    $contenu = "
      <p style='color:#94a3b8;line-height:1.7;'>
        Bonjour <strong style='color:#fff;'>$prenom</strong>,<br><br>
        Vous avez demandé à réinitialiser votre mot de passe MedRDV.
      </p>
      <p style='font-size:0.8rem;color:#64748b;'>
        Ce lien expire dans <strong>1 heure</strong>.
        Si vous n'avez pas fait cette demande, ignorez cet email.
      </p>";

    $html = emailTemplate(
        'Réinitialisation de mot de passe 🔐',
        $contenu,
        $link,
        'Réinitialiser mon mot de passe →'
    );
    sendEmail($email, $prenom, '🔐 MedRDV – Réinitialisation de mot de passe', $html);
}

// ─────────────────────────────────────────────────────────────
//  INSCRIPTION PATIENT
// ─────────────────────────────────────────────────────────────

function registerPatient(array $data): array {
    $pdo    = getDB();
    $errors = [];

    if (empty($data['prenom']))        $errors[] = 'Le prénom est requis.';
    if (empty($data['nom']))           $errors[] = 'Le nom est requis.';
    if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL))
                                       $errors[] = 'Email invalide.';
    if (strlen($data['password'] ?? '') < 8)
                                       $errors[] = 'Mot de passe minimum 8 caractères.';
    if (($data['password'] ?? '') !== ($data['password_confirm'] ?? ''))
                                       $errors[] = 'Les mots de passe ne correspondent pas.';

    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $stmt->execute([strtolower(trim($data['email'] ?? ''))]);
    if ($stmt->fetch()) $errors[] = 'Cet email est déjà utilisé.';

    if ($errors) return ['success' => false, 'errors' => $errors, 'message' => implode(' ', $errors)];

    $hash  = password_hash($data['password'], PASSWORD_BCRYPT);
    $token = bin2hex(random_bytes(32));

    // DEV MODE → compte actif directement
    // PROD     → en_attente jusqu'à vérification email
    $devMode      = defined('DEV_MODE') && DEV_MODE;
    $emailVerifie = $devMode ? 1 : 0;
    $statut       = $devMode ? 'actif' : 'en_attente';
    $tokenStore   = $devMode ? null : $token;

    $stmt = $pdo->prepare("
        INSERT INTO utilisateurs
            (role, prenom, nom, email, mot_de_passe, telephone, date_naissance, genre,
             email_verifie, token_email, statut, created_at)
        VALUES ('patient', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        trim($data['prenom']),
        trim($data['nom']),
        strtolower(trim($data['email'])),
        $hash,
        $data['telephone'] ?? null,
        !empty($data['date_naissance']) ? $data['date_naissance'] : null,
        $data['genre'] ?? null,
        $emailVerifie, $tokenStore, $statut,
    ]);
    $userId = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO dossiers_medicaux (patient_id) VALUES (?)")->execute([$userId]);
    try { $pdo->prepare("INSERT INTO confidentialite_patient (patient_id) VALUES (?)")->execute([$userId]); }
    catch (Exception $e) { /* table optionnelle */ }

    // Logger/envoyer le lien de vérification
    sendVerificationEmail($data['email'], trim($data['prenom']), $token, 'patient');

    if ($devMode) {
        return [
            'success' => true,
            'user_id' => $userId,
            'message' => '✅ Compte créé (DEV MODE) ! Vous pouvez vous connecter directement.',
        ];
    }
    return [
        'success' => true,
        'message' => '📧 Compte créé ! Vérifiez votre email pour activer votre compte.',
    ];
}

// ─────────────────────────────────────────────────────────────
//  INSCRIPTION MÉDECIN
// ─────────────────────────────────────────────────────────────

function registerMedecin(array $data): array {
    $pdo    = getDB();
    $errors = [];

    if (empty($data['prenom']))        $errors[] = 'Le prénom est requis.';
    if (empty($data['nom']))           $errors[] = 'Le nom est requis.';
    if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL))
                                       $errors[] = 'Email invalide.';
    if (strlen($data['password'] ?? '') < 8)
                                       $errors[] = 'Mot de passe minimum 8 caractères.';
    if (($data['password'] ?? '') !== ($data['password_confirm'] ?? ''))
                                       $errors[] = 'Les mots de passe ne correspondent pas.';
    if (empty($data['specialite']))    $errors[] = 'La spécialité est requise.';
    if (empty($data['numero_ordre']))  $errors[] = 'Le numéro d\'ordre est requis.';

    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $stmt->execute([strtolower(trim($data['email'] ?? ''))]);
    if ($stmt->fetch()) $errors[] = 'Cet email est déjà utilisé.';

    $stmt = $pdo->prepare("SELECT id FROM medecins WHERE numero_ordre = ?");
    $stmt->execute([$data['numero_ordre'] ?? '']);
    if ($stmt->fetch()) $errors[] = 'Ce numéro d\'ordre est déjà enregistré.';

    if ($errors) return ['success' => false, 'errors' => $errors, 'message' => implode(' ', $errors)];

    $hash  = password_hash($data['password'], PASSWORD_BCRYPT);
    $token = bin2hex(random_bytes(32));

    $devMode      = defined('DEV_MODE') && DEV_MODE;
    $emailVerifie = $devMode ? 1 : 0;
    $tokenStore   = $devMode ? null : $token;
    // Médecin TOUJOURS en_attente (validation admin obligatoire)
    $statut = 'en_attente';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO utilisateurs
                (role, prenom, nom, email, mot_de_passe, telephone,
                 email_verifie, token_email, statut, created_at)
            VALUES ('medecin', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            trim($data['prenom']),
            trim($data['nom']),
            strtolower(trim($data['email'])),
            $hash,
            $data['telephone'] ?? null,
            $emailVerifie, $tokenStore, $statut,
        ]);
        $userId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO medecins
                (utilisateur_id, specialite, localisation, numero_ordre,
                 type_creneau, annees_experience, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId, $data['specialite'],
            $data['localisation'] ?? null, $data['numero_ordre'],
            $data['type_creneau'] ?? '20', intval($data['annees_experience'] ?? 0),
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'errors' => ['Erreur serveur: ' . $e->getMessage()]];
    }

    sendVerificationEmail($data['email'], trim($data['prenom']), $token, 'medecin');

    return [
        'success' => true,
        'message' => $devMode
            ? '⏳ Profil médecin créé (DEV MODE). Validation admin requise avant connexion.'
            : '📧 Profil créé ! Vérifiez votre email. Votre compte sera activé après validation de notre équipe.',
    ];
}

// ─────────────────────────────────────────────────────────────
//  LOGIN
// ─────────────────────────────────────────────────────────────

function loginUser(string $email, string $password): array {
    startSession();
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = ?");
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['mot_de_passe'])) {
        return ['success' => false, 'error' => 'Email ou mot de passe incorrect.'];
    }
    if (!$user['email_verifie']) {
        return [
            'success' => false,
            'error'   => '📧 Veuillez vérifier votre email avant de vous connecter.',
            'code'    => 'EMAIL_NOT_VERIFIED'
        ];
    }
    if ($user['statut'] === 'en_attente') {
        return [
            'success' => false,
            'error'   => ($user['role'] === 'medecin')
                ? '⏳ Votre dossier médecin est en cours de validation. Vous serez notifié par email.'
                : '⏳ Votre compte est en attente d\'activation.',
            'code' => 'PENDING'
        ];
    }
    if ($user['statut'] === 'suspendu') {
        return ['success' => false, 'error' => '🚫 Votre compte est suspendu. Contactez le support.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['user_role']   = $user['role'];
    $_SESSION['user_prenom'] = $user['prenom'];
    $_SESSION['user_nom']    = $user['nom'];
    $_SESSION['user_email']  = $user['email'];

    $redirects = [
        'patient' => BASE_URL . '/dashboard-patient.html',
        'medecin' => BASE_URL . '/dashboard-medecin.html',
        'admin'   => BASE_URL . '/index.html',
    ];

    return [
        'success'  => true,
        'role'     => $user['role'],
        'prenom'   => $user['prenom'],
        'redirect' => $redirects[$user['role']] ?? BASE_URL . '/index.html',
    ];
}

// ─────────────────────────────────────────────────────────────
//  VÉRIFICATION EMAIL (via lien cliqué)
// ─────────────────────────────────────────────────────────────

function verifyEmail(string $token): array {
    if (empty($token)) return ['success' => false, 'error' => 'Token manquant.'];

    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE token_email = ? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) return ['success' => false, 'error' => 'Lien invalide ou déjà utilisé.'];

    if ($user['email_verifie']) {
        return ['success' => true, 'role' => $user['role'], 'message' => '✅ Email déjà vérifié.', 'already' => true];
    }

    $newStatut = ($user['role'] === 'patient') ? 'actif' : 'en_attente';

    $pdo->prepare("
        UPDATE utilisateurs SET email_verifie = 1, token_email = NULL, statut = ?
        WHERE id = ?
    ")->execute([$newStatut, $user['id']]);

    return ($user['role'] === 'patient')
        ? ['success' => true, 'role' => 'patient', 'message' => '✅ Email vérifié ! Vous pouvez maintenant vous connecter.']
        : ['success' => true, 'role' => 'medecin', 'message' => '✅ Email vérifié ! Votre dossier est en cours d\'examen. Vous serez notifié par email dès l\'activation.'];
}

// ─────────────────────────────────────────────────────────────
//  MOT DE PASSE OUBLIÉ
// ─────────────────────────────────────────────────────────────

function forgotPassword(string $email): array {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT id, prenom FROM utilisateurs WHERE email = ? LIMIT 1");
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    // Sécurité : ne jamais révéler si l'email existe
    if (!$user) {
        return ['success' => true, 'message' => 'Si cet email est enregistré, vous recevrez un lien de réinitialisation.'];
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE utilisateurs SET token_reset = ? WHERE id = ?")->execute([$token, $user['id']]);

    sendResetPasswordEmail($email, $user['prenom'], $token);

    return ['success' => true, 'message' => 'Si cet email est enregistré, vous recevrez un lien de réinitialisation sous quelques minutes.'];
}

// ─────────────────────────────────────────────────────────────
//  RÉINITIALISATION MOT DE PASSE
// ─────────────────────────────────────────────────────────────

function resetPassword(string $token, string $password, string $passwordConfirm): array {
    if (empty($token))        return ['success' => false, 'error' => 'Token manquant.'];
    if (strlen($password) < 8) return ['success' => false, 'error' => 'Mot de passe minimum 8 caractères.'];
    if ($password !== $passwordConfirm) return ['success' => false, 'error' => 'Les mots de passe ne correspondent pas.'];

    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE token_reset = ? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) return ['success' => false, 'error' => 'Lien invalide ou expiré. Veuillez refaire une demande.'];

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ?, token_reset = NULL WHERE id = ?")->execute([$hash, $user['id']]);

    return ['success' => true, 'message' => '✅ Mot de passe modifié ! Vous pouvez maintenant vous connecter.'];
}

// ─────────────────────────────────────────────────────────────
//  DÉCONNEXION
// ─────────────────────────────────────────────────────────────

function logoutUser(): void {
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
