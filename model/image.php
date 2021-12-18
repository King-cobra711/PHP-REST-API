<?php
class ImageException extends Exception {}

class Image {
    private $_id;
    private $_title;
    private $_filename;
    private $_mimetype;
    private $_taskid;
    private $_uploadfolderlocation;

    public function __construct($id, $title, $filename, $mimetype, $taskid){
        $this->setID($id);
        $this->setTitle($title);
        $this->setFilename($filename);
        $this->setmimetype($mimetype);
        $this->setTaskid($taskid);
        $this->_uploadfolderlocation = "../../../taskimages/";
    }


    public function getID(){
        return $this->_id;
    }
    public function getTitle(){
        return $this->_title;
    }
    public function getFilename(){
        return $this->_filename;
    }
    public function getFileExtention(){
        $filenameParts = explode(".", $this->_filename);

        if(!$filenameParts) {
			throw new ImageException("Filename does not contain a file extension");
		}

        $lastArrayElement = count($filenameParts)-1;
        $fileExtention = $filenameParts[$lastArrayElement];
        return $fileExtention;
    }
    public function getMimetype(){
        return $this->_mimetype;
    }
    public function getTaskid(){
        return $this->_taskid;
    }
    public function getUploadFolderLocation(){
        return $this->_uploadfolderlocation;
    }
    public function getImageURl(){
        $httpOrHttps = (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on" ? "https" : "http");
        $host = $_SERVER["HTTP_HOST"];
        $url = "/v1/tasks/".$this->getTaskid()."/images/".$this->getID();
        return $httpOrHttps."://".$host.$url;
    }

    public function returnImageFile(){
        $filePath = $this->getUploadFolderLocation().$this->getTaskid()."/".$this->getFilename();

        if(!file_exists($filePath)){
            throw new ImageException("File not found");
        }

        header("Content-Type: ".$this->getMimetype());
        header("Content-Disposition: inline; filename='".$this->getFilename()."'");

        if(readfile($filePath)){
            http_response_code(404);
            exit;
        }

        exit;
    }


    public function setID($id){
        if(($id !== null) && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)){
            throw new ImageException("Image ID error");
        };
        $this->_id = $id;
    }

    public function setTitle($title){
        if(strlen($title) < 1 || strlen($title) > 255){
            throw new ImageException("Image title error");
        }
        $this->_title = $title;
    }
    public function setFilename($filename){
        if(strlen($filename) < 1 || strlen($filename) > 30 || preg_match("/^[a-zA-Z0-9_-]+(.jpg|.png|.gif)$/", $filename) != 1){
            throw new ImageException("Image filename error - must be between 1 and 30 characters, no spaces and only be .jpg .png .gif ");
        }
        $this->_filename = $filename;
    }
    public function setmimetype($mimetype){
        if(strlen($mimetype) < 1 || strlen($mimetype) > 255){
            throw new ImageException("Image mimetype error");
        }
        $this->_mimetype = $mimetype;
    }
    public function setTaskid($taskid){
        if(($taskid !== null) && (!is_numeric($taskid) || $taskid <= 0 || $taskid > 9223372036854775807 || $this->_taskid !== null)){
            throw new ImageException("Image Taskid error");
        };

        $this->_taskid = $taskid;
    }

    public function deleteImageFile(){
        $filePath = $this->getUploadFolderLocation().$this->getTaskid()."/".$this->getFilename();

        if(file_exists($filePath)){
            if(!unlink($filePath)){
                throw new ImageException("Failed to delete image file");
            }
        }

    }

    public function saveImageFile($tempFileName){
        $uploadedFilePath = $this->getUploadFolderLocation().$this->getTaskid()."/".$this->getFilename();

        if(!is_dir($this->getUploadFolderLocation().$this->getTaskid())){
            if(!mkdir($this->getUploadFolderLocation().$this->getTaskid())){
                throw new ImageException("Failed to creat image upload folder for task");
            }
        }
        if(!file_exists($tempFileName)){
            throw new ImageException("Failed to upload image file");
        }
        if(!move_uploaded_file($tempFileName, $uploadedFilePath)){
            throw new ImageException("Failed to upload image file");
        }

    }

    public function renameImageFile($oldFilename, $newFilename){
        $origionalFilePath = $this->getUploadFolderLocation().$this->getTaskid()."/".$oldFilename;
        $renamedFilePath = $this->getUploadFolderLocation().$this->getTaskid()."/".$newFilename;

        if(!file_exists($origionalFilePath)){
            throw new ImageException("Cannot find image file to rename");
        }

        if(!rename($origionalFilePath, $renamedFilePath)){
            throw new ImageException("Failed to update the filename");
        }

    }

    public function returnImageAsArray(){
        $image = [];
        $image["id"] = $this->getID();
        $image["title"] = $this->gettitle();
        $image["filename"] = $this->getFilename();
        $image["mimetype"] = $this->getMimetype();
        $image["task_id"] = $this->getTaskid();
        $image["image_url"] = $this->getImageURl();

        return $image;
    }

}


?>