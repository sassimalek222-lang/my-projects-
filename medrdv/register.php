<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// 1️⃣ Connexion DB
$conn = new mysqli("localhost", "root", "", "medrdv");
if ($conn->connect_error) die("Erreur de connexion: " . $conn->connect_error);

// 2️⃣ Récupérer données formulaire
$nom = $_POST['nom'];
$prenom = $_POST['prenom'];
$email = $_POST['email'];
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);
$telephone = $_POST['telephone'];

// 3️⃣ Vérifier si email existe déjà
$stmt = $conn->prepare("SELECT id FROM patient WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) die("Cet email existe déjà !");

// 4️⃣ Générer token
$token = bin2hex(random_bytes(25));

// 5️⃣ Insérer patient dans DB (inactive)
$stmt = $conn->prepare("INSERT INTO patient (nom, prenom, email, password, telephone, token, is_active) VALUES (?, ?, ?, ?, ?, ?, 0)");
$stmt->bind_param("ssssss", $nom, $prenom, $email, $password, $telephone, $token);
if (!$stmt->execute()) die("Erreur SQL: " . $stmt->error);

// 6️⃣ Envoyer email de vérification avec PHPMailer
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'eyaarfaoui45@gmail.com';          // ton Gmail
    $mail->Password   = 'dftl fxlz xetd mjct';      // App Password Gmail
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('eyaarfaoui45@gmail.com', 'MedRdv');
    $mail->addAddress($email, $prenom);

    $mail->isHTML(true);
    $mail->Subject = 'Vérification de votre compte';
    $mail->Body    = "Bonjour $prenom,<br>Cliquez sur ce lien pour activer votre compte:<br>";
    $mail->Body   .= "<a href='http://localhost/medrdv/verify.php?email=$email&token=$token'>Activer le compte</a>";

    $mail->send();
    echo "Inscription réussie ! Vérifiez votre email pour activer votre compte.";

} catch (Exception $e) {
    echo "Erreur lors de l'envoi de l'email: {$mail->ErrorInfo}";
}

$stmt->close();
$conn->close();
?>