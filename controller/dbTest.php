<?php 
require_once('db.php');
require_once('../model/response.php');

try {
    $writeDB = DB::connectWriteDB();
    // $readDB = DB::connectReadDB();
}
catch(PDOException $ex){
    $response = new Response();
    $response->setSuccess(false);
    $response->setHttpStatusCode(500);
    $response->addMessage("Database Connection Error");
    $response->send();
    exit;
}


?>