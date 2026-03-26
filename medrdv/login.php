<?php
$conn = new mysqli("localhost","root","","medrdv");
if ($conn->connect_error) die("Erreur connexion: " . $conn->connect_error);

$email = $_POST['email'];
$password = $_POST['password'];

// Vérifier email et compte activé
$stmt = $conn->prepare("SELECT * FROM patient WHERE email=? AND is_active=1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    if (password_verify($password, $user['password'])) {
        echo "Connexion réussie ! Bienvenue ".$user['prenom'];
    } else {
        echo "Mot de passe incorrect";
    }
} else {
    echo "Email non trouvé ou compte non activé";
}
$stmt->close();
$conn->close();
?>