<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

/* connexion base de données */

$conn = mysqli_connect("localhost","root","","medrdv");

if(!$conn){
    die("Connexion échouée : ".mysqli_connect_error());
}

/* récupération données formulaire */

$nom = $_POST['nom'];
$email = $_POST['email'];
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);

/* insertion patient */

$sql = "INSERT INTO patients(nom,email,password) VALUES('$nom','$email','$password')";

if(mysqli_query($conn,$sql)){

    $mail = new PHPMailer(true);

    try {

        /* configuration SMTP */

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'eyaarfaoui45@gmail.com';
        $mail->Password   = 'tigk ayaf hruw hfnj';   // App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        /* expéditeur */

        $mail->setFrom('eyaarfaoui45@gmail.com', 'MedRDV');

        /* destinataire */

        $mail->addAddress($email, $nom);

        /* contenu email */

        $mail->isHTML(true);
        $mail->Subject = 'Verification de votre compte';
        $mail->Body    = "
        <h2>Bonjour $nom</h2>
        <p>Votre compte a été créé avec succès.</p>
        <p>Bienvenue sur <b>MedRDV</b>.</p>
        ";

        $mail->send();

        echo "Compte créé et email envoyé";

    } catch (Exception $e) {

        echo "Compte créé mais email non envoyé : {$mail->ErrorInfo}";

    }

}else{

    echo "Erreur : ".mysqli_error($conn);

}

?>