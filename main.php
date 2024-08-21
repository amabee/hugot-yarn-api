<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
include('connection.php');

class MAIN_API
{
    private $conn;

    public function __construct()
    {
        $this->conn = DatabaseConnection::getInstance()->getConnection();
    }

    public function addPost($json)
    {
        $json = json_decode($json, true);

        if (isset($json['userid']) && isset($json['post_content'])) {
            try {
                $post_content = $json['post_content'];
                $image = isset($json['image']) ? $json['image'] : null;
                preg_match_all('/#\w+/', $post_content, $matches);
                $hashtags = array_unique($matches[0]);


                if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
                    $fileTmpPath = $_FILES['image']['tmp_name'];
                    $fileName = $_FILES['image']['name'];

                    $fileNameCmps = explode(".", $fileName);
                    $fileExtension = strtolower(end($fileNameCmps));


                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                    if (in_array($fileExtension, $allowedExtensions)) {
                        $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
                        $uploadFileDir = './images/';
                        $dest_path = $uploadFileDir . $newFileName;

                        if (move_uploaded_file($fileTmpPath, $dest_path)) {
                            $image = $newFileName;
                        } else {
                            return json_encode(array("error" => "Failed to move uploaded file."));
                        }
                    } else {
                        return json_encode(array("error" => "Unsupported file extension."));
                    }
                }


                $sql = "INSERT INTO `posts`(`userid`, `post_content`, `image`, `created_at`) 
                        VALUES (:userid, :post_content, :image, NOW())";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(":userid", $json["userid"]);
                $stmt->bindValue(":post_content", $post_content);
                $stmt->bindValue(":image", $image);

                if ($stmt->execute()) {
                    $post_id = $this->conn->lastInsertId();


                    foreach ($hashtags as $hashtag) {
                        $tag = substr($hashtag, 1);

                        $checkSql = 'SELECT * FROM `hashtags` WHERE `hashtag` = :tag';
                        $checkStmt = $this->conn->prepare($checkSql);
                        $checkStmt->bindParam(':tag', $tag, PDO::PARAM_STR);
                        $checkStmt->execute();

                        if ($checkStmt->rowCount() == 0) {
                            $insertSql = 'INSERT INTO `hashtags` (`hashtag`) VALUES (:tag)';
                            $insertStmt = $this->conn->prepare($insertSql);
                            $insertStmt->bindParam(':tag', $tag, PDO::PARAM_STR);
                            $insertStmt->execute();
                        }

                        $hashtagId = $this->conn->lastInsertId();

                        $postHashtagSql = 'INSERT INTO `post_hashtags` (`post_id`, `hashtag_id`) VALUES (:post_id, :hashtag_id)';
                        $postHashtagStmt = $this->conn->prepare($postHashtagSql);
                        $postHashtagStmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
                        $postHashtagStmt->bindParam(':hashtag_id', $hashtagId, PDO::PARAM_INT);
                        $postHashtagStmt->execute();
                    }

                    return json_encode(array("success" => "Post Created"));
                } else {
                    return json_encode(array("error" => "Something went wrong creating your post"));
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                return json_encode(array("error" => "Exception error occurred while creating post"));
            }
        } else {
            return json_encode(array("error" => "Post Content Missing or User ID Missing"));
        }
    }


    public function getPosts($json)
    {
        $json = json_decode($json, true);

        if (isset($json['user_id'])) {
            $user_id = $json['user_id'];

            try {
                $sql = "
                    SELECT 
                        posts.post_id AS post_id,
                        posts.userid AS post_user_id,
                        posts.post_content,
                        posts.created_at,
                        posts.image,
                        users.username,
                        users.firstname,
                        users.lastname,
                        users.user_image,
                        COALESCE(reactions_count.reactions_count, 0) AS total_reactions,
                        COALESCE(comments_count.comments_count, 0) AS total_comments,
                        COALESCE(shares_count.shares_count, 0) AS total_shares,
                        COALESCE(user_reaction.reaction, 'none') AS user_reaction
                    FROM posts
                    JOIN users ON posts.userid = users.userid
                    LEFT JOIN (
                        SELECT post_id, COUNT(*) AS reactions_count
                        FROM reacts
                        GROUP BY post_id
                    ) AS reactions_count ON posts.post_id = reactions_count.post_id
                    LEFT JOIN (
                        SELECT post_id, COUNT(*) AS comments_count
                        FROM comments
                        GROUP BY post_id
                    ) AS comments_count ON posts.post_id = comments_count.post_id
                    LEFT JOIN (
                        SELECT post_id, COUNT(*) AS shares_count
                        FROM shares
                        GROUP BY post_id
                    ) AS shares_count ON posts.post_id = shares_count.post_id
                    LEFT JOIN (
                        SELECT post_id, reaction
                        FROM reacts
                        WHERE user_id = :user_id
                    ) AS user_reaction ON posts.post_id = user_reaction.post_id
                    WHERE posts.userid = :user_id
                    OR posts.userid IN (
                        SELECT followed_user_id
                        FROM follows
                        WHERE userid = :user_id
                    )
                    ORDER BY posts.created_at DESC
                    LIMIT 0,10
                ";

                $stmt = $this->conn->prepare($sql);
                $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $stmt->execute();

                $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return json_encode(array("success" => $posts));
            } catch (PDOException $e) {
                error_log($e->getMessage());
                return json_encode(array("error" => $e->getMessage()));
            }
        } else {
            return json_encode(array('error' => 'User ID Missing'));
        }
    }

    public function reactToPost($json)
    {
        $json = json_decode($json, true);

        if (isset($json['userid']) && isset($json['post_id']) && isset($json['reaction'])) {
            try {
                $userid = $json['userid'];
                $post_id = $json['post_id'];
                $reaction = $json['reaction'];

                // Check if the user has already reacted to the post
                $checkSql = 'SELECT * FROM `reacts` WHERE `user_id` = :userid AND `post_id` = :post_id';
                $checkStmt = $this->conn->prepare($checkSql);
                $checkStmt->bindParam(':userid', $userid, PDO::PARAM_INT);
                $checkStmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
                $checkStmt->execute();

                if ($checkStmt->rowCount() > 0) {
                    // Reaction exists, update or delete it
                    $existingReaction = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if ($reaction === "") {
                        // If reaction is empty, remove the existing reaction
                        $deleteSql = 'DELETE FROM `reacts` WHERE `user_id` = :userid AND `post_id` = :post_id';
                        $deleteStmt = $this->conn->prepare($deleteSql);
                        $deleteStmt->bindParam(':userid', $userid, PDO::PARAM_INT);
                        $deleteStmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
                        if ($deleteStmt->execute()) {
                            return json_encode(array("success" => "Reaction removed"));
                        } else {
                            return json_encode(array("error" => "Failed to remove reaction"));
                        }
                    } else {
                        // Update the existing reaction
                        $updateSql = 'UPDATE `reacts` SET `reaction` = :reaction, `reacted_at` = NOW() WHERE `user_id` = :userid AND `post_id` = :post_id';
                        $updateStmt = $this->conn->prepare($updateSql);
                        $updateStmt->bindParam(':reaction', $reaction, PDO::PARAM_STR);
                        $updateStmt->bindParam(':userid', $userid, PDO::PARAM_INT);
                        $updateStmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
                        if ($updateStmt->execute()) {
                            return json_encode(array("success" => "Reaction updated"));
                        } else {
                            return json_encode(array("error" => "Failed to update reaction"));
                        }
                    }
                } else {
                    // Insert a new reaction
                    $insertSql = 'INSERT INTO `reacts` (`post_id`, `user_id`, `reaction`, `reacted_at`) VALUES (:post_id, :user_id, :reaction, NOW())';
                    $insertStmt = $this->conn->prepare($insertSql);
                    $insertStmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
                    $insertStmt->bindParam(':user_id', $userid, PDO::PARAM_INT);
                    $insertStmt->bindParam(':reaction', $reaction, PDO::PARAM_STR);
                    if ($insertStmt->execute()) {
                        return json_encode(array("success" => "Post reacted to"));
                    } else {
                        return json_encode(array("error" => "Failed to add reaction"));
                    }
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                return json_encode(array("error" => "Exception error occurred while reacting to post"));
            }
        } else {
            return json_encode(array("error" => "User ID, Post ID, or Reaction Missing"));
        }
    }

    public function getComments($json)
    {

        $json = json_decode($json, true);
        try {

            if (!isset($json['user_id']) || !isset($json['post_id'])) {
                return json_encode(array("error" => "Invalid Data"));
            }

            $sql = "INSERT INTO `comments`( `user_id`, `post_id`, `comment`, `commentd_at`) 
                    VALUES (:user_id, :post_id, :comment, NOW())";

            $this->conn->prepare($sql);



        } catch (PDOException $e) {
            return json_encode(array("error" => $e->getMessage()));
        }
    }


}

$main_api = new MAIN_API();

if ($_SERVER["REQUEST_METHOD"] == "GET" || $_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_REQUEST['operation']) && isset($_REQUEST['json'])) {
        $operation = $_REQUEST['operation'];
        $json = $_REQUEST['json'];

        switch ($operation) {
            case 'addPost':
                echo $main_api->addPost($json);
                break;

            case 'getPosts':
                echo $main_api->getPosts($json);
                break;

            case 'reactToPost':
                echo $main_api->reactToPost($json);
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