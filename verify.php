<?php

$conn = new mysqli("localhost","root","","medrdv");

$token = $_GET['token'];

$conn->query("UPDATE patients SET verified=1 WHERE token='$token'");

echo "Compte activé";
echo "<br><a href='login.html'>Login</a>";

?>