<?php

function is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if ($remote === $server && $remote !== '') {
        return true;
    }
    if (in_array($remote, $trusted, true)) {
        return true;
    }
    return false;
}

if (!is_trusted_requester()) {
    require __DIR__ . "/../login/lib.php";
    //all company members are allowed
    /*
    if (
        !array_any($allowedUsers, function ($email) {
            return $email == $_SESSION['user']['email'];
        })
    ) {
        require __DIR__ . "/../login/403.php";
        die();
    }
    */
}