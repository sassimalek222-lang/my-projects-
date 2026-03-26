<?php
session_start();

$conn = new mysqli("localhost", "root", "", "medrdv");

if ($conn->connect_error) {
    die("Erreur connexion: " . $conn->connect_error);
}

$email = $_POST['email'];
$password = $_POST['password'];

// vérifier si email موجود
$sql = "SELECT * FROM medecins WHERE email='$email'";
$result = $conn->query($sql);

if ($result->num_rows == 1) {
    $medecin = $result->fetch_assoc();

    // vérifier password
    if (password_verify($password, $medecin['mot_de_passe'])) {

        // session
        $_SESSION['medecin_id'] = $medecin['id'];
        $_SESSION['nom'] = $medecin['nom'];

        echo "Connexion réussie ✅";

        // redirection
        header("Location: dashboard_medecin.php");
        exit();

    } else {
        echo "Mot de passe incorrect ❌";
    }

} else {
    echo "Email introuvable ❌";
}

$conn->close();
?>