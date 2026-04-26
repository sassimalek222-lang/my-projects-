<?php
// ============================================================
// MedRDV – API Auth (backend/api-auth.php)
// Reçoit les requêtes AJAX du frontend
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$data   = [];

// Accepter JSON ou form-data
$raw = file_get_contents('php://input');
if ($raw) {
    $json = json_decode($raw, true);
    if ($json) $data = $json;
}
if (empty($data)) $data = $_POST;

// ─── Router ──────────────────────────────────────────────────
switch ($action) {

    // ── Inscription patient ────────────────────────────────────
    case 'register_patient':
        echo json_encode(registerPatient($data));
        break;

    // ── Inscription médecin ────────────────────────────────────
    case 'register_medecin':
        echo json_encode(registerMedecin($data));
        break;

    // ── Connexion ──────────────────────────────────────────────
    case 'login':
        echo json_encode(loginUser($data['email'] ?? '', $data['password'] ?? ''));
        break;

    // ── Déconnexion ────────────────────────────────────────────
    case 'logout':
        logoutUser();
        echo json_encode(['success' => true, 'redirect' => BASE_URL . '/index.html']);
        break;

    // ── Vérification email ─────────────────────────────────────
    case 'verify':
        $token = $_GET['token'] ?? $data['token'] ?? '';
        echo json_encode(verifyEmail($token));
        break;

    // ── Mot de passe oublié ────────────────────────────────────
    case 'forgot_password':
        echo json_encode(forgotPassword($data['email'] ?? ''));
        break;

    // ── Réinitialisation mot de passe ──────────────────────────
    case 'reset_password':
        echo json_encode(resetPassword(
            $data['token']            ?? '',
            $data['password']         ?? '',
            $data['password_confirm'] ?? ''
        ));
        break;

    // ── Vérifier session ───────────────────────────────────────
    case 'check_session':
        startSession();
        if (isLoggedIn()) {
            echo json_encode([
                'logged_in' => true,
                'user_id'   => $_SESSION['user_id'],
                'role'      => $_SESSION['user_role'],
                'prenom'    => $_SESSION['user_prenom'],
                'nom'       => $_SESSION['user_nom'],
            ]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Action inconnue: ' . $action]);
}
