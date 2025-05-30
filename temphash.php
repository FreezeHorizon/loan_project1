<?php
$passwordToHash = 'yourchosenadminpassword'; // CHOOSE A STRONG PASSWORD
echo "Password: " . $passwordToHash . "<br>";
echo "Hashed Password: " . password_hash($passwordToHash, PASSWORD_DEFAULT);
?>