<?php

return [
    'host' => getenv('FERRETERIA_DB_HOST') ?: '127.0.0.1',
    'database' => getenv('FERRETERIA_DB_NAME') ?: 'ferreteria',
    'username' => getenv('FERRETERIA_DB_USER') ?: 'root',
    'password' => getenv('FERRETERIA_DB_PASSWORD') ?: '',
];