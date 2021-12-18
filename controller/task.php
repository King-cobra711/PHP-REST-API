<?php 
require_once("db.php");
require_once("../model/response.php");
require_once("../model/task.php");
require_once("../model/image.php");

    function retrieveTaskImages($dbCon, $taskid, $returned_userid){
        $imageQuery = $dbCon->prepare("SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid FROM tblimages, tbltasks WHERE tbltasks.id = :taskid AND tbltasks.userid = :userid AND tbltasks.id = tblimages.taskid");
        $imageQuery->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $imageQuery->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $imageQuery->execute();

        $rowCount = $imageQuery->rowCount();

        $imageArray = [];

        while($imageRow = $imageQuery->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($imageRow["id"], $imageRow["title"], $imageRow["filename"], $imageRow["mimetype"], $imageRow["taskid"]);

            $imageArray = $image->returnImageAsArray();
        }

        return $imageArray;
    }

try{
$writeDB = DB::connectWriteDB();
$readDB = DB::connectReadDB();
}catch(PDOException $ex){
    // set up for system administrator to view. error_log takes 2 arguments, 1) the message, 2) where the error is displayed. 0 for the second argument displays it in the php error log file.
    error_log("Connection error" . $ex, 0);

    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connection error");
    $response->send();
    exit();

}

// begin auth script
if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access token is missing from the header") : false);
    (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false);
    $response->send();
    exit;
}

$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

try{
    $query = $writeDB->prepare("SELECT userid, accesstokenexpiry, user_active, login_attempts FROM tblsessions, tblusers WHERE tblsessions.userid = tblusers.id AND accesstoken = :accesstoken");
    $query->bindParam(":accesstoken", $accesstoken, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage("Invalid access token");
    $response->send();
    exit;
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_userid = $row["userid"];
    $returned_accesstokenexpiry = $row["accesstokenexpiry"];
    $returned_useractive = $row["user_active"];
    $returned_loginattempts = $row["login_attempts"];

    if($returned_useractive !== "Y"){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account not active");
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

    if(strtotime($returned_accesstokenexpiry) < time()){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Access token expired");
        $response->send();
        exit;
    }
}catch(PDOException $ex){
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Authentication issue - please try again.");
    $response->send();
    exit;
}




// end auth script

if(array_key_exists("taskid", $_GET)){
    $taskid = $_GET['taskid'];
    if($taskid == '' || !is_numeric($taskid)){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Task ID cannot be blank or must be numeric");
    $response->send();
    exit;
    }
    if($_SERVER['REQUEST_METHOD'] === 'GET'){
        
        try{
        $query = $readDB->prepare('SELECT id, title, description,  DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed FROM tbltasks WHERE id = :taskid AND userid = :userid');
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("Task not found");
        $response->send();
        exit;
        }
        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $imageArray = retrieveTaskImages($readDB, $taskid, $returned_userid);
            $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);
            $tasksArray[] = $task->returnTaskAsArray();
        }
        $returnData = [];
        $returnData['rows_returned'] = $rowCount;
        $returnData['tasks'] = $tasksArray;

        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->toCache(true);
        $response->setData($returnData);
        $response->send();
        exit;
    }catch(TaskException $ex){
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage($ex->getMessage());
        $response->send();
        exit;
    }catch(PDOException $ex){
        error_log("Database query error" . $ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Failed to get task");
    $response->send();
    exit;
    }catch(ImageException $ex){
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage($ex->getMessage());
        $response->send();
        exit;
    }
}elseif($_SERVER['REQUEST_METHOD'] === 'DELETE'){
    try{

        $imageSelectQuery = $readDB->prepare("SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid FROM tblimages, tbltasks WHERE tbltasks.id = :taskid AND tbltasks.userid = :userid AND tblimages.taskid = tbltasks.id");
        $imageSelectQuery->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $imageSelectQuery->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $imageSelectQuery->execute();

        while($imageRow = $imageSelectQuery->fetch(PDO::FETCH_ASSOC)){
            $writeDB->beginTransaction();
            $image = new Image($row["id"], $row["title"], $row["filename"], $row["mimetype"], $row["taskid"]);

            $imageId = $image->getID();

            $query = $writeDB->prepare("DELETE tblimages FROM tblimages, tbltasks WHERE tblimages.id = :imageid AND tbltasks.id = :taskid AND tbltasks.userid = :userid AND tblimages.taskid = tbltasks.id");
            $query->bindParam(":imageid", $imageId, PDO::PARAM_INT);
            $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
            $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $image->deleteImageFile();

            $writeDB->commit();
        }

        $query = $writeDB->prepare("DELETE FROM tbltasks WHERE id = :taskid AND userid = :userid");
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if($rowCount === 0){
            $response = new Response();
            $response->setHttpStatusCode(404);
            $response->setSuccess(false);
            $response->addMessage("Unable to delets task. Task not found.");
            $response->send();
            exit;
        }

        $taskImageFolder = "../../../taskimages".$taskid;

        if(is_dir($taskImageFolder)){
            rmdir($taskImageFolder);
        }

        $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Task deleted");
            $response->send();
            exit;
    }catch(PDOException $ex){
        if($writeDB->inTransaction){
            $writeDB->rollBack();
        }
        $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to delete task");
            $response->send();
            exit;
    }catch(ImageException $ex){
        if($writeDB->inTransaction){
            $writeDB->rollBack();
        }
        $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
    }
}elseif($_SERVER['REQUEST_METHOD'] === 'PATCH'){

    try{
        if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Content type header not set to json");
            $response->send();
            exit;
        }

        $rawPatchData = file_get_contents('php://input');
        if(!$jsonData = json_decode($rawPatchData)){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Request body is not valid json");
            $response->send();
            exit;
        }

        $title_updated = false;
        $description_updated = false;
        $deadline_updated = false;
        $completed_updated = false;

        $queryFields = "";
        
        if(isset($jsonData->title)){
            $title_updated = true;
            $queryFields .= "title = :title, ";
        }
        if(isset($jsonData->description)){
            $description_updated = true;
            $queryFields .= "description = :description, ";
        }
        if(isset($jsonData->deadline)){
            $deadline_updated = true;
            $queryFields .= "deadline = STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), ";
        }
        if(isset($jsonData->completed)){
            $completed_updated = true;
            $queryFields .= "completed = :completed, ";
        }

        $queryFields = rtrim($queryFields, ", ");

        if($title_updated === false && $description_updated === false && $deadline_updated === false &&$completed_updated === false){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Could not updated task - No data provided.");
            $response->send();
            exit;
        }

        $query = $writeDB->prepare("SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') as deadline, completed FROM tbltasks WHERE id = :taskid AND userid = :userid");
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            $response = new Response();
            $response->setHttpStatusCode(404);
            $response->setSuccess(false);
            $response->addMessage("No task found to update");
            $response->send();
            exit;
        }

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
        }

        $queryString = "UPDATE tbltasks SET ".$queryFields." WHERE id = :taskid AND userid = :userid";
        $query = $writeDB->prepare($queryString);

        if($title_updated === true){
            $task->setTitle($jsonData->title);
            $up_title = $task->getTitle();
            $query->bindParam(":title", $up_title, PDO::PARAM_STR);
        }
        if($description_updated === true){
            $task->setDescription($jsonData->description);
            $up_description = $task->getDescription();
            $query->bindParam(":description", $up_description, PDO::PARAM_STR);
        }
        if($deadline_updated === true){
            $task->setDeadline($jsonData->deadline);
            $up_deadline = $task->getDeadline();
            $query->bindParam(":deadline", $up_deadline, PDO::PARAM_STR);
        }
        if($completed_updated === true){
            $task->setCompleted($jsonData->completed);
            $up_completed = $task->getCompleted();
            $query->bindParam(":completed", $up_completed, PDO::PARAM_STR);
        }

        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Task could not update");
            $response->send();
            exit;
        }

        $query = $writeDB->prepare("SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') as deadline, completed FROM tbltasks WHERE id = :taksid AND userid = :userid");
        $query->bindParam(":taksid", $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            $response = new Response();
            $response->setHttpStatusCode(404);
            $response->setSuccess(false);
            $response->addMessage("No task found after update");
            $response->send();
            exit;
        }

        $taskArray = [];

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $imageArray = retrieveTaskImages($writeDB, $taskid, $returned_userid);
            $task = new Task($row["id"], $row["title"], $row["description"], $row["deadline"], $row["completed"], $imageArray);
            
            $taskArray[] = $task->returnTaskAsArray();
        }

        $returnData = [];
        $returnData['rows_returned'] = $rowCount;
        $returnData['tasks'] = $taskArray;

        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->addMessage("Task updated");
        $response->setData($returnData);
        $response->send();
        exit;


    }
    catch(TaskException $ex){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage($ex->getMessage());
        $response->send();
        exit;
    }catch(PDOException $ex){
        error_log("Database query error" . $ex, 0);
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("Failed to update task - check sent data for errors" . $ex);
        $response->send();
        exit;
    }catch(ImageException $ex){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage($ex->getMessage());
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
}elseif(array_key_exists("completed", $_GET)){
    $completed = $_GET["completed"];
    if($completed !== "Y" && $completed !== "N"){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Completed filter must be Y or N");
        $response->send();
        exit;
    }

    if($_SERVER['REQUEST_METHOD'] === 'GET'){
        try{
            $query = $readDB->prepare("SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline, completed FROM tbltasks WHERE completed = :completed AND userid = :userid");
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $tasksArray = [];
            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $imageArray = retrieveTaskImages($readDB, $row["id"], $returned_userid);
                $task = new Task($row["id"], $row["title"], $row["description"], $row["deadline"], $row["completed"], $imageArray);

                $taskArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
                $returnData['rows_returned'] = $rowCount;
                $returnData['tasks'] = $taskArray;

                $response = new Response();
                $response->setHttpStatusCode(200);
                $response->setSuccess(true);
                $response->toCache(true);
                $response->setData($returnData);
                $response->send();
                exit;
        }catch(TaskException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }catch(PDOException $ex){
            error_log("Database query error" . $ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get task");
            $response->send();
            exit;
        }catch(ImageException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
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
}elseif(array_key_exists("page", $_GET)){
    if($_SERVER['REQUEST_METHOD'] === 'GET'){
        $page = $_GET['page'];
        if($page == '' || !is_numeric($page)){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Page number cannot be blank and must be numeric");
        $response->send();
        exit;
        }

        $limitPerPage = 6;

        try{
            $query = $readDB->prepare("SELECT count(id) AS totalNumberOfTasks FROM tbltasks WHERE userid = :userid");
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $row = $query->fetch(PDO::FETCH_ASSOC);
            $tasksCount = intval($row['totalNumberOfTasks']);

            $numOfPages = ceil($tasksCount/$limitPerPage);

            if($numOfPages == 0){
                $numOfPages = 1;
            }

            if($page > $numOfPages || $page == 0){
            $response = new Response();
            $response->setHttpStatusCode(404);
            $response->setSuccess(false);
            $response->addMessage("Page not found");
            $response->send();
            exit;
            }

            $offSet = ($page == 1 ? 0 : ($limitPerPage*($page-1)));

            $query = $readDB->prepare("SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline, completed FROM tbltasks WHERE userid = :userid LIMIT :pageLimit offset :offset");
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':pageLimit', $limitPerPage
            , PDO::PARAM_INT);
            $query->bindParam(':offset', $$offSet
            , PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $tasksArray = [];

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $imageArray = retrieveTaskImages($readDB, $row["id"], $returned_userid);
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);

                $taskArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
                $returnData['rows_returned'] = $rowCount;
                $returnData['total_rows'] = $tasksCount;
                $returnData['total_pages'] = $numOfPages;

                ($page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false);
                ($page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false);
                $returnData['tasks'] = $taskArray;

                $response = new Response();
                $response->setHttpStatusCode(200);
                $response->setSuccess(true);
                $response->toCache(true);
                $response->setData($returnData);
                $response->send();
                exit;
        }catch(PDOException $ex){
        error_log("Database query error - " .$ex, 0);
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("Failed to get tasks");
        $response->send();
        exit;
        }catch(TaskException $ex){
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage($ex->getMessage());
        $response->send();
        exit;
        }catch(ImageException $ex){
            $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage($ex->getMessage());
        $response->send();
        exit;
        }
    }else{
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request Method Not Allowed");
    $response->send();
    exit;
}
}elseif(empty($_GET)){
    if($_SERVER["REQUEST_METHOD"] === "GET"){
        try{
            $query = $readDB->prepare("SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline, completed FROM tbltasks WHERE userid = :userid");
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = [];

            // tried to create a new task for each row
            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $imageArray = retrieveTaskImages($readDB, $row["id"], $returned_userid);
                $task = new Task($row["id"], $row["title"], $row["description"], $row["deadline"], $row["completed"], $imageArray);
                
                $taskArray[] = $task->returnTaskAsArray();
            };
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
        }catch(PDOException $ex){
            error_log("Database query error - " .$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Error retrieving tasks");
            $response->send();
            exit;
        }catch(TaskException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }catch(ImageException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
    }elseif($_SERVER["REQUEST_METHOD"] === "POST"){
    try{
        if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Content type header is not set to json");
            $response->send();
            exit;
        }

        $rowPOSTData = file_get_contents('php://input');

        if(!$jsonData = json_decode($rowPOSTData)){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Request body is not valid json");
            $response->send();
            exit;
        }

        // Error for manditory fields
        if(!isset($jsonData->title) || !isset($jsonData->completed)){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            (!isset($jsonData->title) ? $response->addMessage("Title field must be provided") : false);
            (!isset($jsonData->completed) ? $response->addMessage("Completed field must be provided") : false);
            $response->send();
            exit;
        }

        $newTask = new Task(null, $jsonData->title, (isset($jsonData->description) ? $jsonData->description : null), (isset($jsonData->deadline) ? $jsonData->deadline : null), $jsonData->completed );

        $title = $newTask->getTitle();
        $description = $newTask->getDescription();
        $deadline = $newTask->getDeadline();
        $completed = $newTask->getCompleted();

        $query = $writeDB->prepare("INSERT INTO tbltasks (title, description, deadline, completed, userid)  VALUES (:title, :description, STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), :completed, :userid)");
        $query->bindParam(":title", $title, PDO::PARAM_STR);
        $query->bindParam(":description", $description, PDO::PARAM_STR);
        $query->bindParam(":deadline", $deadline, PDO::PARAM_STR);
        $query->bindParam(":completed", $completed, PDO::PARAM_STR);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to insert data to database");
            $response->send();
            exit;
        }

        $lastTaskId = $writeDB->lastInsertId();
        $query = $writeDB->prepare("SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline, completed FROM tbltasks WHERE id = :taskid AND userid = :userid");
        $query->bindParam(":taskid", $lastTaskId, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Could not retrieve last inserted ID");
            $response->send();
            exit;
        }

        $taskArray = [];


        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $task = new Task($row['id'],$row['title'] , $row['description'], $row['deadline'], $row['completed']);

            $taskArray[]= $task->returnTaskAsArray();
        }

        $returnData = [];
        $returnData["rows_returned"] = $rowCount;
        $returnData["Tasks"] = $taskArray;

        $response = new Response();
        $response->setHttpStatusCode(201);
        $response->setSuccess(true);
        $response->addMessage("Task created");
        $response->setData($returnData);
        $response->send();
        exit;


    }catch(TaskException $ex){
        $response = new Response();
        $response-> setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage($ex->getMessage());
        $response->send();
    }catch(PDOException $ex){
        error_log("Database query error" . $ex, 0);
        $response = new Response();
        $response-> setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("Failed to insert task into database - check submitted data for errors");
        $response->send();
    }
    }else{
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit;
    }
}else{
    $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("Endpoint Not found");
        $response->send();
        exit;
}

?>