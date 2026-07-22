<?php 
    function getToken() {
    return [
        'client_id'     => getenv('FIRMAPERU_CLIENT_ID') ?: '',
        'client_secret' => getenv('FIRMAPERU_CLIENT_SECRET') ?: ''
    ];
}



