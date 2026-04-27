<?php
// ============================================================
// MedRDV – API Médecin (backend/api-medecin.php)
// Gestion des créneaux, RDV, notifications médecin
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

startSession();

// Toutes les routes médecin nécessitent auth
if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'medecin') {
    http_response_code(401);
    echo json_encode(['error' => 'Accès réservé aux médecins.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$data = [];
$raw  = file_get_contents('php://input');
if ($raw) {
    $json = json_decode($raw, true);
    if ($json) $data = $json;
}
if (empty($data)) $data = array_merge($_GET, $_POST);

// ─── Router ──────────────────────────────────────────────────
switch ($action) {

    case 'check_session':
        echo json_encode([
            'logged_in' => true,
            'user_id'   => $_SESSION['user_id'],
            'prenom'    => $_SESSION['user_prenom'],
            'nom'       => $_SESSION['user_nom'],
            'email'     => $_SESSION['user_email'],
        ]);
        break;

    case 'mes_rdv':
        echo json_encode(getMesRdvMedecin($data));
        break;

    case 'rdv_du_jour':
        echo json_encode(getRdvDuJour());
        break;

    case 'confirmer_rdv':
        $rdvId = intval($data['rdv_id'] ?? 0);
        echo json_encode(confirmerRdvParMedecin($rdvId));
        break;

    case 'annuler_rdv':
        $rdvId = intval($data['rdv_id'] ?? 0);
        $motif = trim($data['motif'] ?? '');
        echo json_encode(annulerRdvParMedecin($rdvId, $motif));
        break;

    case 'marquer_effectue':
        $rdvId = intval($data['rdv_id'] ?? 0);
        echo json_encode(marquerRdvEffectue($rdvId));
        break;

    case 'mes_creneaux':
        $date = $data['date'] ?? '';
        echo json_encode(getMesCreneaux($date));
        break;

    case 'ajouter_creneaux':
        echo json_encode(ajouterCreneaux($data));
        break;

    case 'supprimer_creneau':
        $creneauId = intval($data['creneau_id'] ?? 0);
        echo json_encode(supprimerCreneau($creneauId));
        break;

    case 'mes_notifications':
        echo json_encode(getMesNotificationsMedecin());
        break;

    case 'marquer_lu':
        $notifId = intval($data['notif_id'] ?? 0);
        echo json_encode(marquerNotificationLue($notifId));
        break;

    case 'tout_marquer_lu':
        echo json_encode(marquerToutesLues());
        break;

    case 'stats_dashboard':
        echo json_encode(getStatsDashboard());
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Action inconnue: ' . $action]);
}

// ─────────────────────────────────────────────────────────────
function getMedecinId(): int {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT id FROM medecins WHERE utilisateur_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : 0;
}

function getMesRdvMedecin(array $data): array {
    $pdo       = getDB();
    $medecinId = getMedecinId();
    if (!$medecinId) return ['success' => false, 'error' => 'Profil médecin introuvable.'];

    $statut = $data['statut'] ?? '';
    $where  = ['rv.medecin_id = ?'];
    $params = [$medecinId];

    if ($statut) {
        $where[]  = 'rv.statut = ?';
        $params[] = $statut;
    }

    $sql = "
        SELECT
            rv.id, rv.statut, rv.motif, rv.confirmation_patient, rv.created_at,
            c.date_creneau, c.heure_debut, c.heure_fin,
            u.prenom AS patient_prenom, u.nom AS patient_nom,
            u.email AS patient_email, u.telephone AS patient_tel
        FROM rendez_vous rv
        JOIN creneaux     c ON c.id  = rv.creneau_id
        JOIN utilisateurs u ON u.id  = rv.patient_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.date_creneau DESC, c.heure_debut ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return ['success' => true, 'rdvs' => $stmt->fetchAll()];
}

function getRdvDuJour(): array {
    $pdo       = getDB();
    $medecinId = getMedecinId();
    if (!$medecinId) return ['success' => false, 'error' => 'Profil introuvable.'];

    $stmt = $pdo->prepare("
        SELECT
            rv.id, rv.statut, rv.motif, rv.confirmation_patient,
            c.heure_debut, c.heure_fin,
            u.prenom AS patient_prenom, u.nom AS patient_nom, u.telephone AS patient_tel
        FROM rendez_vous rv
        JOIN creneaux     c ON c.id = rv.creneau_id
        JOIN utilisateurs u ON u.id = rv.patient_id
        WHERE rv.medecin_id = ?
          AND c.date_creneau = CURDATE()
          AND rv.statut NOT IN ('annule')
        ORDER BY c.heure_debut ASC
    ");
    $stmt->execute([$medecinId]);

    return ['success' => true, 'rdvs' => $stmt->fetchAll()];
}

function confirmerRdvParMedecin(int $rdvId): array {
    if (!$rdvId) return ['success' => false, 'error' => 'RDV manquant.'];

    $pdo       = getDB();
    $medecinId = getMedecinId();

    $stmt = $pdo->prepare("
        SELECT rv.*, c.date_creneau, c.heure_debut,
               u.prenom, u.nom, u.email, u.id AS patient_uid
        FROM rendez_vous rv
        JOIN creneaux c ON c.id = rv.creneau_id
        JOIN utilisateurs u ON u.id = rv.patient_id
        WHERE rv.id = ? AND rv.medecin_id = ?
    ");
    $stmt->execute([$rdvId, $medecinId]);
    $rdv = $stmt->fetch();

    if (!$rdv) return ['success' => false, 'error' => 'RDV introuvable.'];

    $pdo->prepare("UPDATE rendez_vous SET statut = 'confirme' WHERE id = ?")
        ->execute([$rdvId]);

    // Notifier le patient
    creerNotification(
        $rdv['patient_uid'],
        'confirmation',
        '✅ Rendez-vous confirmé par le médecin',
        "Votre rendez-vous du " . formatDate($rdv['date_creneau']) .
        " à " . substr($rdv['heure_debut'], 0, 5) . " a été confirmé."
    );

    return ['success' => true, 'message' => '✅ Rendez-vous confirmé.'];
}

function annulerRdvParMedecin(int $rdvId, string $motif = ''): array {
    if (!$rdvId) return ['success' => false, 'error' => 'RDV manquant.'];

    $pdo       = getDB();
    $medecinId = getMedecinId();

    $stmt = $pdo->prepare("
        SELECT rv.*, c.date_creneau, c.heure_debut, c.id AS cr_id,
               u.prenom, u.nom, u.email, u.id AS patient_uid,
               um.prenom AS med_prenom, um.nom AS med_nom
        FROM rendez_vous rv
        JOIN creneaux c ON c.id = rv.creneau_id
        JOIN utilisateurs u ON u.id = rv.patient_id
        JOIN medecins m ON m.id = rv.medecin_id
        JOIN utilisateurs um ON um.id = m.utilisateur_id
        WHERE rv.id = ? AND rv.medecin_id = ?
    ");
    $stmt->execute([$rdvId, $medecinId]);
    $rdv = $stmt->fetch();

    if (!$rdv) return ['success' => false, 'error' => 'RDV introuvable.'];
    if ($rdv['statut'] === 'effectue') return ['success' => false, 'error' => 'RDV déjà effectué.'];

    $pdo->prepare("UPDATE rendez_vous SET statut = 'annule' WHERE id = ?")
        ->execute([$rdvId]);
    $pdo->prepare("UPDATE creneaux SET statut = 'libre' WHERE id = ?")
        ->execute([$rdv['cr_id']]);

    // Notifier le patient
    $msgMotif = $motif ? " Motif : $motif" : '';
    creerNotification(
        $rdv['patient_uid'],
        'annulation',
        '❌ Rendez-vous annulé par le médecin',
        "Votre rendez-vous avec Dr. {$rdv['med_prenom']} {$rdv['med_nom']} du " .
        formatDate($rdv['date_creneau']) . " a été annulé.$msgMotif"
    );

    // Email au patient
    $html = emailTemplate(
        '❌ Votre rendez-vous a été annulé',
        "<p style='color:#94a3b8;line-height:1.7;'>
            Bonjour <strong style='color:#fff;'>{$rdv['prenom']}</strong>,<br><br>
            Nous vous informons que votre rendez-vous avec
            <strong>Dr. {$rdv['med_prenom']} {$rdv['med_nom']}</strong>
            prévu le " . formatDate($rdv['date_creneau']) . " à " .
            substr($rdv['heure_debut'], 0, 5) . " a été annulé.
        </p>
        " . ($motif ? "<p style='background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);border-radius:8px;padding:12px;color:#f87171;'>Motif : $motif</p>" : '') . "
        <p style='color:#64748b;font-size:0.82rem;'>Vous pouvez prendre un nouveau rendez-vous via MedRDV.</p>",
        BASE_URL . '/dashboard-patient.html',
        'Prendre un nouveau RDV →'
    );
    sendEmail($rdv['email'], $rdv['prenom'], '❌ MedRDV – Annulation de votre rendez-vous', $html);

    return ['success' => true, 'message' => '✅ Rendez-vous annulé et patient notifié.'];
}

function marquerRdvEffectue(int $rdvId): array {
    if (!$rdvId) return ['success' => false, 'error' => 'RDV manquant.'];

    $pdo       = getDB();
    $medecinId = getMedecinId();

    $stmt = $pdo->prepare("SELECT id, statut FROM rendez_vous WHERE id = ? AND medecin_id = ?");
    $stmt->execute([$rdvId, $medecinId]);
    $rdv = $stmt->fetch();

    if (!$rdv) return ['success' => false, 'error' => 'RDV introuvable.'];

    $pdo->prepare("UPDATE rendez_vous SET statut = 'effectue' WHERE id = ?")
        ->execute([$rdvId]);

    return ['success' => true, 'message' => '✅ Rendez-vous marqué comme effectué.'];
}

function getMesCreneaux(string $date = ''): array {
    $pdo       = getDB();
    $medecinId = getMedecinId();
    if (!$medecinId) return ['success' => false, 'error' => 'Profil introuvable.'];

    if ($date) {
        $stmt = $pdo->prepare("
            SELECT c.id, c.date_creneau, c.heure_debut, c.heure_fin, c.statut
            FROM creneaux c
            WHERE c.medecin_id = ? AND c.date_creneau = ?
            ORDER BY c.heure_debut ASC
        ");
        $stmt->execute([$medecinId, $date]);
    } else {
        $stmt = $pdo->prepare("
            SELECT c.id, c.date_creneau, c.heure_debut, c.heure_fin, c.statut
            FROM creneaux c
            WHERE c.medecin_id = ?
              AND c.date_creneau >= CURDATE()
              AND c.date_creneau <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY c.date_creneau ASC, c.heure_debut ASC
        ");
        $stmt->execute([$medecinId]);
    }

    return ['success' => true, 'creneaux' => $stmt->fetchAll()];
}

function ajouterCreneaux(array $data): array {
    $pdo       = getDB();
    $medecinId = getMedecinId();
    if (!$medecinId) return ['success' => false, 'error' => 'Profil introuvable.'];

    $dateDebut   = $data['date_debut']   ?? '';
    $dateFin     = $data['date_fin']     ?? $dateDebut;
    $heureDebut  = $data['heure_debut']  ?? '';
    $heureFin    = $data['heure_fin']    ?? '';
    $dureeMin    = intval($data['duree'] ?? 20);
    $joursActifs = $data['jours'] ?? [1,2,3,4,5]; // Lundi-Vendredi par défaut

    if (!$dateDebut || !$heureDebut || !$heureFin) {
        return ['success' => false, 'error' => 'Paramètres manquants (date, heure_debut, heure_fin).'];
    }

    $created = 0;
    $skipped = 0;

    $current = new DateTime($dateDebut);
    $end     = new DateTime($dateFin);

    while ($current <= $end) {
        $jourSemaine = (int)$current->format('N'); // 1=Lundi, 7=Dimanche
        if (in_array($jourSemaine, $joursActifs)) {
            // Générer les créneaux de cette journée
            $startTime = new DateTime($current->format('Y-m-d') . ' ' . $heureDebut);
            $endTime   = new DateTime($current->format('Y-m-d') . ' ' . $heureFin);

            while ($startTime < $endTime) {
                $slotEnd = clone $startTime;
                $slotEnd->modify("+{$dureeMin} minutes");
                if ($slotEnd > $endTime) break;

                try {
                    $pdo->prepare("
                        INSERT IGNORE INTO creneaux
                            (medecin_id, date_creneau, heure_debut, heure_fin, statut)
                        VALUES (?, ?, ?, ?, 'libre')
                    ")->execute([
                        $medecinId,
                        $current->format('Y-m-d'),
                        $startTime->format('H:i:s'),
                        $slotEnd->format('H:i:s'),
                    ]);
                    $created++;
                } catch (Exception $e) {
                    $skipped++;
                }

                $startTime = $slotEnd;
            }
        }
        $current->modify('+1 day');
    }

    return [
        'success' => true,
        'message' => "✅ $created créneaux créés" . ($skipped ? ", $skipped ignorés (déjà existants)." : "."),
        'created' => $created,
        'skipped' => $skipped,
    ];
}

function supprimerCreneau(int $creneauId): array {
    if (!$creneauId) return ['success' => false, 'error' => 'Créneau manquant.'];

    $pdo       = getDB();
    $medecinId = getMedecinId();

    $stmt = $pdo->prepare("SELECT id, statut FROM creneaux WHERE id = ? AND medecin_id = ?");
    $stmt->execute([$creneauId, $medecinId]);
    $creneau = $stmt->fetch();

    if (!$creneau) return ['success' => false, 'error' => 'Créneau introuvable.'];
    if ($creneau['statut'] === 'reserve') {
        return ['success' => false, 'error' => 'Impossible de supprimer un créneau réservé. Annulez d\'abord le RDV.'];
    }

    $pdo->prepare("DELETE FROM creneaux WHERE id = ?")->execute([$creneauId]);

    return ['success' => true, 'message' => '✅ Créneau supprimé.'];
}

function getMesNotificationsMedecin(): array {
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

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lue = 0");
    $stmt2->execute([$userId]);
    $nonLues = (int)$stmt2->fetchColumn();

    return ['success' => true, 'notifs' => $notifs, 'non_lues' => $nonLues];
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

function getStatsDashboard(): array {
    $pdo       = getDB();
    $medecinId = getMedecinId();
    if (!$medecinId) return ['success' => false, 'error' => 'Profil introuvable.'];

    $stats = [];

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN rv.statut IN ('en_attente','confirme') AND c.date_creneau >= CURDATE() THEN 1 ELSE 0 END) AS a_venir,
            SUM(CASE WHEN c.date_creneau = CURDATE() THEN 1 ELSE 0 END) AS aujourd_hui,
            SUM(CASE WHEN rv.statut = 'effectue' THEN 1 ELSE 0 END) AS effectues
        FROM rendez_vous rv
        JOIN creneaux c ON c.id = rv.creneau_id
        WHERE rv.medecin_id = ?
    ");
    $stmt->execute([$medecinId]);
    $stats['rdv'] = $stmt->fetch();

    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) AS creneaux_libres
        FROM creneaux
        WHERE medecin_id = ? AND statut = 'libre' AND date_creneau >= CURDATE()
    ");
    $stmt2->execute([$medecinId]);
    $stats['creneaux_libres'] = (int)$stmt2->fetchColumn();

    $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lue = 0");
    $stmt3->execute([$_SESSION['user_id']]);
    $stats['notifs_non_lues'] = (int)$stmt3->fetchColumn();

    return ['success' => true, 'stats' => $stats];
}

function formatDate(string $date): string {
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    [$y, $m, $d] = explode('-', $date);
    return intval($d) . ' ' . $mois[intval($m)] . ' ' . $y;
}
