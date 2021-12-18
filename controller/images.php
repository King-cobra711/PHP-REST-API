<?php
require_once("db.php");
require_once("../model/response.php");
require_once("../model/image.php");

function sendResponse($statusCode, $succsess, $message = null, $toCache = false, $data = null){
$response = new Response();
$response->setHttpStatusCode($statusCode);
$response->setSuccess($succsess);
if($message !== null){
    $response->addMessage($message);
}
if($toCache !== null){
    $response->toCache($toCache);
}
if($data !== null){
    $response->setData($data);
}
$response->send();
exit;
}

function uploadImageRoute($readDB, $writeDB, $taskid, $returned_userid){
    try{
        
        if(!isset($_SERVER["CONTENT_TYPE"]) || strpos($_SERVER["CONTENT_TYPE"], "multipart/form-data; boundary") === false){
            sendResponse(400, false, "Content type not set to multipart/form-data; boundary");
        }
        $query = $readDB->prepare("SELECT id from tbltasks WHERE id = :taskid AND userid = :userid");
        $query->bindParam("taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam("userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            sendResponse(404, false, "Task not found");
        }

        if(!isset($_POST["attributes"])){
            sendResponse(404, false, "Attributes missing from body request");
        }

        if(!$jsonImageAttribute = json_decode($_POST["attributes"])){
            sendResponse(404, false, "Attributes field is not valid json");
        }

        if(!isset($jsonImageAttribute->title) || !isset($jsonImageAttribute->filename) || $jsonImageAttribute->title == "" || $jsonImageAttribute->filename == ""){
            sendResponse(400, false, "Title and Filename fields are manditory");
        }

        if(strpos($jsonImageAttribute->filename, ".") > 0){
            sendResponse(400, false, "Filename must not contain a file extention");
        }

        if(!isset($_FILES["imagefile"]) || $_FILES["imagefile"]["error"] !== 0){
            sendResponse(500, false, "Image file upload unsuccessful - make sure you selected a file");
        }

        $imageFileDetails = getimagesize($_FILES["imagefile"]["tmp_name"]);

        if(isset($_FILES["imagefile"]["size"]) && $_FILES["imagefile"]["size"] > 524288){
            sendResponse(400, false, "File must be under 5MB");
        }

        $allowedImageFileTypes = ["image/jpeg", "image/gif", "image/png"];

        if(!in_array($imageFileDetails["mime"], $allowedImageFileTypes)){
            sendResponse(400, false, "File type not allowed");
        }

        $fileExtention = "";
        switch ($imageFileDetails["mime"]){
            case "image/jpeg";
            $fileExtention = ".jpg";
            break;
            case "image/gif";
            $fileExtention = ".gif";
            break;
            case "image/png";
            $fileExtention = ".png";
            break;
            default:
            break;
        }

        if($fileExtention == ""){
            sendResponse(400, false, "No valid file extention found for mimetype");
        }

        $image = new Image(null, $jsonImageAttribute->title, $jsonImageAttribute->filename.$fileExtention, $imageFileDetails["mime"], $taskid);

        $title = $image->getTitle();
        $newFilename = $image->getFilename();
        $mimetype = $image->getMimetype();

        $query = $readDB->prepare("SELECT tblimages.id FROM tblimages, tbltasks WHERE tblimages.taskid = tbltasks.id AND tbltasks.id = :taskid AND tbltasks.userid = :userid AND tblimages.filename = :filename");
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->bindParam(":filename", $newFilename, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount !== 0){
            sendResponse(409, false, "A file with that filename already exists for this task - Try a different filename.");
        }

        $writeDB->beginTransaction();
        $query = $writeDB->prepare("INSERT INTO tblimages (title, filename, mimetype, taskid) VALUES (:title, :filename, :mimetype, :taskid)");
        $query->bindParam(":title", $title, PDO::PARAM_STR);
        $query->bindParam(":filename", $newFilename, PDO::PARAM_STR);
        $query->bindParam(":mimetype", $mimetype, PDO::PARAM_STR);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            if($writeDB->inTransaction()){
            $writeDB->rollBack();
        }
            sendResponse(500, false, "Failed to upload image");
        }
        $lastImageID = $writeDB->lastInsertId();

        $query = $writeDB->prepare("SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid FROM tblimages, tbltasks WHERE tblimages.id = :id AND tbltasks.id = :taskid AND tbltasks.userid = :userid AND tblimages.taskid = tbltasks.id");
        $query->bindParam(":id", $lastImageID, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            if($writeDB->inTransaction()){
            $writeDB->rollBack();
        }
        sendResponse(500, false, "Failed to retrieve image attributes after upload. - Try uploading image again.");
        }

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row["id"], $row["title"], $row["filename"], $row["mimetype"], $row["taskid"]);
            $imageArray[] = $image->returnImageAsArray();
        }

        $image->saveImageFile($_FILES['imagefile']['tmp_name']);

        $writeDB->commit();

        sendResponse(200, true, "Image uploaded successfully", false, $imageArray);


    }catch(PDOException $ex){
        error_log("Database Query error: ".$ex, 0);
        if($writeDB->inTransaction()){
            $writeDB->rollBack();
        }
        sendResponse(500, false, "Failed to upload the image");
    }catch(ImageException $ex){
        if($writeDB->inTransaction()){
            $writeDB->rollBack();
        }
        sendResponse(500, false, $ex->getMessage());
    }
}

function getImgAttributesRoute($readDB, $taskid, $imageid, $returned_userid){
    try{
        $query = $readDB->prepare("SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid FROM tblimages, tbltasks WHERE tblimages.id = :imageid AND tbltasks.id = :taskid AND tbltasks.userid = :userid AND tblimages.taskid = tbltasks.id");
        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            sendResponse(404, false, "Image not found");
        }

        $imagesArray = [];

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row["id"], $row["title"], $row["filename"], $row["mimetype"], $row["taskid"]);
            $imagesArray = $image->returnImageAsArray();
        }

        sendResponse(200, true, null, true, $imagesArray);

    }catch(ImageException$ex){
        sendResponse(500, false, "Error: ", $ex->getMessage());
    }catch(PDOException $ex){
        error_log("Database query error - ". $ex);
        sendResponse(500, false, "Failed to get attributes");
    }
}

function getImageRoute($readDB, $taskid, $imageid, $returned_userid){
    try{
        $query = $readDB->prepare("SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid FROM tblimages, tbltasks WHERE tblimages.id = :imageid AND tbltasks.id = :taskid AND tbltasks.userid = :userid AND tblimages.taskid = tbltasks.id");
        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            sendResponse(404, false, "Image not found");
        }

        $image = null;

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row["id"], $row["title"], $row["filename"], $row["mimetype"], $row["taskid"]);
        }

        if($image == null){
            sendResponse(404, false, "Image not found");
        }

        $image->returnImageFile();

    }catch(ImageException $ex){
        sendResponse(500, false, $ex->getMessage());
    }catch(PDOException $ex){
        error_log("Database query error: " .$ex, 0);
        sendResponse(500, false, "error getting image");
    }
}

function updateImageAttributesRoute($writeDB, $taskid, $imageid, $returned_userid){
    try{
        if($_SERVER["CONTENT_TYPE"] !== "application/json"){
            sendResponse(400, false, "Content type not set to json");
        }
        $rowPatchData = file_get_contents("php://input");
        if(!$jsonData = json_decode($rowPatchData)){
            sendResponse(400, false, "Request body is not valid json");
        }

        $title_updated = false;
        $filename_updated = false;

        $queryFields = "";

        if(isset($jsonData->title)){
            $title_updated = true;
            $queryFields .= "tblimages.title = :title, ";
        }
        if(isset($jsonData->filename)){
            if(strpos($jsonData->filename, ".") !== false){
                sendResponse(400, false, "Filename cannot contain any dots or file extentions");
            }
            $filename_updated = true;
            $queryFields .= "tblimages.filename = :filename, ";
        }
        $queryFields = rtrim($queryFields, ", ");

        if($title_updated === false && $filename_updated === false){
            sendResponse(400, false, "No image file fields provided");
        }

        $writeDB->beginTransaction();
        $query = $writeDB->prepare("SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid FROM tblimages, tbltasks WHERE tblimages.id = :imageid AND tblimages.taskid = :taskid AND tblimages.taskid = tbltasks.id AND tbltasks.userid = :userid");
        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            if($writeDB->inTransaction()){
                $writeDB->rollBack();
            }
            sendResponse(404, false, "No image found to update");
        }
        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row["id"], $row["title"], $row["filename"], $row["mimetype"], $row["taskid"]);
        }

        $queryString = "UPDATE tblimages INNER JOIN tbltasks ON tblimages.taskid = tbltasks.id SET ".$queryFields." WHERE tblimages.id = :imageid AND tblimages.taskid = tbltasks.id AND tblimages.taskid = :taskid AND tbltasks.userid = :userid";

        $query = $writeDB->prepare($queryString);
        
        if($title_updated === true){
            $image->setTitle($jsonData->title);
            $up_title = $image->getTitle();
            $query->bindParam(":title", $up_title, PDO::PARAM_STR);
        }
        if($filename_updated === true){
            $origional_filename = $image->getFilename();

            $image->setFilename($jsonData->filename.".".$image->getFileExtention());
            $up_filename = $image->getFilename();
            $query->bindParam(":filename", $up_filename, PDO::PARAM_STR);
        }

        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);

    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
        if($writeDB->inTransaction()){
            $writeDB->rollBack();
        }
        sendResponse(400, false, "Image attributes not updated - the given values may be the same as the stored values");
    }

    $query = $writeDB->prepare("SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid FROM tblimages, tbltasks WHERE tblimages.id = :imageid AND tbltasks.id = :taskid AND tbltasks.id = tblimages.taskid AND tbltasks.userid = :userid");

    $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
    $query->bindParam(":taskid", $taskid,PDO::PARAM_INT);
    $query->bindParam(":userid",$returned_userid, PDO::PARAM_INT);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
        if($writeDB->inTransaction()){
            $writeDB->rollBack();
        }
        sendResponse(404, false, "No image found");
    }

    $imagesArray = [];


    while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row["id"], $row["title"], $row["filename"], $row["mimetype"], $row["taskid"]);

            $imageArray[] = $image->returnImageAsArray();
        }
        if($filename_updated === true){
            $image->renameImageFile($origional_filename, $up_filename);
        }
    

    $writeDB->commit();

    sendResponse(200, true, "Image attributes updated", false, $imagesArray);


    }catch(PDOException $ex){
        error_log("Database query error: ".$ex);
        if($writeDB->inTransaction()){
            $writeDB->rollBack();
        }
        sendResponse(500, false, "Failed to update image attributes - check your data for errors.". $ex);
    }catch(ImageException $ex){
        if($writeDB->inTransaction()){
            $writeDB->rollBack();
        }
        sendResponse(400, false, $ex->getMessage());
    }
}

function deleteImageRoute($writeDB, $taskid, $imageid, $returned_userid){
try{
    $writeDB->beginTransaction();

$query = $writeDB->prepare("SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid FROM tblimages, tbltasks WHERE tblimages.id = :imageid AND tbltasks.id = :taskid AND tbltasks.userid = :userid AND tblimages.taskid = tbltasks.id");
$query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
$query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
$query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
$query->execute();


    $rowCount = $query->rowCount();

    if($rowCount === 0){
        $writeDB->rollBack();
        sendResponse(404, false, "Image not found");
    }

    $image = null;

    while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $image = new Image($row["id"], $row["title"], $row["filename"], $row["mimetype"], $row["taskid"]);
    }

    if($image === null){
        $writeDB->rollBack();
        sendResponse(500, false, "Failed to get image");
    }

    $query = $writeDB->prepare("DELETE tblimages FROM tblimages, tbltasks WHERE tblimages.id = :imageid AND tbltasks.id = :taskid AND tblimages.taskid = tbltasks.id AND tbltasks.userid = :userid");
    $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
    $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
    $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
        $writeDB->rollBack();
        sendResponse(404, false, "Image not found");
    }

    $image->deleteImageFile();

    $writeDB->commit();

    sendResponse(200, true, "Image deleted");

}catch(PDOException $ex){
    error_log("Database query error: ".$ex);
    $writeDB->rollBack();
    sendResponse(500, false, "Failed to delete image");
}catch(ImageException $ex){
    $writeDB->rollBack();
    sendResponse(500, false, $ex->getMessage());
}
}

function checkAuthStatusAndReturnUserID($writeDB){
    if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){
        $message = null;

        if(!isset($_SERVER["HTTP_AUTHORIZATION"])){
            $message = "Access token is missing from the header";
        }else{
            if(strlen($_SERVER["HTTP_AUTHORIZATION"]) < 1){
                $message = "Access token cannot be blank";
            }
        }
        
        sendResponse(401, false, $message);
}
$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];
try{
    $query = $writeDB->prepare("SELECT userid, accesstokenexpiry, user_active, login_attempts FROM tblsessions, tblusers WHERE tblsessions.userid = tblusers.id AND accesstoken = :accesstoken");
    $query->bindParam(":accesstoken", $accesstoken, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
    sendResponse(401, false, "Invalid access token");
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_userid = $row["userid"];
    $returned_accesstokenexpiry = $row["accesstokenexpiry"];
    $returned_useractive = $row["user_active"];
    $returned_loginattempts = $row["login_attempts"];

    if($returned_useractive !== "Y"){
        sendResponse(401, false, "User account not active");
    }

    if($returned_loginattempts >= 3){
        sendResponse(401, false, "User account is currently locked out");
    }

    if(strtotime($returned_accesstokenexpiry) < time()){
        sendResponse(401, false, "Access token expired");
    }
    return $returned_userid;
}catch(PDOException $ex){
    sendResponse(500, false, "Authentication issue - please try again.");
}

}

try{
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectReadDB();
}catch(PDOException $ex){
    error_log("Connection error: ".$ex, 0);
    sendResponse(500, false, "Database connection error");
}

$returned_userid = checkAuthStatusAndReturnUserID($writeDB);

//  /tasks/1/images/5/attributes

if(array_key_exists("taskid", $_GET) && array_key_exists("imageid", $_GET) && array_key_exists("attributes", $_GET)){
    $taskid = $_GET["taskid"];
    $imageid = $_GET["imageid"];
    $attributes = $_GET["attributes"];

    if($imageid == "" || !is_numeric($imageid) || $taskid == "" || !is_numeric($taskid)){
        sendResponse(400, false, "Image ID or Task ID cannot be blank and must be nurmeric");
    }

    if($_SERVER["REQUEST_METHOD"] === "GET"){
        getImgAttributesRoute($readDB, $taskid, $imageid, $returned_userid);
    }elseif($_SERVER["REQUEST_METHOD"] === "PATCH"){
        updateImageAttributesRoute($writeDB, $taskid, $imageid, $returned_userid);
    }else{
        sendResponse(405, false, "Request method not allowed");
    }

}elseif(array_key_exists("taskid", $_GET) && array_key_exists("imageid", $_GET)){
    $taskid = $_GET["taskid"];
    $imageid = $_GET["imageid"];

    if($imageid == "" || !is_numeric($imageid) || $taskid == "" || !is_numeric($taskid)){
        sendResponse(400, false, "Image ID or Task ID cannot be blank and must be nurmeric");
    }

    if($_SERVER["REQUEST_METHOD"] === "GET"){
        getImageRoute($readDB, $taskid, $imageid, $returned_userid);
    }elseif($_SERVER["REQUEST_METHOD"] === "DELETE"){
        deleteImageRoute($writeDB, $taskid, $imageid, $returned_userid);
    }else{
        sendResponse(405, false, "Request method not allowed");
    }
}

//  /tasks/taskid/images

elseif(array_key_exists("taskid", $_GET) && !array_key_exists("imageid", $_GET)){
    $taskid = $_GET["taskid"];
    if($taskid == "" || !is_numeric($taskid)){
        sendResponse(400, false, "Task ID cannot be blank and must be nurmeric");
    }
    if($_SERVER["REQUEST_METHOD"] === "POST"){
        uploadImageRoute($readDB, $writeDB, $taskid, $returned_userid);
    }else{
        sendResponse(405, false, "Request method not allowed");
    }
}else{
    sendResponse(404, false, "Endpoint not found");
}

?>