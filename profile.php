<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('connection.php');


class Profile
{
    private $conn;

    public function __construct()
    {
        $this->conn = DatabaseConnection::getInstance()->getConnection();
    }

    public function updateProfile($json)
    {

        $json = json_decode($json, true);
        try {
            $fileName = null;

            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $image = $_FILES['image'];
                $fileName = basename($image['name']);
                $target_dir = "images/";
                $target_file = $target_dir . $fileName;

                $check = getimagesize($image['tmp_name']);
                if ($check === false) {
                    return json_encode(array("error" => "File is not an image."));
                }


                if (!move_uploaded_file($image['tmp_name'], $target_file)) {
                    return json_encode(array("error" => "Failed to upload image."));
                }
            } else {
                // Fallback if no new image is uploaded
                $fileName = isset($_POST['user_image']) ? $_POST['user_image'] : null;
            }

            // Retrieve other form data
            $firstname = $json['firstname'] ?? null;
            $lastname = $json['lastname'] ?? null;
            $email = $json['email'] ?? null;
            $username = $json['username'] ?? null;
            $password = sha1($json['password'] ?? '');
            $userid = $json['userid'] ?? null;

            $sql = "UPDATE `users` 
                    SET 
                        `firstname` = :firstname, 
                        `lastname` = :lastname, 
                        `email` = :email, 
                        `username` = :username, 
                        `password` = :password, 
                        `user_image` = :user_image 
                    WHERE 
                        `userid` = :userid";

            $stmt = $this->conn->prepare($sql);

            $stmt->bindParam(':firstname', $firstname);
            $stmt->bindParam(':lastname', $lastname);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $password);
            $stmt->bindParam(':user_image', $fileName);
            $stmt->bindParam(':userid', $userid);

            if ($stmt->execute()) {
                return json_encode(array("success" => "Profile updated successfully"));
            } else {
                error_log(print_r($stmt->errorInfo(), true));
                return json_encode(array("error" => "Failed to update profile"));
            }

        } catch (Exception $e) {
            return json_encode(array("error" => $e->getMessage()));
        }
    }


}

$profile = new Profile();

if ($_SERVER["REQUEST_METHOD"] == "GET" || $_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_REQUEST['operation']) && isset($_REQUEST['json'])) {
        $operation = $_REQUEST['operation'];
        $json = $_REQUEST['json'];

        switch ($operation) {
            case 'updateProfile':
                echo $profile->updateProfile($json);
                break;

            default:
                echo json_encode(["error" => "Invalid operation"]);
                break;
        }
    } else {
        echo json_encode(["error" => "Missing parameters"]);
    }
} else {
    echo json_encode(["error" => "Invalid request method"]);
}

?>