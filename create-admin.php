<?php
require 'backend/config.php';
$pdo = getDB();
$hash = password_hash('Eya@2026', PASSWORD_BCRYPT);
$pdo->exec("DELETE FROM utilisateurs WHERE email = 'eyaarfaoui45@gmail.com'");
$stmt = $pdo->prepare("INSERT INTO utilisateurs (role,prenom,nom,email,mot_de_passe,email_verifie,statut,created_at) VALUES ('admin','Eya','Arfaoui','eyaarfaoui45@gmail.com',?,1,'actif',NOW())");
$stmt->execute([$hash]);
echo 'OK ADMIN CREE';
?>