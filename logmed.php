<?php

$conn = new mysqli("localhost","root","","medrdv");

if ($conn->connect_error) {
die("Erreur connexion: " . $conn->connect_error);
}

$nom = $_POST['nom'];
$prenom = $_POST['prenom'];
$email = $_POST['email'];
$password = password_hash($_POST['password'], PASSWORD_BCRYPT);
$specialite = $_POST['specialite'];
$localisation = $_POST['localisation'];

$sql = "INSERT INTO medecins(nom,prenom,email,mot_de_passe,specialite,localisation)
VALUES('$nom','$prenom','$email','$password','$specialite','$localisation')";

if ($conn->query($sql) === TRUE) {
echo "Médecin ajouté avec succès";
} else {
echo "Erreur : " . $conn->error;
}

$conn->close();

?>