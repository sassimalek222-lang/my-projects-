-- ============================================================
--  MedRDV – Base de Données Complète
--  Version : 1.0 | Date : 02/02/2026
--  Technologies : MySQL 8.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS medrdv CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE medrdv;

-- ============================================================
-- 1. UTILISATEURS (Patients + Médecins + Admins)
-- ============================================================
CREATE TABLE utilisateurs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role          ENUM('patient','medecin','admin') NOT NULL,
    prenom        VARCHAR(100) NOT NULL,
    nom           VARCHAR(100) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    mot_de_passe  VARCHAR(255) NOT NULL COMMENT 'Hashé avec bcrypt',
    telephone     VARCHAR(20),
    date_naissance DATE,
    genre         ENUM('homme','femme','autre'),
    email_verifie TINYINT(1) DEFAULT 0,
    token_email   VARCHAR(255) COMMENT 'Token de validation email',
    statut        ENUM('actif','suspendu','en_attente') DEFAULT 'en_attente',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB;

-- ============================================================
-- 2. PROFILS MÉDECINS
-- ============================================================
CREATE TABLE medecins (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id    INT UNSIGNED NOT NULL UNIQUE,
    specialite        VARCHAR(150) NOT NULL,
    localisation      VARCHAR(200),
    biographie        TEXT,
    annees_experience INT DEFAULT 0,
    numero_ordre      VARCHAR(50) UNIQUE COMMENT 'Numéro ordre médical',
    type_creneau      ENUM('15','20','30') DEFAULT '20' COMMENT 'Durée créneau en minutes',
    score_confiance   DECIMAL(4,2) DEFAULT 0.00 COMMENT 'Score calculé automatiquement',
    nb_patients_total INT DEFAULT 0,
    taux_reussite     DECIMAL(5,2) DEFAULT 0.00,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    INDEX idx_specialite (specialite),
    INDEX idx_localisation (localisation)
) ENGINE=InnoDB;

-- ============================================================
-- 3. DISPONIBILITÉS MÉDECIN (Plages horaires)
-- ============================================================
CREATE TABLE disponibilites (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medecin_id      INT UNSIGNED NOT NULL,
    jour_semaine    TINYINT NOT NULL COMMENT '1=Lundi ... 7=Dimanche',
    heure_debut     TIME NOT NULL,
    heure_fin       TIME NOT NULL,
    actif           TINYINT(1) DEFAULT 1,
    FOREIGN KEY (medecin_id) REFERENCES medecins(id) ON DELETE CASCADE,
    INDEX idx_medecin_jour (medecin_id, jour_semaine)
) ENGINE=InnoDB;

-- ============================================================
-- 4. CRÉNEAUX GÉNÉRÉS AUTOMATIQUEMENT
-- ============================================================
CREATE TABLE creneaux (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medecin_id      INT UNSIGNED NOT NULL,
    date_creneau    DATE NOT NULL,
    heure_debut     TIME NOT NULL,
    heure_fin       TIME NOT NULL,
    statut          ENUM('libre','reserve','annule') DEFAULT 'libre',
    FOREIGN KEY (medecin_id) REFERENCES medecins(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_medecin_creneau (medecin_id, date_creneau, heure_debut),
    INDEX idx_medecin_date (medecin_id, date_creneau)
) ENGINE=InnoDB;

-- ============================================================
-- 5. RENDEZ-VOUS
-- ============================================================
CREATE TABLE rendez_vous (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id            INT UNSIGNED NOT NULL,
    medecin_id            INT UNSIGNED NOT NULL,
    creneau_id            INT UNSIGNED NOT NULL UNIQUE,
    statut                ENUM('en_attente','confirme','annule','effectue') DEFAULT 'en_attente',
    confirmation_patient  ENUM('oui','non','sans_reponse') DEFAULT 'sans_reponse',
    email_rappel_envoye   TINYINT(1) DEFAULT 0,
    date_rappel_envoye    DATETIME,
    motif                 TEXT,
    notes_patient         TEXT,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES utilisateurs(id),
    FOREIGN KEY (medecin_id) REFERENCES medecins(id),
    FOREIGN KEY (creneau_id) REFERENCES creneaux(id),
    INDEX idx_patient (patient_id),
    INDEX idx_medecin (medecin_id),
    INDEX idx_statut (statut),
    -- Règle: un patient ne peut pas avoir 2 RDV au même créneau
    CONSTRAINT chk_statut CHECK (statut IN ('en_attente','confirme','annule','effectue'))
) ENGINE=InnoDB;

-- ============================================================
-- 6. FILE D'ATTENTE VIRTUELLE
-- ============================================================
CREATE TABLE file_attente (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id      INT UNSIGNED NOT NULL,
    medecin_id      INT UNSIGNED NOT NULL,
    date_souhaitee  DATE,
    priorite        INT DEFAULT 0,
    statut          ENUM('en_attente','notifie','confirme','expire') DEFAULT 'en_attente',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES utilisateurs(id),
    FOREIGN KEY (medecin_id) REFERENCES medecins(id),
    INDEX idx_medecin_date (medecin_id, date_souhaitee)
) ENGINE=InnoDB;

-- ============================================================
-- 7. DOSSIERS MÉDICAUX
-- ============================================================
CREATE TABLE dossiers_medicaux (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id          INT UNSIGNED NOT NULL UNIQUE,
    groupe_sanguin      VARCHAR(5),
    allergies           TEXT,
    antecedents         TEXT,
    maladies_chroniques TEXT,
    traitements         TEXT,
    notes_generales     TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 8. FICHES DE CONSULTATION
-- ============================================================
CREATE TABLE fiches_consultation (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rendez_vous_id  INT UNSIGNED NOT NULL UNIQUE,
    medecin_id      INT UNSIGNED NOT NULL,
    patient_id      INT UNSIGNED NOT NULL,
    diagnostic      TEXT,
    traitement      TEXT,
    ordonnance      TEXT,
    notes_medecin   TEXT,
    date_consultation DATETIME NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rendez_vous_id) REFERENCES rendez_vous(id),
    FOREIGN KEY (medecin_id) REFERENCES medecins(id),
    FOREIGN KEY (patient_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB;

-- ============================================================
-- 9. PERMISSIONS ACCÈS DOSSIER PATIENT
-- Contrôle l'accès progressif au dossier selon le cahier des charges
-- ============================================================
CREATE TABLE acces_dossier (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medecin_id      INT UNSIGNED NOT NULL,
    patient_id      INT UNSIGNED NOT NULL,
    niveau_acces    ENUM('aucun','basique','complet') DEFAULT 'aucun',
    -- basique = après confirmation RDV
    -- complet = après consultation effectuée
    rendez_vous_id  INT UNSIGNED,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (medecin_id) REFERENCES medecins(id),
    FOREIGN KEY (patient_id) REFERENCES utilisateurs(id),
    FOREIGN KEY (rendez_vous_id) REFERENCES rendez_vous(id),
    UNIQUE KEY uniq_acces (medecin_id, patient_id)
) ENGINE=InnoDB;

-- ============================================================
-- 10. HISTORIQUE RELATION PATIENT-MÉDECIN
-- ============================================================
CREATE TABLE historique_relation (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id          INT UNSIGNED NOT NULL,
    medecin_id          INT UNSIGNED NOT NULL,
    nb_consultations    INT DEFAULT 0,
    derniere_visite     DATE,
    patient_regulier    TINYINT(1) DEFAULT 0 COMMENT '1 si > 3 consultations',
    priorite            INT DEFAULT 0 COMMENT 'Score de priorité pour les RDV',
    FOREIGN KEY (patient_id) REFERENCES utilisateurs(id),
    FOREIGN KEY (medecin_id) REFERENCES medecins(id),
    UNIQUE KEY uniq_relation (patient_id, medecin_id)
) ENGINE=InnoDB;

-- ============================================================
-- 11. CONFIDENTIALITÉ PATIENT (contrôle d'accès données)
-- ============================================================
CREATE TABLE confidentialite_patient (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id                  INT UNSIGNED NOT NULL UNIQUE,
    masquer_historique          TINYINT(1) DEFAULT 0,
    masquer_allergies           TINYINT(1) DEFAULT 0,
    masquer_antecedents         TINYINT(1) DEFAULT 0,
    mode_anonyme_forum          TINYINT(1) DEFAULT 0,
    FOREIGN KEY (patient_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 12. NOTIFICATIONS
-- ============================================================
CREATE TABLE notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    type        ENUM('rappel_rdv','confirmation','annulation','file_attente','retard','email_verification','autre'),
    titre       VARCHAR(255) NOT NULL,
    message     TEXT NOT NULL,
    lue         TINYINT(1) DEFAULT 0,
    envoye_email TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    INDEX idx_user_lue (user_id, lue)
) ENGINE=InnoDB;

-- ============================================================
-- 13. RETARDS MÉDECIN
-- ============================================================
CREATE TABLE retards_medecin (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medecin_id      INT UNSIGNED NOT NULL,
    date_retard     DATE NOT NULL,
    duree_minutes   INT NOT NULL,
    patients_notifies TINYINT(1) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medecin_id) REFERENCES medecins(id)
) ENGINE=InnoDB;

-- ============================================================
-- 14. RÉSEAU SOCIAL – PUBLICATIONS
-- ============================================================
CREATE TABLE publications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auteur_id   INT UNSIGNED NOT NULL,
    contenu     TEXT NOT NULL,
    image_url   VARCHAR(500),
    video_url   VARCHAR(500),
    anonyme     TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (auteur_id) REFERENCES utilisateurs(id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- 15. COMMENTAIRES PUBLICATIONS
-- ============================================================
CREATE TABLE commentaires (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    publication_id  INT UNSIGNED NOT NULL,
    auteur_id       INT UNSIGNED NOT NULL,
    contenu         TEXT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    FOREIGN KEY (auteur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB;

-- ============================================================
-- 16. FORUM – QUESTIONS
-- ============================================================
CREATE TABLE forum_questions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id  INT UNSIGNED NOT NULL,
    titre       VARCHAR(500) NOT NULL,
    contenu     TEXT NOT NULL,
    anonyme     TINYINT(1) DEFAULT 0,
    statut      ENUM('ouverte','resolue','fermee') DEFAULT 'ouverte',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES utilisateurs(id),
    INDEX idx_statut (statut)
) ENGINE=InnoDB;

-- ============================================================
-- 17. FORUM – RÉPONSES MÉDECINS
-- ============================================================
CREATE TABLE forum_reponses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    medecin_id  INT UNSIGNED NOT NULL,
    contenu     TEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES forum_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (medecin_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB;

-- ============================================================
-- 18. AVIS PATIENTS SUR MÉDECINS
-- ============================================================
CREATE TABLE avis (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id  INT UNSIGNED NOT NULL,
    medecin_id  INT UNSIGNED NOT NULL,
    note        TINYINT NOT NULL CHECK (note BETWEEN 1 AND 5),
    commentaire TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES utilisateurs(id),
    FOREIGN KEY (medecin_id) REFERENCES medecins(id),
    UNIQUE KEY uniq_avis (patient_id, medecin_id),
    INDEX idx_medecin (medecin_id)
) ENGINE=InnoDB;

-- ============================================================
-- 19. LOGS D'ADMINISTRATION
-- ============================================================
CREATE TABLE admin_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED NOT NULL,
    action      VARCHAR(255) NOT NULL,
    cible_table VARCHAR(100),
    cible_id    INT UNSIGNED,
    details     JSON,
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES utilisateurs(id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- TRIGGERS
-- ============================================================

DELIMITER $$

-- Trigger: mise à jour automatique du statut créneau lors d'une réservation
CREATE TRIGGER after_rdv_insert
AFTER INSERT ON rendez_vous
FOR EACH ROW
BEGIN
    UPDATE creneaux SET statut = 'reserve' WHERE id = NEW.creneau_id;
END$$

-- Trigger: libération du créneau si RDV annulé
CREATE TRIGGER after_rdv_update
AFTER UPDATE ON rendez_vous
FOR EACH ROW
BEGIN
    IF NEW.statut = 'annule' THEN
        UPDATE creneaux SET statut = 'libre' WHERE id = NEW.creneau_id;
    END IF;
    -- Mise à jour accès dossier si RDV confirmé
    IF NEW.confirmation_patient = 'oui' AND OLD.confirmation_patient != 'oui' THEN
        INSERT INTO acces_dossier (medecin_id, patient_id, niveau_acces, rendez_vous_id)
        VALUES (NEW.medecin_id, NEW.patient_id, 'basique', NEW.id)
        ON DUPLICATE KEY UPDATE niveau_acces = 'basique', rendez_vous_id = NEW.id;
    END IF;
    -- Accès complet après consultation effectuée
    IF NEW.statut = 'effectue' AND OLD.statut != 'effectue' THEN
        INSERT INTO acces_dossier (medecin_id, patient_id, niveau_acces, rendez_vous_id)
        VALUES (NEW.medecin_id, NEW.patient_id, 'complet', NEW.id)
        ON DUPLICATE KEY UPDATE niveau_acces = 'complet';
    END IF;
END$$

-- Trigger: mise à jour historique relation patient-médecin après consultation
CREATE TRIGGER after_consultation_insert
AFTER INSERT ON fiches_consultation
FOR EACH ROW
BEGIN
    INSERT INTO historique_relation (patient_id, medecin_id, nb_consultations, derniere_visite)
    VALUES (NEW.patient_id, NEW.medecin_id, 1, DATE(NEW.date_consultation))
    ON DUPLICATE KEY UPDATE
        nb_consultations = nb_consultations + 1,
        derniere_visite = DATE(NEW.date_consultation),
        patient_regulier = IF(nb_consultations + 1 >= 3, 1, 0),
        priorite = nb_consultations + 1;
END$$

-- Trigger: mise à jour score médecin après avis
CREATE TRIGGER after_avis_insert
AFTER INSERT ON avis
FOR EACH ROW
BEGIN
    UPDATE medecins SET
        score_confiance = (SELECT AVG(note) FROM avis WHERE medecin_id = NEW.medecin_id)
    WHERE id = NEW.medecin_id;
END$$

DELIMITER ;

-- ============================================================
-- DONNÉES DE TEST
-- ============================================================

-- Admin
INSERT INTO utilisateurs (role, prenom, nom, email, mot_de_passe, email_verifie, statut)
VALUES ('admin', 'Malek', 'Sassi', 'admin@medrdv.tn', '$2y$10$examplehash', 1, 'actif');

-- Médecin exemple
INSERT INTO utilisateurs (role, prenom, nom, email, mot_de_passe, telephone, email_verifie, statut)
VALUES ('medecin', 'Sami', 'Ben Ali', 'dr.benali@medrdv.tn', '$2y$10$examplehash', '+216 71 000 000', 1, 'actif');

INSERT INTO medecins (utilisateur_id, specialite, localisation, type_creneau, numero_ordre)
VALUES (2, 'Cardiologie', 'Tunis, Lac 1', '20', 'TN-MED-12345');

-- Patient exemple
INSERT INTO utilisateurs (role, prenom, nom, email, mot_de_passe, telephone, email_verifie, statut)
VALUES ('patient', 'Mohamed', 'Ben Salah', 'patient@email.tn', '$2y$10$examplehash', '+216 22 000 000', 1, 'actif');

INSERT INTO dossiers_medicaux (patient_id, groupe_sanguin) VALUES (3, 'A+');
INSERT INTO confidentialite_patient (patient_id) VALUES (3);

-- ============================================================
-- VUES UTILES
-- ============================================================

-- Vue: liste médecins avec infos complètes et score
CREATE VIEW vue_medecins AS
SELECT
    m.id AS medecin_id,
    u.prenom, u.nom,
    CONCAT(u.prenom, ' ', u.nom) AS nom_complet,
    m.specialite, m.localisation, m.type_creneau,
    m.score_confiance, m.nb_patients_total,
    m.annees_experience,
    COUNT(DISTINCT a.id) AS nb_avis,
    ROUND(AVG(a.note), 1) AS note_moyenne
FROM medecins m
JOIN utilisateurs u ON u.id = m.utilisateur_id
LEFT JOIN avis a ON a.medecin_id = m.id
WHERE u.statut = 'actif'
GROUP BY m.id, u.prenom, u.nom, m.specialite, m.localisation,
         m.type_creneau, m.score_confiance, m.nb_patients_total, m.annees_experience;

-- Vue: rendez-vous avec infos patient et médecin
CREATE VIEW vue_rendez_vous AS
SELECT
    rv.id, rv.statut, rv.confirmation_patient,
    c.date_creneau, c.heure_debut, c.heure_fin,
    up.prenom AS patient_prenom, up.nom AS patient_nom, up.email AS patient_email,
    um.prenom AS medecin_prenom, um.nom AS medecin_nom,
    m.specialite
FROM rendez_vous rv
JOIN creneaux c ON c.id = rv.creneau_id
JOIN utilisateurs up ON up.id = rv.patient_id
JOIN medecins m ON m.id = rv.medecin_id
JOIN utilisateurs um ON um.id = m.utilisateur_id;
