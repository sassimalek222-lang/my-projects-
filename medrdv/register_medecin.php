<?php
$conn = new mysqli("localhost", "root", "", "medrdv");

if ($conn->connect_error) {
    die("Erreur: " . $conn->connect_error);
}

$nom = $_POST['nom'];
$prenom = $_POST['prenom'];
$email = $_POST['email'];
$password = $_POST['password'];
$specialite = $_POST['specialite'];
$localisation = $_POST['localisation'];
$type_calendrier = $_POST['type_calendrier'];
$telephone = $_POST['telephone'];

$hashed_password = password_hash($password, PASSWORD_BCRYPT);

// vérifier email
$check = $conn->query("SELECT * FROM medecins WHERE email='$email'");
if ($check->num_rows > 0) {
    die("Email déjà utilisé !");
}

$sql = "INSERT INTO medecins 
(nom, prenom, email, mot_de_passe, specialite, localisation)
VALUES 
('$nom', '$prenom', '$email', '$hashed_password', '$specialite', '$localisation')";

if ($conn->query($sql)) {
    echo "Médecin enregistré avec succès ✅";
} else {
    echo "Erreur: " . $conn->error;
}
?>