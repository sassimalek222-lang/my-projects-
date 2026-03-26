<?php
$conn = new mysqli("localhost", "root", "", "medrdv");
if ($conn->connect_error) die("Erreur connexion: " . $conn->connect_error);

if (isset($_GET['email']) && isset($_GET['token'])) {
    $email = $_GET['email'];
    $token = $_GET['token'];

    $stmt = $conn->prepare("SELECT id FROM patient WHERE email=? AND token=? AND is_active=0");
    $stmt->bind_param("ss", $email, $token);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE patient SET is_active=1, token=NULL WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        echo "Votre compte est activé ! <a href='login.html'>Se connecter</a>";
    } else {
        echo "Lien invalide ou compte déjà activé.";
    }

} else {
    echo "Paramètres manquants.";
}
$conn->close();
?>