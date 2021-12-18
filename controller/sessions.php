<?php 
require_once("db.php");
require_once("../model/response.php");

try{

$writeDB = DB::connectWriteDB();

}catch(PDOException $ex){
    error_log("Connection error".$ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connection error");
    $response->send();
    exit;
}

if(array_key_exists("sessionid", $_GET)){
    $sessionid = $_GET["sessionid"];

    if($sessionid === '' || !is_numeric($sessionid)){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        ($sessionid === '' ? $response->addMessage("Session ID cannot be blank") : false);
        (!is_numeric($sessionid) ? $response->addMessage("Session ID must be numeric") : false);
        $response->send();
        exit;
    }

    if(!isset($_SERVER["HTTP_AUTHORIZATION"]) || strlen($_SERVER["HTTP_AUTHORIZATION"]) < 1){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        (!isset($_SERVER["HTTP_AUTHORIZATION"]) ? $response->addMessage("Access token is missing from header") : false);
        (strlen($_SERVER["HTTP_AUTHORIZATION"]) < 1 ? $response->addMessage("Access token cannot be blank") : false);
        $response->send();
        exit;
    }

    $accesstoken = $_SERVER["HTTP_AUTHORIZATION"];

    if($_SERVER["REQUEST_METHOD"] === "DELETE"){
        try{
            $query = $writeDB->prepare("DELETE FROM tblsessions WHERE id = :sessionid AND accesstoken = :accesstoken");
            $query->bindParam(":sessionid", $sessionid, PDO::PARAM_INT);
            $query->bindParam(":accesstoken", $accesstoken, PDO::PARAM_STR);

            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Failed to logout of this session using access token provided");
                $response->send();
                exit;
            }

            $returnData = [];
            $returnData["session_id"] = intval($sessionid);

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Logged out");
            $response->setData($returnData);
            $response->send();
            exit;

        }catch(PDOException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("There was an issue loggin out - please try again.");
            $response->send();
            exit;
        }
    }elseif($_SERVER["REQUEST_METHOD"] === "PATCH"){
        if($_SERVER["CONTENT_TYPE"] !== "application/json"){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Content type header is not application/json");
            $response->send();
            exit;
        }

        $rowPatchData = file_get_contents('php://input');

        if(!$jsonData = json_decode($rowPatchData)){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Request body is not valid json");
            $response->send();
            exit;
        }

        if(!isset($jsonData->refresh_token) || strlen($jsonData->refresh_token) < 1){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            (!isset($jsonData->refresh_token) ? $response->addMessage("Refresh token is missing.") : false);
            (strlen($jsonData->refresh_token) < 1 ? $response->addMessage("Refresh token cannot be blank") : false);
            $response->send();
            exit;
        }

        try{
            $refreshtoken = $jsonData->refresh_token;

            $query = $writeDB->prepare("SELECT tblsessions.id AS sessionid, tblsessions.userid AS userid, accesstoken, refreshtoken, user_active, login_attempts, accesstokenexpiry, refreshtokenexpiry FROM tblsessions, tblusers WHERE tblusers.id = tblsessions.userid AND tblsessions.id = :sessionid AND tblsessions.accesstoken = :accesstoken AND tblsessions.refreshtoken = :refreshtoken");
            $query->bindParam(":sessionid", $sessionid, PDO::PARAM_INT);
            $query->bindParam(":accesstoken", $accesstoken, PDO::PARAM_STR);
            $query->bindParam(":refreshtoken", $refreshtoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Access token or refresh token is incorrect for session id");
                $response->send();
                exit;
            }

            $row = $query->fetch(PDO::FETCH_ASSOC);

            $returned_sessionid = $row["sessionid"];
            $returned_userid = $row["userid"];
            $returned_accesstoken = $row["accesstoken"];
            $returned_refreshtoken = $row["refreshtoken"];
            $returned_useractive = $row["user_active"];
            $returned_loginattempts = $row["login_attempts"];
            $returned_accesstokenexpiry = $row["accesstokenexpiry"];
            $returned_refreshtokenexpiry = $row["refreshtokenexpiry"];

            if($returned_useractive !== "Y"){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account is not active");
                $response->send();
                exit;
            }
            if($returned_loginattempts >= 3){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account is currently locked out");
                $response->send();
                exit;
            }

            // To delete expired rows from the database you would create scheduled scripts that would check the expiry date of the token to the current date and delete if required.
            if(strtotime($returned_refreshtokenexpiry) < time()){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Refresh token has expired - please login again.");
                $response->send();
                exit;
            }

            $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
            $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

            $access_token_expiry_seconds = 1200;
            $refresh_token_expiry_seconds = 1209600;

            $query = $writeDB->prepare("UPDATE tblsessions SET accesstoken = :accesstoken, accesstokenexpiry = DATE_ADD(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), refreshtoken = :refreshtoken, refreshtokenexpiry = DATE_ADD(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND) WHERE id = :sessionid AND userid = :userid AND accesstoken = :returnedaccesstoken AND refreshtoken = :returnedrefreshtoken");
            $query->bindParam(":accesstoken", $accesstoken, PDO::PARAM_STR);
            $query->bindParam(":accesstokenexpiryseconds", $access_token_expiry_seconds, PDO::PARAM_INT);
            $query->bindParam(":refreshtoken", $refreshtoken, PDO::PARAM_STR);
            $query->bindParam(":refreshtokenexpiryseconds", $refresh_token_expiry_seconds, PDO::PARAM_INT);
            $query->bindParam(":sessionid", $returned_sessionid, PDO::PARAM_INT);
            $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
            $query->bindParam(":returnedaccesstoken", $returned_accesstoken, PDO::PARAM_STR);
            $query->bindParam(":returnedrefreshtoken", $returned_refreshtoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token could not be refreshed - please login again.");
                $response->send();
                exit;
            }

            $returnData = [];
            $returnData["session_id"] = $returned_sessionid;
            $returnData["access_token"] = $accesstoken;
            $returnData["access_token_expiry_seconds"] = $access_token_expiry_seconds;
            $returnData["refresh_token"] = $refreshtoken;
            $returnData["refresh_token_expiry_seconds"] = $refresh_token_expiry_seconds;

            $response = new Response();
                $response->setHttpStatusCode(200);
                $response->setSuccess(true);
                $response->addMessage("Token refreshed");
                $response->setData($returnData);
                $response->send();
                exit;

        }catch(PDOException $ex){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("There was an issue refreshing access token - please login again.");
            $response->send();
            exit;
        }

    }else{
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit;
    }

}elseif(empty($_GET)){

    if($_SERVER["REQUEST_METHOD"] !== "POST"){
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit;
    }

    sleep(1);

    if($_SERVER["CONTENT_TYPE"] !== "application/json"){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Content type header not set to json");
        $response->send();
        exit;
    }
    $rowPostData = file_get_contents("php://input");
    if(!$jsonData = json_decode($rowPostData)){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Request body is not valid json");
        $response->send();
        exit;
    }

    if(!isset($jsonData->username) || !isset($jsonData->password)){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        (!isset($jsonData->username) ? $response->addMessage("Username not supplied") : false);
        (!isset($jsonData->password) ? $response->addMessage("Password not supplied") : false);
        $response->send();
        exit;
    }

    if(strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        (strlen($jsonData->username) < 1 ? $response->addMessage("Username must be greater than 0 charcters") : false);
        (strlen($jsonData->username) > 255 ? $response->addMessage("Username cannot be greater than 255 charcters") : false);
        (strlen($jsonData->password) < 1 ? $response->addMessage("Password must be greater than 0 charcters") : false);
        (strlen($jsonData->password) > 255 ? $response->addMessage("Password cannot be greater than 255 charcters") : false);
        $response->send();
        exit;
    }

    try{
        $username = $jsonData->username;
        $password = $jsonData->password;

        $query = $writeDB->prepare("SELECT id, fullname, username, password, user_active, login_attempts FROM tblusers WHERE username = :username");
        $query->bindParam(":username", $username, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0 ){
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("Username or password incorrect");
            $response->send();
            exit;
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);
            $returned_id = $row["id"];
            $returned_fullname = $row["fullname"];
            $returned_username = $row["username"];
            $returned_password = $row["password"];
            $returned_user_active = $row["user_active"];
            $returned_login_attempts = $row["login_attempts"];

            if($returned_user_active !== "Y"){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account not active");
                $response->send();
                exit;
            }
            if($returned_login_attempts >= 3){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account is locked out");
                $response->send();
                exit;
            }

            if(!password_verify($jsonData->password, $returned_password)){
                $query = $writeDB->prepare("UPDATE tblusers SET login_attempts = login_attempts+1 WHERE id = :id");
                $query->bindParam(":id", $returned_id, PDO::PARAM_INT);
                $query->execute();

                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Username or password is incorrect");
                $response->send();
                exit;
            }

            $accessToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24))).time();
            $refreshToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24))).time();

            // expiry time in seconds
            $accessTokenExpirySeconds = 1200;
            // 14 days in seconds
            $refreshTokenExpirySeconds = 1209600;

    }catch(PDOException $ex){
        // not login errors to text file because it may compramise passwords
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue logging in");
        $response->send();
        exit;
    }
    try{
        $writeDB->beginTransaction();

        $query = $writeDB->prepare("UPDATE tblusers SET login_attempts = 0 WHERE id = :id");
        $query->bindParam(":id", $returned_id, PDO::PARAM_INT);
        $query->execute();

        $query = $writeDB->prepare("INSERT INTO tblsessions (userid, accesstoken, accesstokenexpiry, refreshtoken, refreshtokenexpiry) VALUES (:userid, :accesstoken, DATE_ADD(NOW(), INTERVAL :accesstokenexpiry SECOND), :refreshtoken, DATE_ADD(NOW(), INTERVAL :refreshtokenexpiry SECOND))");
        $query->bindParam(":userid", $returned_id, PDO::PARAM_INT);
        $query->bindParam(":accesstoken", $accessToken, PDO::PARAM_STR);
        $query->bindParam(":accesstokenexpiry", $accessTokenExpirySeconds, PDO::PARAM_INT);
        $query->bindParam(":refreshtoken", $refreshToken, PDO::PARAM_STR);
        $query->bindParam(":refreshtokenexpiry", $refreshTokenExpirySeconds, PDO::PARAM_INT);
        $query->execute();

        $lastSessionID = $writeDB->lastInsertId();

        $writeDB->commit();

        $returnData = [];
        $returnData["session_id"] = intval($lastSessionID);
        $returnData["access_token"] = $accessToken;
        $returnData["access_token_expiry_seconds"] = $accessTokenExpirySeconds;
        $returnData["refresh_token"] = $refreshToken;
        $returnData["refresh_token_expiry_seconds"] = $refreshTokenExpirySeconds;

        $response = new Response();
        $response->setHttpStatusCode(201);
        $response->setSuccess(true);
        $response->setData($returnData);
        $response->send();
        exit;


    }catch(PDOException $ex){
        $writeDB->rollBack();
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue logging in - please try again." . $ex);
        $response->send();
        exit;
    }

}else{
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint not found");
    $response->send();
    exit;
}

?>