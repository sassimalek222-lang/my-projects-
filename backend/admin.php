<?php
// ============================================================
// MedRDV – Fonctions d'administration
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// ─────────────────────────────────────────────────────────────
//  SÉCURITÉ – Vérifier droits admin
// ─────────────────────────────────────────────────────────────

function requireAdmin(): void {
    startSession();
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login-admin.html');
        exit;
    }
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        die(json_encode(['error' => 'Accès refusé. Droits administrateur requis.']));
    }
}

function isAdmin(): bool {
    startSession();
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin';
}

// ─────────────────────────────────────────────────────────────
//  STATISTIQUES TABLEAU DE BORD
// ─────────────────────────────────────────────────────────────

function getAdminStats(): array {
    $pdo = getDB();

    $stats = [];

    // Total utilisateurs par rôle
    $stmt = $pdo->query("SELECT role, COUNT(*) as total FROM utilisateurs GROUP BY role");
    foreach ($stmt->fetchAll() as $row) {
        $stats['users'][$row['role']] = (int)$row['total'];
    }
    $stats['users']['total'] = array_sum($stats['users'] ?? []);

    // Médecins en attente de validation
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM utilisateurs
        WHERE role = 'medecin' AND statut = 'en_attente'
    ");
    $stats['medecins_en_attente'] = (int)$stmt->fetch()['total'];

    // Rendez-vous du jour
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM rendez_vous
        WHERE DATE(created_at) = CURDATE()
    ");
    $stats['rdv_aujourd_hui'] = (int)($stmt->fetch()['total'] ?? 0);

    // Total rendez-vous
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM rendez_vous");
    $stats['rdv_total'] = (int)($stmt->fetch()['total'] ?? 0);

    // Comptes suspendus
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM utilisateurs WHERE statut = 'suspendu'
    ");
    $stats['comptes_suspendus'] = (int)$stmt->fetch()['total'];

    // Nouveaux comptes (7 derniers jours)
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM utilisateurs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stats['nouveaux_7j'] = (int)$stmt->fetch()['total'];

    return $stats;
}

// ─────────────────────────────────────────────────────────────
//  GESTION MÉDECINS EN ATTENTE
// ─────────────────────────────────────────────────────────────

function getMedecinsPending(): array {
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT
            u.id, u.prenom, u.nom, u.email, u.telephone,
            u.statut, u.email_verifie, u.created_at,
            m.specialite, m.localisation, m.numero_ordre,
            m.annees_experience, m.type_creneau
        FROM utilisateurs u
        JOIN medecins m ON m.utilisateur_id = u.id
        WHERE u.role = 'medecin' AND u.statut = 'en_attente'
        ORDER BY u.created_at ASC
    ");
    return $stmt->fetchAll();
}

function validateMedecin(int $userId): array {
    requireAdmin();
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, prenom, nom, email FROM utilisateurs WHERE id = ? AND role = 'medecin'");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'Médecin introuvable.'];
    }

    $pdo->prepare("
        UPDATE utilisateurs SET statut = 'actif' WHERE id = ?
    ")->execute([$userId]);

    // Notifier le médecin par email
    $html = emailTemplate(
        '✅ Votre compte médecin est activé !',
        "<p style='color:#94a3b8;line-height:1.7;'>
            Bonjour <strong style='color:#fff;'>{$user['prenom']}</strong>,<br><br>
            Félicitations ! Votre dossier médecin a été validé par notre équipe.
            Vous pouvez maintenant vous connecter et gérer vos rendez-vous sur MedRDV.
        </p>",
        BASE_URL . '/login-medecin.html',
        'Accéder à mon espace médecin →'
    );
    sendEmail($user['email'], $user['prenom'] . ' ' . $user['nom'],
              '✅ MedRDV – Compte médecin activé', $html);

    logAdminAction('validate_medecin', $userId, "Médecin {$user['prenom']} {$user['nom']} ({$user['email']}) validé");

    return ['success' => true, 'message' => "✅ Compte de Dr. {$user['prenom']} {$user['nom']} activé avec succès."];
}

function rejectMedecin(int $userId, string $raison = ''): array {
    requireAdmin();
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, prenom, nom, email FROM utilisateurs WHERE id = ? AND role = 'medecin'");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'Médecin introuvable.'];
    }

    $pdo->prepare("UPDATE utilisateurs SET statut = 'suspendu' WHERE id = ?")->execute([$userId]);

    $raisonHtml = $raison
        ? "<p style='background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);
               border-radius:8px;padding:12px;color:#f87171;font-size:0.85rem;margin:1rem 0;'>
               Motif : $raison
           </p>"
        : '';

    $html = emailTemplate(
        '❌ Votre demande n\'a pas été acceptée',
        "<p style='color:#94a3b8;line-height:1.7;'>
            Bonjour <strong style='color:#fff;'>{$user['prenom']}</strong>,<br><br>
            Après examen de votre dossier, nous ne sommes pas en mesure de valider votre compte médecin pour le moment.
        </p>
        $raisonHtml
        <p style='font-size:0.8rem;color:#64748b;'>
            Pour toute question, contactez notre support à support@medrdv.tn
        </p>"
    );
    sendEmail($user['email'], $user['prenom'] . ' ' . $user['nom'],
              '❌ MedRDV – Décision sur votre dossier médecin', $html);

    logAdminAction('reject_medecin', $userId, "Médecin {$user['prenom']} {$user['nom']} rejeté. Raison: $raison");

    return ['success' => true, 'message' => "Demande de Dr. {$user['prenom']} {$user['nom']} rejetée."];
}

// ─────────────────────────────────────────────────────────────
//  GESTION UTILISATEURS
// ─────────────────────────────────────────────────────────────

function getAllUsers(string $role = '', string $statut = '', int $page = 1, int $perPage = 20): array {
    requireAdmin();
    $pdo = getDB();

    $where = [];
    $params = [];

    if ($role) { $where[] = 'u.role = ?'; $params[] = $role; }
    if ($statut) { $where[] = 'u.statut = ?'; $params[] = $statut; }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT u.id, u.role, u.prenom, u.nom, u.email, u.telephone,
               u.statut, u.email_verifie, u.created_at,
               m.specialite, m.numero_ordre
        FROM utilisateurs u
        LEFT JOIN medecins m ON m.utilisateur_id = u.id
        $whereSQL
        ORDER BY u.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs u $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    return [
        'users'   => $users,
        'total'   => $total,
        'page'    => $page,
        'pages'   => ceil($total / $perPage),
        'perPage' => $perPage,
    ];
}

function suspendUser(int $userId): array {
    requireAdmin();
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, prenom, nom, email, role FROM utilisateurs WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) return ['success' => false, 'error' => 'Utilisateur introuvable.'];
    if ($user['role'] === 'admin') return ['success' => false, 'error' => 'Impossible de suspendre un administrateur.'];

    $pdo->prepare("UPDATE utilisateurs SET statut = 'suspendu' WHERE id = ?")->execute([$userId]);

    logAdminAction('suspend_user', $userId, "Utilisateur {$user['prenom']} {$user['nom']} ({$user['role']}) suspendu");

    return ['success' => true, 'message' => "Compte de {$user['prenom']} {$user['nom']} suspendu."];
}

function activateUser(int $userId): array {
    requireAdmin();
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, prenom, nom, email, role FROM utilisateurs WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) return ['success' => false, 'error' => 'Utilisateur introuvable.'];

    $pdo->prepare("UPDATE utilisateurs SET statut = 'actif' WHERE id = ?")->execute([$userId]);

    logAdminAction('activate_user', $userId, "Utilisateur {$user['prenom']} {$user['nom']} ({$user['role']}) réactivé");

    return ['success' => true, 'message' => "Compte de {$user['prenom']} {$user['nom']} réactivé."];
}

function deleteUser(int $userId): array {
    requireAdmin();
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, prenom, nom, role FROM utilisateurs WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) return ['success' => false, 'error' => 'Utilisateur introuvable.'];
    if ($user['role'] === 'admin') return ['success' => false, 'error' => 'Impossible de supprimer un administrateur.'];

    $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?")->execute([$userId]);

    logAdminAction('delete_user', $userId, "Utilisateur {$user['prenom']} {$user['nom']} ({$user['role']}) supprimé");

    return ['success' => true, 'message' => "Compte supprimé définitivement."];
}

// ─────────────────────────────────────────────────────────────
//  GESTION RENDEZ-VOUS
// ─────────────────────────────────────────────────────────────

function getAllRdv(int $page = 1, int $perPage = 20): array {
    requireAdmin();
    $pdo = getDB();
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT
            rv.id, rv.statut, rv.motif, rv.created_at,
            CONCAT(c.date_creneau, ' ', c.heure_debut) AS date_heure,
            up.prenom AS patient_prenom, up.nom AS patient_nom, up.email AS patient_email,
            um.prenom AS medecin_prenom, um.nom AS medecin_nom,
            m.specialite
        FROM rendez_vous rv
        JOIN creneaux c ON c.id = rv.creneau_id
        JOIN utilisateurs up ON up.id = rv.patient_id
        JOIN medecins m ON m.utilisateur_id = rv.medecin_id
        JOIN utilisateurs um ON um.id = m.utilisateur_id
        ORDER BY c.date_creneau DESC, c.heure_debut DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute();
    $rdvs = $stmt->fetchAll();

    $count = (int)$pdo->query("SELECT COUNT(*) FROM rendez_vous")->fetchColumn();

    return [
        'rdvs'    => $rdvs,
        'total'   => $count,
        'page'    => $page,
        'pages'   => ceil($count / $perPage),
    ];
}

// ─────────────────────────────────────────────────────────────
//  LOGS ADMIN
// ─────────────────────────────────────────────────────────────

function logAdminAction(string $action, int $targetId, string $details = ''): void {
    startSession();
    try {
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO admin_logs (admin_id, action, cible_id, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([
            $_SESSION['user_id'] ?? 0,
            $action,
            $targetId,
            json_encode(['message' => $details]),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("[MedRDV Admin] Log error: " . $e->getMessage());
    }
}

function getAdminLogs(int $limit = 50): array {
    requireAdmin();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT al.*, u.prenom, u.nom
        FROM admin_logs al
        JOIN utilisateurs u ON u.id = al.admin_id
        ORDER BY al.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $logs = $stmt->fetchAll();
    // Extraire le message depuis le JSON details
    foreach ($logs as &$log) {
        if (!empty($log['details'])) {
            $d = json_decode($log['details'], true);
            $log['details'] = $d['message'] ?? $log['details'];
        }
    }
    return $logs;
}

// ─────────────────────────────────────────────────────────────
//  CRÉER COMPTE ADMIN (usage unique / setup)
// ─────────────────────────────────────────────────────────────

function createAdminAccount(string $prenom, string $nom, string $email, string $password): array {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $stmt->execute([strtolower(trim($email))]);
    if ($stmt->fetch()) return ['success' => false, 'error' => 'Email déjà utilisé.'];

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare("
        INSERT INTO utilisateurs (role, prenom, nom, email, mot_de_passe, email_verifie, statut, created_at)
        VALUES ('admin', ?, ?, ?, ?, 1, 'actif', NOW())
    ")->execute([trim($prenom), trim($nom), strtolower(trim($email)), $hash]);

    return ['success' => true, 'message' => 'Compte administrateur créé.'];
}
