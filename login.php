<?php

$conn = new mysqli("localhost","root","","medrdv");

$email = $_POST['email'];
$password = $_POST['password'];

$sql = "SELECT * FROM patients WHERE email='$email'";
$result = $conn->query($sql);

if($result->num_rows > 0){

$user = $result->fetch_assoc();

if($user['verified'] == 0){
echo "Veuillez vérifier votre email";
exit();
}

if(password_verify($password,$user['password'])){
echo "Connexion réussie";                        
}else{
echo "Mot de passe incorrect";
}

}else{
echo "Email non trouvé";
}

?>
