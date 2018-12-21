<?php
$executionStartTime = microtime(true);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once (__DIR__.'/../box-jwt-php/bootstrap/autoload.php');
require_once (__DIR__.'/../box-jwt-php/helpers/helpers.php');
require_once('../dbconfig.php');

use Box\Auth\BoxJWTAuth;
use Box\BoxClient;
use Box\Config\BoxConstants;
use Box\Models\Request\BoxFileRequest;

$boxJwt     = new BoxJWTAuth();
$boxConfig  = $boxJwt->getBoxConfig();
$adminToken = $boxJwt->adminToken();
$boxClient  = new BoxClient($boxConfig, $adminToken->access_token);
$boxFolderId = '';

$filePath = '../files/';

//if run in cli
if(php_sapi_name()==="cli") {
    $newline = "\n";
    $tab = "\t";
} else {
    $newline = "<br>";
    $tab = "&nbsp;&nbsp;&nbsp;&nbsp;";
}
$newline_double = $newline.$newline;

date_default_timezone_set('America/Los_Angeles');
echo "Start Time: ".date('M j, Y g:i:s a').$newline_double;

echo "Begin migration...".$newline_double; 

$dbConn = mysqli_connect(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
if (!$dbConn) {
    printf("Can't connect to localhost. Error: %s\n", mysqli_connect_error());
    exit();
}

//update database tables first
$updatedTables = false;
if ($result = mysqli_query($dbConn,"SHOW TABLES LIKE 'report_history'")) {
    if($result->num_rows == 1) {
        $updatedTables = true;
    }
}

//if they have not yet been updated
if($updatedTables == false) {
    $dbschema = file_get_contents('2_up.sql');
    echo "Updating database tables...".$newline_double;

    if (mysqli_multi_query($dbConn,$dbschema)) {
        echo 'SUCCESS'.$newline_double;
    }
    else{
        echo 'FAIL'.$newline_double;
        exit();
    }
}

mysqli_close($dbConn);
$dbConn = mysqli_connect(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

if(!file_exists($filePath)){
    echo "File directory does not exist.".$newline.' Exiting script.';
    exit();
}

echo "Getting relevant files...".$newline_double;
//Get all files from files folder
$files = array_diff(scandir($filePath), array('.', '..', '.DS_Store'));

$updateDatabase = array();
$failedFiles = array();
$dataCaseQuery = '';
$consultantCaseQuery = '';
$filesList = implode (", ", $files);

echo "Searching for files to upload...".$newline_double;

if (!$dbConn) {
    printf("Can't connect to localhost. Error: %s\n", mysqli_connect_error());
    exit();
}

$queryData = "SELECT 
                data_id AS id, 
                collector_id AS collector_id, 
                data_file_name AS origFileName, 
                data_file_type AS fileType,
                data_file AS currentFileName 
            FROM data WHERE data_file in (".$filesList.")
            AND data_box_file_id IS NULL";

$queryConsultant = "SELECT 
                    consultant_id AS id, 
                    collector_id AS collector_id, 
                    consultant_file_name AS origFileName, 
                    consultant_file_type AS fileType,
                    consultant_consent_form AS currentFileName 
                FROM consultant 
                WHERE consultant_consent_form in (".$filesList.")
                AND consultant_box_file_id IS NULL";

echo $queryData.$newline_double;
echo $queryConsultant.$newline_double;

//Data Table Query
$dataResult = mysqli_query($dbConn,$queryData);

$dataRows = array();
if ($dataResult) {
    while ($row = mysqli_fetch_assoc($dataResult)) {
      $dataRows[] = $row;
    }
}

//Consultant Table Query
$consultantResult = mysqli_query($dbConn,$queryConsultant);
$consultantRows = array();
if ($consultantResult) {
    while ($row = mysqli_fetch_assoc($consultantResult)) {
      $consultantRows[] = $row;
    }
}

if(empty($dataRows) && empty($consultantRows)){
    echo "No new files to upload.".$newline.' Exiting script.';
    exit();
}

echo "Uploading files...".$newline;
//Data Table
$updateDataTable = array();
$dataBoxIdQuery = '';
$dataBoxNameQuery = '';
foreach ($dataRows as $row) {

    //upload each file to box 
    try {
        //name and id to array to update database
        $boxinfo = uploadFileToBox($row);
        $updateDataTable[] = $boxinfo;
        $dataBoxIdQuery .= " WHEN data_id = ".$row['id']." THEN ".$boxinfo['box_id'];
        $dataBoxNameQuery .= " WHEN data_id = ".$row['id']." THEN '".addslashes($boxinfo['box_name'])."'";
    } catch(Exception $e) {
        $failedFiles[] = $row['currentFileName'];
        echo $e.$newline;
    }
}

//Consultant Table
$updateConsultantTable = array();
$consultantBoxIdQuery = '';
$consultantBoxNameQuery = '';
foreach ($consultantRows as $row) {
    //upload each file to box 
    try {
        //name and id to array to update database
        $boxinfo = uploadFileToBox($row);
        $updateConsultantTable[] = $boxinfo;
        $consultantBoxIdQuery .= " WHEN consultant_id = ".$row['id']." THEN ".$boxinfo['box_id'];
        $consultantBoxNameQuery .= " WHEN consultant_id = ".$row['id']." THEN '".addslashes($boxinfo['box_name'])."'";
    } catch(Exception $e) {
        $failedFiles[] = $row['currentFileName'];
        echo $e.$newline;
    }
}

echo $newline_double."Updating file information in database...".$newline_double;

// Set autocommit to off
mysqli_autocommit($dbConn,FALSE);

$updated = true;

if (count($updateDataTable) > 0) {
    
    $dataQuery = "UPDATE data 
                    SET data_box_file_id = 
                        CASE $dataBoxIdQuery ELSE data_box_file_id END
                    , data_file =
                        CASE $dataBoxNameQuery ELSE data_file END";

    echo $dataQuery.$newline_double;

    if (mysqli_query($dbConn, $dataQuery)) {
        $updated = true;
        echo "Data table update SUCCESS. Updated with new box ids.".$newline_double;
    } else {
        $updated = false;
        echo "Data table update FAILED.".$newline_double;
    }
}

if (count($updateConsultantTable) > 0 && $updated) {
    
    $consultantQuery = "UPDATE consultant 
                            SET consultant_box_file_id = 
                                CASE $consultantBoxIdQuery ELSE consultant_box_file_id END
                            , consultant_consent_form =
                                CASE $consultantBoxNameQuery ELSE consultant_consent_form END";

    echo $consultantQuery . $newline_double;

    if (mysqli_query($dbConn, $consultantQuery)) {
        $updated = true;
        echo "Consultant table update SUCCESS. Updated with new box ids.".$newline_double;
    } else {
        $updated = false;
        echo "Consultant table update FAILED.".$newline_double;
    }
}


if ($updated) {
    mysqli_commit($dbConn);
} else {
    echo 'Rolling back database update...'.$newline_double;
    mysqli_rollback($dbConn);
    $allUploadedFiles = array_merge($updateDataTable, $updateConsultantTable);
    deleteRecentlyUploaded($allUploadedFiles);
}

mysqli_close($dbConn);
    

//Write failed files list to file
if($updated && count($failedFiles) > 0) {

    $failedFilesLog = 'failedfiles.txt';

    echo "Some files have failed to move to box...".$newline_double;

    $fileList = "Failed to move the following files to Box on " . date('m/d/Y') . ' at ' . date('h:i:s a') . "\n" . implode("\n", $failedFiles);

    if (file_exists($failedFilesLog)) {
        //prepend current contents
        $fileList .= "\n\n" . file_get_contents($failedFilesLog);
    }

    if(file_put_contents($failedFilesLog, $fileList)){
        echo "List of files saved in failedfiles.txt".$newline.$tab;
    } else {
        echo "List of files was unable to be written.".$newline.$tab; 
    }

    echo implode($newline.$tab, $failedFiles);
}

echo $newline_double."Script complete.";

$executionEndTime = microtime(true);
$seconds = (float)($executionEndTime - $executionStartTime);

echo "<br>This script took $seconds seconds to execute.";

function uploadFileToBox($row) {

    global $boxFolderId, $boxClient, $filePath, $tab, $newline;

    $dbId = $row['id'];
    $origFileName = $row['origFileName'];
    $currentFileName = $row['currentFileName'];
    $fileType = $row['fileType'];

    $info = pathinfo($origFileName);

    $validFileType = array('m4a','jpg','wma','wav','docx','pdf','MP3','mp3','png','3gp','doc','mov','amr','JPG','zip','WMA','do','m4v','3ga','mp4','ppt','mpeg','mpg','MOV','caf','txt','avi','pages','jpeg','aifc','flv','PNG','rtf','wve','gif','mP3','HEIC','webarchive','BMP','PDF','aif','j','jp','bmp','html');

    // get the filename without the extension
    if(!isset($info['extension']) || !in_array($info['extension'], $validFileType)){
        $fileExt = '';
        $fileBasename = $origFileName;
    }
    else{
        $fileExt = '.'.$info['extension'];
        $fileBasename = basename($origFileName,$fileExt);
    }

    //if cannot find extension from filename
    if($fileExt == ''){
        if($fileType == 'audio/mpeg'){
            $fileExt = '.mpeg';
        }
        else if($fileType == 'application/vnd.openxmlformats-officedocument.word'){
            $fileExt = '.doc';
        }
        else {
            $fileExt = ''; //can be anytype of file
        }
    }

    $finalBoxName = $fileBasename . '_' . $currentFileName . $fileExt;

    echo $tab . $currentFileName . $tab . $origFileName . $tab . $fileBasename . $tab . $fileExt . $tab . $finalBoxName . $newline;

    $fileRequest = new BoxFileRequest(['name' => $finalBoxName, 'parent' => ['id' => $boxFolderId]]);
    $res         = $boxClient->filesManager->uploadFile($fileRequest, $filePath.$currentFileName);
    $uploadedFileObject = json_decode($res->getBody());

    //get id from box
    $uploadedFileId   = $uploadedFileObject->entries[0]->id;
    $uploadedFileName = $uploadedFileObject->entries[0]->name;

    //name and id to array to update database
    return array('box_id' => $uploadedFileId, 'box_name' => $uploadedFileName );
}

function deleteRecentlyUploaded($allUploadedFiles){
    
    global $boxFolderId, $boxClient, $tab, $newline, $newline_double;
    
    echo "Removing uploaded files...".$newline_double;

    $manuallyRemoveFromBox = array();
    foreach($allUploadedFiles as $file){

        $boxFileId = $file['box_id'];
        $boxFileName = $file['box_name'];

        echo $tab.$boxFileId.$newline;
        $res = $boxClient->filesManager->deleteFile($boxFileId);
        $status = $res->getStatusCode();

        //204 = successful delete
        if($status != 204) { 
            $ManuallyRemoveFromBox[] = $boxFileName;
        }
    }
    
    if(count($manuallyRemoveFromBox)){
        echo "Please manually remove the following files from box before restarting migration script:".$newline.$tab;
        echo implode($newline.$tab, $manuallyRemoveFromBox);
    }

}