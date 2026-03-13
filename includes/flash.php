<?php
// simple session-based flash messaging

function setFlash($message, $type = 'success') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_message'] = ['msg' => $message, 'type' => $type];
}

function getFlash() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash_message'])) {
        $f = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $f;
    }
    return null;
}

?>