<?php
session_start();
session_destroy();
header('Location: ../../modules/auth/login.php');
exit;
