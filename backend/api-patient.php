<?php
// ============================================================
// MedRDV – API Patient (backend/api-patient.php)
// Gestion des rendez-vous, créneaux, notifications
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

startSession();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Lire body JSON ou form-data
$data = [];
$raw  = file_get_contents('php://input');
if ($raw) {
    $json = json_decode($raw, true);
    if ($json) $data = $json;
}
if (empty($data)) $data = array_merge($_GET, $_POST);

// ─── Routes publiques (sans auth) ────────────────────────────
$publicActions = ['get_medecins', 'get_creneaux_medecin'];

if (!in_array($action, $publicActions)) {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Non connecté.']);
        exit;
    }
}

// ─── Router ──────────────────────────────────────────────────
switch ($action) {

    // ── Liste des médecins disponibles ─────────────────────────
    case 'get_medecins':
        echo json_encode(getMedecins($data));
        break;

    // ── Créneaux disponibles d'un médecin ──────────────────────
    case 'get_creneaux_medecin':
        $medecinId = intval($data['medecin_id'] ?? 0);
        $date      = $data['date'] ?? '';
        echo json_encode(getCreneauxMedecin($medecinId, $date));
        break;

    // ── Prendre un rendez-vous ─────────────────────────────────
    case 'prendre_rdv':
        requireRole('patient');
        echo json_encode(prendreRendezVous($data));
        break;

    // ── Mes rendez-vous ────────────────────────────────────────
    case 'mes_rdv':
        requireRole('patient');
        echo json_encode(getMesRendezVous());
        break;

    // ── Annuler un rendez-vous ─────────────────────────────────
    case 'annuler_rdv':
        requireRole('patient');
        $rdvId = intval($data['rdv_id'] ?? 0);
        echo json_encode(annulerRendezVous($rdvId));
        break;

    // ── Confirmer un rendez-vous (réponse au rappel) ───────────
    case 'confirmer_rdv':
        requireRole('patient');
        $rdvId = intval($data['rdv_id'] ?? 0);
        echo json_encode(confirmerRendezVous($rdvId));
        break;

    // ── Mes notifications ──────────────────────────────────────
    case 'mes_notifications':
        echo json_encode(getMesNotifications());
        break;

    // ── Marquer notification lue ───────────────────────────────
    case 'marquer_lu':
        $notifId = intval($data['notif_id'] ?? 0);
        echo json_encode(marquerNotificationLue($notifId));
        break;

    // ── Marquer toutes lues ────────────────────────────────────
    case 'tout_marquer_lu':
        echo json_encode(marquerToutesLues());
        break;

    // ── Profil du patient connecté ─────────────────────────────
    case 'get_profil':
        echo json_encode(getProfilPatient());
        break;

    // ── Vérifier session ───────────────────────────────────────
    case 'check_session':
        if (isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'patient') {
            echo json_encode([
                'logged_in' => true,
                'user_id'   => $_SESSION['user_id'],
                'prenom'    => $_SESSION['user_prenom'],
                'nom'       => $_SESSION['user_nom'],
                'email'     => $_SESSION['user_email'],
            ]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Action inconnue: ' . $action]);
}

// ─────────────────────────────────────────────────────────────
//  HELPER : Vérifier rôle
// ─────────────────────────────────────────────────────────────
function requireRole(string $role): void {
    if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== $role) {
        http_response_code(403);
        echo json_encode(['error' => 'Accès refusé.']);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────
//  LISTE MÉDECINS
// ─────────────────────────────────────────────────────────────
function getMedecins(array $data): array {
    $pdo = getDB();

    $where  = ["u.statut = 'actif'", "u.role = 'medecin'"];
    $params = [];

    if (!empty($data['specialite'])) {
        $where[]  = 'm.specialite = ?';
        $params[] = $data['specialite'];
    }
    if (!empty($data['localisation'])) {
        $where[]  = 'm.localisation LIKE ?';
        $params[] = '%' . $data['localisation'] . '%';
    }
    if (!empty($data['nom'])) {
        $where[]  = "(u.prenom LIKE ? OR u.nom LIKE ?)";
        $params[] = '%' . $data['nom'] . '%';
        $params[] = '%' . $data['nom'] . '%';
    }

    $sql = "
        SELECT
            m.id            AS medecin_id,
            u.prenom, u.nom, u.email, u.telephone,
            m.specialite, m.localisation, m.annees_experience,
            m.type_creneau,
            COALESCE(AVG(a.note), 0) AS note_moyenne,
            COUNT(DISTINCT a.id)     AS nb_avis
        FROM medecins m
        JOIN utilisateurs u ON u.id = m.utilisateur_id
        LEFT JOIN avis a ON a.medecin_id = m.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY m.id
        ORDER BY note_moyenne DESC, u.nom ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [
        'success'  => true,
        'medecins' => $stmt->fetchAll(),
    ];
}

// ─────────────────────────────────────────────────────────────
//  CRÉNEAUX DISPONIBLES
// ─────────────────────────────────────────────────────────────
function getCreneauxMedecin(int $medecinId, string $date = ''): array {
    if (!$medecinId) return ['success' => false, 'error' => 'Médecin manquant.'];

    $pdo = getDB();

    // Si pas de date, retourner les 30 prochains jours
    if (empty($date)) {
        $stmt = $pdo->prepare("
            SELECT
                c.id, c.date_creneau, c.heure_debut, c.heure_fin, c.statut
            FROM creneaux c
            WHERE c.medecin_id = ?
              AND c.statut = 'libre'
              AND c.date_creneau >= CURDATE()
              AND c.date_creneau <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY c.date_creneau ASC, c.heure_debut ASC
            LIMIT 100
        ");
        $stmt->execute([$medecinId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                c.id, c.date_creneau, c.heure_debut, c.heure_fin, c.statut
            FROM creneaux c
            WHERE c.medecin_id = ?
              AND c.statut = 'libre'
              AND c.date_creneau = ?
            ORDER BY c.heure_debut ASC
        ");
        $stmt->execute([$medecinId, $date]);
    }

    return [
        'success'  => true,
        'creneaux' => $stmt->fetchAll(),
    ];
}

// ─────────────────────────────────────────────────────────────
//  PRENDRE UN RENDEZ-VOUS
// ─────────────────────────────────────────────────────────────
function prendreRendezVous(array $data): array {
    $pdo       = getDB();
    $patientId = $_SESSION['user_id'];
    $creneauId = intval($data['creneau_id'] ?? 0);
    $motif     = trim($data['motif'] ?? '');

    if (!$creneauId) return ['success' => false, 'error' => 'Créneau manquant.'];

    // Vérifier que le créneau est libre
    $stmt = $pdo->prepare("
        SELECT c.id, c.medecin_id, c.date_creneau, c.heure_debut, c.heure_fin, c.statut
        FROM creneaux c
        WHERE c.id = ? AND c.statut = 'libre'
    ");
    $stmt->execute([$creneauId]);
    $creneau = $stmt->fetch();

    if (!$creneau) {
        return ['success' => false, 'error' => 'Ce créneau n\'est plus disponible.'];
    }

    // Vérifier que le patient n'a pas déjà un RDV à la même heure
    $stmt = $pdo->prepare("
        SELECT rv.id FROM rendez_vous rv
        JOIN creneaux c2 ON c2.id = rv.creneau_id
        WHERE rv.patient_id = ?
          AND c2.date_creneau = ?
          AND c2.heure_debut = ?
          AND rv.statut NOT IN ('annule')
    ");
    $stmt->execute([$patientId, $creneau['date_creneau'], $creneau['heure_debut']]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'Vous avez déjà un rendez-vous à cette heure-là.'];
    }

    $pdo->beginTransaction();
    try {
        // Créer le rendez-vous
        $stmt = $pdo->prepare("
            INSERT INTO rendez_vous
                (patient_id, medecin_id, creneau_id, statut, motif, created_at)
            VALUES (?, ?, ?, 'en_attente', ?, NOW())
        ");
        $stmt->execute([$patientId, $creneau['medecin_id'], $creneauId, $motif ?: null]);
        $rdvId = $pdo->lastInsertId();

        // Marquer le créneau comme réservé
        $pdo->prepare("UPDATE creneaux SET statut = 'reserve' WHERE id = ?")
            ->execute([$creneauId]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()];
    }

    // Récupérer infos médecin pour email
    $stmt = $pdo->prepare("
        SELECT u.prenom, u.nom, u.email AS email_medecin,
               up.prenom AS p_prenom, up.nom AS p_nom, up.email AS p_email
        FROM medecins m
        JOIN utilisateurs u  ON u.id  = m.utilisateur_id
        JOIN utilisateurs up ON up.id = ?
        WHERE m.id = ?
    ");
    $stmt->execute([$patientId, $creneau['medecin_id']]);
    $infos = $stmt->fetch();

    // Notification en base
    creerNotification(
        $patientId,
        'confirmation',
        '✅ Rendez-vous confirmé',
        "Votre rendez-vous avec Dr. {$infos['prenom']} {$infos['nom']} le " .
        formatDate($creneau['date_creneau']) . " à " . substr($creneau['heure_debut'], 0, 5) . " a bien été enregistré."
    );

    // Email de confirmation au patient
    $html = emailTemplate(
        '📅 Votre rendez-vous est enregistré',
        "<p style='color:#94a3b8;line-height:1.7;'>
            Bonjour <strong style='color:#fff;'>{$infos['p_prenom']}</strong>,<br><br>
            Votre rendez-vous avec <strong style='color:#fff;'>Dr. {$infos['prenom']} {$infos['nom']}</strong>
            a bien été enregistré.
         </p>
         <div style='background:rgba(14,165,160,0.1);border:1px solid rgba(14,165,160,0.3);
                     border-radius:10px;padding:16px;margin:1rem 0;'>
           <p style='color:#e2e8f0;margin:0;line-height:1.9;'>
             📅 <strong>Date :</strong> " . formatDate($creneau['date_creneau']) . "<br>
             ⏰ <strong>Heure :</strong> " . substr($creneau['heure_debut'], 0, 5) . " – " . substr($creneau['heure_fin'], 0, 5) . "<br>
             " . ($motif ? "📝 <strong>Motif :</strong> $motif" : '') . "
           </p>
         </div>
         <p style='font-size:0.82rem;color:#64748b;'>
           Vous recevrez un rappel 24h avant votre rendez-vous.
         </p>",
        BASE_URL . '/dashboard-patient.html',
        'Voir mes rendez-vous →'
    );
    sendEmail($infos['p_email'], $infos['p_prenom'], '📅 MedRDV – Confirmation de rendez-vous', $html);

    return [
        'success' => true,
        'rdv_id'  => $rdvId,
        'message' => "✅ Rendez-vous pris avec Dr. {$infos['prenom']} {$infos['nom']} le " .
                     formatDate($creneau['date_creneau']) . " à " . substr($creneau['heure_debut'], 0, 5) . ".",
    ];
}

// ─────────────────────────────────────────────────────────────
//  MES RENDEZ-VOUS
// ─────────────────────────────────────────────────────────────
function getMesRendezVous(): array {
    $pdo       = getDB();
    $patientId = $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT
            rv.id, rv.statut, rv.motif, rv.confirmation_patient,
            rv.created_at,
            c.date_creneau, c.heure_debut, c.heure_fin,
            u.prenom AS medecin_prenom, u.nom AS medecin_nom,
            m.specialite, m.localisation
        FROM rendez_vous rv
        JOIN creneaux    c  ON c.id  = rv.creneau_id
        JOIN medecins    m  ON m.id  = rv.medecin_id
        JOIN utilisateurs u ON u.id  = m.utilisateur_id
        WHERE rv.patient_id = ?
        ORDER BY c.date_creneau DESC, c.heure_debut DESC
    ");
    $stmt->execute([$patientId]);

    return [
        'success' => true,
        'rdvs'    => $stmt->fetchAll(),
    ];
}

// ─────────────────────────────────────────────────────────────
//  ANNULER UN RENDEZ-VOUS
// ─────────────────────────────────────────────────────────────
function annulerRendezVous(int $rdvId): array {
    if (!$rdvId) return ['success' => false, 'error' => 'RDV manquant.'];

    $pdo       = getDB();
    $patientId = $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT rv.*, c.date_creneau, c.heure_debut,
               u.prenom AS med_prenom, u.nom AS med_nom
        FROM rendez_vous rv
        JOIN creneaux c ON c.id = rv.creneau_id
        JOIN medecins m ON m.id = rv.medecin_id
        JOIN utilisateurs u ON u.id = m.utilisateur_id
        WHERE rv.id = ? AND rv.patient_id = ?
    ");
    $stmt->execute([$rdvId, $patientId]);
    $rdv = $stmt->fetch();

    if (!$rdv) return ['success' => false, 'error' => 'Rendez-vous introuvable.'];
    if ($rdv['statut'] === 'annule') return ['success' => false, 'error' => 'Déjà annulé.'];
    if ($rdv['statut'] === 'effectue') return ['success' => false, 'error' => 'Ce rendez-vous est déjà effectué.'];

    // Vérifier qu'on peut encore annuler (pas dans les 2h qui viennent)
    $rdvDateTime = new DateTime($rdv['date_creneau'] . ' ' . $rdv['heure_debut']);
    $now         = new DateTime();
    $diff        = $now->diff($rdvDateTime);
    $heuresAvant = ($diff->days * 24) + $diff->h;

    if ($rdvDateTime < $now) {
        return ['success' => false, 'error' => 'Ce rendez-vous est déjà passé.'];
    }
    if ($heuresAvant < 2) {
        return ['success' => false, 'error' => 'Annulation impossible moins de 2h avant le rendez-vous.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE rendez_vous SET statut = 'annule' WHERE id = ?")
            ->execute([$rdvId]);
        $pdo->prepare("UPDATE creneaux SET statut = 'libre' WHERE id = ?")
            ->execute([$rdv['creneau_id']]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Erreur serveur.'];
    }

    creerNotification(
        $patientId,
        'annulation',
        '❌ Rendez-vous annulé',
        "Votre rendez-vous avec Dr. {$rdv['med_prenom']} {$rdv['med_nom']} du " .
        formatDate($rdv['date_creneau']) . " a été annulé."
    );

    return ['success' => true, 'message' => '✅ Rendez-vous annulé avec succès.'];
}

// ─────────────────────────────────────────────────────────────
//  CONFIRMER UN RENDEZ-VOUS (réponse au rappel email)
// ─────────────────────────────────────────────────────────────
function confirmerRendezVous(int $rdvId): array {
    if (!$rdvId) return ['success' => false, 'error' => 'RDV manquant.'];

    $pdo       = getDB();
    $patientId = $_SESSION['user_id'];

    $stmt = $pdo->prepare("SELECT id, statut FROM rendez_vous WHERE id = ? AND patient_id = ?");
    $stmt->execute([$rdvId, $patientId]);
    $rdv = $stmt->fetch();

    if (!$rdv) return ['success' => false, 'error' => 'Rendez-vous introuvable.'];

    $pdo->prepare("UPDATE rendez_vous SET confirmation_patient = 'oui' WHERE id = ?")
        ->execute([$rdvId]);

    return ['success' => true, 'message' => '✅ Présence confirmée.'];
}

// ─────────────────────────────────────────────────────────────
//  NOTIFICATIONS
// ─────────────────────────────────────────────────────────────
function getMesNotifications(): array {
    $pdo    = getDB();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT id, type, titre, message, lue, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $notifs = $stmt->fetchAll();

    $nonLues = (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lue = 0")
                        ->execute([$userId]) ? $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$userId} AND lue = 0")->fetchColumn() : 0;

    // Requête propre pour le count
    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lue = 0");
    $stmt2->execute([$userId]);
    $nonLues = (int)$stmt2->fetchColumn();

    return [
        'success'   => true,
        'notifs'    => $notifs,
        'non_lues'  => $nonLues,
    ];
}

function marquerNotificationLue(int $notifId): array {
    if (!$notifId) return ['success' => false, 'error' => 'ID manquant.'];
    $pdo    = getDB();
    $userId = $_SESSION['user_id'];
    $pdo->prepare("UPDATE notifications SET lue = 1 WHERE id = ? AND user_id = ?")
        ->execute([$notifId, $userId]);
    return ['success' => true];
}

function marquerToutesLues(): array {
    $pdo    = getDB();
    $userId = $_SESSION['user_id'];
    $pdo->prepare("UPDATE notifications SET lue = 1 WHERE user_id = ?")
        ->execute([$userId]);
    return ['success' => true];
}

function creerNotification(int $userId, string $type, string $titre, string $message): void {
    $pdo = getDB();
    try {
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, titre, message, lue, envoye_email, created_at)
            VALUES (?, ?, ?, ?, 0, 0, NOW())
        ")->execute([$userId, $type, $titre, $message]);
    } catch (Exception $e) {
        error_log('[MedRDV] creerNotification error: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
//  PROFIL PATIENT
// ─────────────────────────────────────────────────────────────
function getProfilPatient(): array {
    $pdo    = getDB();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT u.id, u.prenom, u.nom, u.email, u.telephone, u.date_naissance, u.genre,
               u.statut, u.created_at,
               d.groupe_sanguin, d.allergies, d.antecedents
        FROM utilisateurs u
        LEFT JOIN dossiers_medicaux d ON d.patient_id = u.id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $profil = $stmt->fetch();

    if (!$profil) return ['success' => false, 'error' => 'Profil introuvable.'];

    // Statistiques rapides
    $stmt2 = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN rv.statut = 'effectue' THEN 1 ELSE 0 END) AS effectues,
            SUM(CASE WHEN rv.statut IN ('en_attente','confirme') AND c.date_creneau >= CURDATE() THEN 1 ELSE 0 END) AS a_venir
        FROM rendez_vous rv
        JOIN creneaux c ON c.id = rv.creneau_id
        WHERE rv.patient_id = ?
    ");
    $stmt2->execute([$userId]);
    $stats = $stmt2->fetch();

    return [
        'success' => true,
        'profil'  => $profil,
        'stats'   => $stats,
    ];
}

// ─────────────────────────────────────────────────────────────
//  HELPER : Formater date FR
// ─────────────────────────────────────────────────────────────
function formatDate(string $date): string {
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    [$y, $m, $d] = explode('-', $date);
    return intval($d) . ' ' . $mois[intval($m)] . ' ' . $y;
}
