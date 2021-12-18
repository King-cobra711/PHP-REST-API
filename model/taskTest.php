<?php 
require_once('task.php');

try{
    $task = new Task(1, "Task 1 title", "Task 1 description", "07/11/2021 00:00", "N");
    header('Content-type: application/json;Charset=UTF-8');
    echo json_encode($task->returnTaskAsArray());
}catch(TaskException $ex){
    echo "Error ". $ex->getMessage();
}

?>