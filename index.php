<?php

require './Mind.php';

$conf = array(
    'db'=>[
        'drive'     =>  'mysql', // mysql, sqlite
        'host'      =>  'localhost',
        'dbname'    =>  'mydb', // mydb, app/migration/mydb.sqlite
        'username'  =>  'root',
        'password'  =>  'root',
        'charset'   =>  'utf8mb4'
    ]
);

$Mind = new Mind($conf);

$Mind->route('/', 'app/views/upload');
