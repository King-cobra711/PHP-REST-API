<?php
require_once("db.php");
require_once("../model/response.php");

try{
    $writeDB = DB::connectWriteDB();

}catch(PDOException $ex){
    error_log("Connection Error: ".$ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Databse connection error");
    $response->send();
    exit;
}

if($_SERVER['REQUEST_METHOD'] !== "POST"){
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit;
}

if($_SERVER["CONTENT_TYPE"] !== "application/json"){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Content type header not set to json");
    $response->send();
    exit;
}

$rowPostData = file_get_contents('php://input');

// If $rowPostData is valid json it is stored in the $jsonData variable
if(!$jsonData = json_decode($rowPostData)){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Request body is not valid json");
    $response->send();
    exit;
}

if(!isset($jsonData->fullname) ||!isset($jsonData->username) ||!isset($jsonData->password)){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    (!isset($jsonData->fullname) ? $response->addMessage("Full name not supplied") : false);
    (!isset($jsonData->username) ? $response->addMessage("Username not supplied") : false);
    (!isset($jsonData->password) ? $response->addMessage("Password not supplied") : false);
    $response->send();
    exit;
}

if(strlen($jsonData->fullname) < 1 || strlen($jsonData->fullname) > 255 || strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    (strlen($jsonData->fullname) < 1 ? $response->addMessage("Full name can not be blank") : false);
    (strlen($jsonData->fullname) > 255 ? $response->addMessage("Full name can not be greater than 255 characters") : false);
    (strlen($jsonData->username) < 1 ? $response->addMessage("Username can not be blank") : false);
    (strlen($jsonData->username) > 255 ? $response->addMessage("Username can not be greater than 255 characters") : false);
    (strlen($jsonData->password) < 1 ? $response->addMessage("Password can not be blank") : false);
    (strlen($jsonData->password) > 255 ? $response->addMessage("Password can not be greater than 255 characters") : false);
    $response->send();
    exit;
}

$fullname = trim($jsonData->fullname);
$username = trim($jsonData->username);
$password = $jsonData->password;

try{
    $query = $writeDB->prepare("SELECT id FROM tblusers WHERE username = :username");
    $query->bindParam(":username", $username, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount !== 0){
        $response = new Response();
        $response->setHttpStatusCode(409);
        $response->setSuccess(false);
        $response->addMessage("Username already in use");
        $response->send();
        exit;
    }
    
    // PASSWORD_DEFAULT could be changed in the future
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $query = $writeDB->prepare("INSERT INTO tblusers (fullname, username, password) VALUES (:fullname, :username, :password)");
    $query->bindParam(":fullname", $fullname, PDO::PARAM_STR);
    $query->bindParam(":username", $username, PDO::PARAM_STR);
    $query->bindParam(":password", $hashedPassword, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue creatinf a user account");
        $response->send();
        exit;
    }

    $lastUserID = $writeDB->lastInsertId();
    $returnData = [];
    $returnData["user_id"] = $lastUserID;
    $returnData["Fullname"] = $fullname;
    $returnData["Username"] = $username;

    $response = new Response();
    $response->setHttpStatusCode(201);
    $response->setSuccess(true);
    $response->addMessage("User created");
    $response->setData($returnData);
    $response->send();
    exit;

}catch(PDOException $ex){
    error_log("Database query error ".$ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("There was an issue creating a user account. Please try again." . $ex);
    $response->send();
    exit;
}

?>