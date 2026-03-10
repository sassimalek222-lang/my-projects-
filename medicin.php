<?php
$conn = new mysqli("localhost","root","","medrdv");

$nom = $_POST['nom'];
$prenom = $_POST['prenom'];
$email = $_POST['email'];
$pass = $_POST['mot_de_passe'];
$specialite = $_POST['specialite'];
$localisation = $_POST['localisation'];
$telephone = $_POST['telephone'];

$sql = "INSERT INTO medecins(nom,prenom,email,mot_de_passe,specialite,localisation,telephone)
VALUES ('$nom','$prenom','$email','$pass','$specialite','$localisation','$telephone')";

$conn->query($sql);

echo "Médecin ajouté avec succès";
?>