<?php
// ============================================================
// MedRDV – API Admin (backend/api-admin.php)
// Endpoints AJAX pour le dashboard administrateur
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/admin.php';

// Vérifier session admin sur toutes les routes sauf check
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== 'check_admin_session') {
    requireAdmin();
}

// Lire body JSON ou form-data
$data = [];
$raw  = file_get_contents('php://input');
if ($raw) {
    $json = json_decode($raw, true);
    if ($json) $data = $json;
}
if (empty($data)) $data = array_merge($_GET, $_POST);

// ─── Router ──────────────────────────────────────────────────
switch ($action) {

    // ── Vérifier session admin ─────────────────────────────────
    case 'check_admin_session':
        startSession();
        if (isAdmin()) {
            echo json_encode([
                'logged_in' => true,
                'prenom'    => $_SESSION['user_prenom'],
                'nom'       => $_SESSION['user_nom'],
            ]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        break;

    // ── Statistiques dashboard ─────────────────────────────────
    case 'get_stats':
        echo json_encode(['success' => true, 'stats' => getAdminStats()]);
        break;

    // ── Médecins en attente ────────────────────────────────────
    case 'get_pending_medecins':
        echo json_encode(['success' => true, 'medecins' => getMedecinsPending()]);
        break;

    // ── Valider médecin ────────────────────────────────────────
    case 'validate_medecin':
        $userId = intval($data['user_id'] ?? 0);
        echo json_encode(validateMedecin($userId));
        break;

    // ── Rejeter médecin ────────────────────────────────────────
    case 'reject_medecin':
        $userId = intval($data['user_id'] ?? 0);
        $raison = trim($data['raison'] ?? '');
        echo json_encode(rejectMedecin($userId, $raison));
        break;

    // ── Liste tous les utilisateurs ────────────────────────────
    case 'get_users':
        $role   = $data['role']   ?? '';
        $statut = $data['statut'] ?? '';
        $page   = intval($data['page'] ?? 1);
        echo json_encode(getAllUsers($role, $statut, $page));
        break;

    // ── Suspendre utilisateur ──────────────────────────────────
    case 'suspend_user':
        $userId = intval($data['user_id'] ?? 0);
        echo json_encode(suspendUser($userId));
        break;

    // ── Réactiver utilisateur ──────────────────────────────────
    case 'activate_user':
        $userId = intval($data['user_id'] ?? 0);
        echo json_encode(activateUser($userId));
        break;

    // ── Supprimer utilisateur ──────────────────────────────────
    case 'delete_user':
        $userId = intval($data['user_id'] ?? 0);
        echo json_encode(deleteUser($userId));
        break;

    // ── Liste rendez-vous ──────────────────────────────────────
    case 'get_rdv':
        $page = intval($data['page'] ?? 1);
        echo json_encode(getAllRdv($page));
        break;

    // ── Logs admin ─────────────────────────────────────────────
    case 'get_logs':
        $limit = intval($data['limit'] ?? 50);
        echo json_encode(['success' => true, 'logs' => getAdminLogs($limit)]);
        break;

    // ── Déconnexion admin ──────────────────────────────────────
    case 'logout':
        logoutUser();
        echo json_encode(['success' => true, 'redirect' => BASE_URL . '/login-admin.html']);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Action inconnue: ' . $action]);
}
