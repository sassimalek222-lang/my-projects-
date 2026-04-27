#!/usr/bin/env php
<?php
// ============================================================
// MedRDV – Script CRON : Rappels de rendez-vous
// À exécuter toutes les heures via cron :
//   0 * * * * php /var/www/html/medrdv-php/cron/rappels.php
// ============================================================

define('CLI_MODE', true);

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/auth.php';

$pdo = getDB();

echo "[" . date('Y-m-d H:i:s') . "] Démarrage des rappels RDV...\n";

// ─────────────────────────────────────────────────────────────
//  1. RAPPELS 24H AVANT LE RENDEZ-VOUS
// ─────────────────────────────────────────────────────────────

$stmt = $pdo->query("
    SELECT
        rv.id AS rdv_id,
        rv.patient_id,
        rv.motif,
        c.date_creneau,
        c.heure_debut,
        c.heure_fin,
        u.prenom AS patient_prenom,
        u.nom    AS patient_nom,
        u.email  AS patient_email,
        um.prenom AS med_prenom,
        um.nom    AS med_nom,
        m.specialite,
        m.localisation
    FROM rendez_vous rv
    JOIN creneaux     c  ON c.id  = rv.creneau_id
    JOIN utilisateurs u  ON u.id  = rv.patient_id
    JOIN medecins     m  ON m.id  = rv.medecin_id
    JOIN utilisateurs um ON um.id = m.utilisateur_id
    WHERE rv.statut IN ('en_attente', 'confirme')
      AND rv.email_rappel_envoye = 0
      AND CONCAT(c.date_creneau, ' ', c.heure_debut)
          BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR)
              AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
");

$rdvs = $stmt->fetchAll();
echo "  → " . count($rdvs) . " rappels à envoyer (24h avant)\n";

foreach ($rdvs as $rdv) {
    // Email de rappel au patient
    $dateFormate  = formatDateFr($rdv['date_creneau']);
    $heureFormate = substr($rdv['heure_debut'], 0, 5);
    $heureFin     = substr($rdv['heure_fin'],   0, 5);

    $contenu = "
        <p style='color:#94a3b8;line-height:1.7;'>
            Bonjour <strong style='color:#fff;'>{$rdv['patient_prenom']}</strong>,<br><br>
            Nous vous rappelons votre rendez-vous prévu <strong>demain</strong>.
        </p>
        <div style='background:rgba(14,165,160,0.1);border:1px solid rgba(14,165,160,0.3);
                    border-radius:10px;padding:16px;margin:1rem 0;'>
            <p style='color:#e2e8f0;margin:0;line-height:1.9;'>
                👨‍⚕️ <strong>Médecin :</strong> Dr. {$rdv['med_prenom']} {$rdv['med_nom']}<br>
                🏥 <strong>Spécialité :</strong> {$rdv['specialite']}<br>
                📍 <strong>Lieu :</strong> " . ($rdv['localisation'] ?: 'Non précisé') . "<br>
                📅 <strong>Date :</strong> $dateFormate<br>
                ⏰ <strong>Heure :</strong> $heureFormate – $heureFin
                " . ($rdv['motif'] ? "<br>📝 <strong>Motif :</strong> {$rdv['motif']}" : '') . "
            </p>
        </div>
        <p style='font-size:0.82rem;color:#64748b;'>
            Pensez à vous présenter 5 minutes avant votre rendez-vous.
        </p>";

    $confirmUrl = BASE_URL . '/backend/api-patient.php?action=confirmer_rdv&rdv_id=' . $rdv['rdv_id'];
    $cancelUrl  = BASE_URL . '/dashboard-patient.html';

    $html = emailTemplate(
        '⏰ Rappel – Rendez-vous demain',
        $contenu,
        $confirmUrl,
        '✅ Confirmer ma présence →'
    );

    $sent = sendEmail(
        $rdv['patient_email'],
        $rdv['patient_prenom'] . ' ' . $rdv['patient_nom'],
        "⏰ MedRDV – Rappel : RDV demain à $heureFormate avec Dr. {$rdv['med_nom']}",
        $html
    );

    if ($sent) {
        // Marquer le rappel comme envoyé
        $pdo->prepare("
            UPDATE rendez_vous
            SET email_rappel_envoye = 1, date_rappel_envoye = NOW()
            WHERE id = ?
        ")->execute([$rdv['rdv_id']]);

        // Notification en base
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, titre, message, lue, envoye_email, created_at)
            VALUES (?, 'rappel_rdv', ?, ?, 0, 1, NOW())
        ")->execute([
            $rdv['patient_id'],
            '⏰ Rappel – Rendez-vous demain',
            "N'oubliez pas votre RDV avec Dr. {$rdv['med_prenom']} {$rdv['med_nom']} demain à $heureFormate."
        ]);

        echo "    ✅ Rappel envoyé → {$rdv['patient_email']} (RDV #{$rdv['rdv_id']})\n";
    } else {
        echo "    ❌ Échec envoi → {$rdv['patient_email']} (RDV #{$rdv['rdv_id']})\n";
    }
}

// ─────────────────────────────────────────────────────────────
//  2. MARQUER AUTOMATIQUEMENT LES RDV PASSÉS COMME "EFFECTUÉS"
// ─────────────────────────────────────────────────────────────

$updated = $pdo->exec("
    UPDATE rendez_vous rv
    JOIN creneaux c ON c.id = rv.creneau_id
    SET rv.statut = 'effectue'
    WHERE rv.statut IN ('en_attente', 'confirme')
      AND CONCAT(c.date_creneau, ' ', c.heure_fin) < NOW()
");

echo "  → $updated RDV marqués comme effectués\n";

// ─────────────────────────────────────────────────────────────
//  3. NETTOYAGE DES CRÉNEAUX EXPIRÉS (libres mais passés)
// ─────────────────────────────────────────────────────────────

$cleaned = $pdo->exec("
    UPDATE creneaux
    SET statut = 'annule'
    WHERE statut = 'libre'
      AND CONCAT(date_creneau, ' ', heure_fin) < DATE_SUB(NOW(), INTERVAL 1 DAY)
");

echo "  → $cleaned créneaux expirés nettoyés\n";

echo "[" . date('Y-m-d H:i:s') . "] Terminé.\n\n";

// ─────────────────────────────────────────────────────────────
function formatDateFr(string $date): string {
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    [$y, $m, $d] = explode('-', $date);
    return intval($d) . ' ' . $mois[intval($m)] . ' ' . $y;
}
