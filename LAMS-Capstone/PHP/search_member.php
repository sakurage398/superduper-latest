<?php
require_once 'db_connection.php';

header('Content-Type: application/json');

function formatPictureForDisplay($picturePath, $userType) {
    if (empty($picturePath)) {
        return null;
    }
    
    // If it's already a data URL, return as is
    if (strpos($picturePath, 'data:image') === 0) {
        return $picturePath;
    }
    
    // Extract just the filename from the path
    $filename = basename($picturePath);
    
    // Debug: Log what we're looking for
    error_log("Looking for image: " . $filename . " for user type: " . $userType);
    
    // Construct the correct path based on your file structure
    // Images are in: PHP/uploads/students/ and PHP/uploads/faculty/
    $baseDir = __DIR__ . '/'; // This points to PHP/ directory
    $correctPath = $baseDir . 'uploads/' . $userType . 's/' . $filename;
    
    // Debug logging
    error_log("Full image path: " . $correctPath);
    error_log("File exists: " . (file_exists($correctPath) ? 'Yes' : 'No'));
    
    // Check if file exists and is readable
    if (file_exists($correctPath) && is_file($correctPath) && is_readable($correctPath)) {
        try {
            $imageData = base64_encode(file_get_contents($correctPath));
            $mimeType = mime_content_type($correctPath);
            
            if ($mimeType && strpos($mimeType, 'image/') === 0) {
                error_log("Successfully loaded image: " . $filename);
                return 'data:' . $mimeType . ';base64,' . $imageData;
            } else {
                error_log("Invalid mime type: " . $mimeType . " for file: " . $correctPath);
            }
        } catch (Exception $e) {
            error_log("Error reading image file: " . $e->getMessage());
        }
    }
    
    // If the above path doesn't work, try alternative paths
    $alternativePaths = [
        $baseDir . 'uploads/' . $userType . '/' . $filename,
        $baseDir . 'uploads/' . $filename,
        '../uploads/' . $userType . 's/' . $filename,
        '../uploads/' . $filename
    ];
    
    foreach ($alternativePaths as $altPath) {
        error_log("Trying alternative path: " . $altPath);
        if (file_exists($altPath) && is_file($altPath) && is_readable($altPath)) {
            try {
                $imageData = base64_encode(file_get_contents($altPath));
                $mimeType = mime_content_type($altPath);
                
                if ($mimeType && strpos($mimeType, 'image/') === 0) {
                    error_log("Successfully loaded image from alternative path: " . $altPath);
                    return 'data:' . $mimeType . ';base64,' . $imageData;
                }
            } catch (Exception $e) {
                error_log("Error reading alt image file: " . $e->getMessage());
            }
        }
    }
    
    error_log("All attempts failed to load image: " . $filename);
    return null;
}

$idNumber = isset($_POST['idNumber']) ? $_POST['idNumber'] : '';
$userType = isset($_POST['userType']) ? $_POST['userType'] : '';

error_log("=== SEARCH REQUEST ===");
error_log("Received ID: " . $idNumber . ", Type: " . $userType);

if (empty($idNumber)) {
    echo json_encode([
        'success' => false,
        'message' => 'ID number is required'
    ]);
    exit;
}

// Rest of your existing code remains the same...
switch ($userType) {
    case 'student':
        $table = 'students';
        $idField = 'student_number';
        break;
    case 'faculty':
        $table = 'faculty';
        $idField = 'faculty_number';
        break;
    case 'staff':
        $table = 'staff';
        $idField = 'staff_number';
        break;
    case 'unknown':
        $userData = null;
        
        // Search in students table
        $stmt = $conn->prepare("SELECT student_number, name, department, program, year_level, block, picture, registration_status, expiration_date, 'student' as user_type FROM students WHERE student_number = ?");
        $stmt->bind_param("s", $idNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $userData = $result->fetch_assoc();
            $userType = 'student';
            
            error_log("Found student: " . $userData['name']);
            if (!empty($userData['picture'])) {
                error_log("Student picture path in DB: " . $userData['picture']);
                $userData['picture'] = formatPictureForDisplay($userData['picture'], 'student');
                error_log("Student picture processed: " . ($userData['picture'] ? 'Success' : 'Failed'));
            } else {
                error_log("No picture path in database for student");
            }
        } else {
            $stmt->close();
            // Search in faculty table
            $stmt = $conn->prepare("SELECT faculty_number, name, department, program, picture, registration_status, expiration_date, 'faculty' as user_type FROM faculty WHERE faculty_number = ?");
            $stmt->bind_param("s", $idNumber);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $userData = $result->fetch_assoc();
                $userType = 'faculty';
                
                error_log("Found faculty: " . $userData['name']);
                if (!empty($userData['picture'])) {
                    error_log("Faculty picture path in DB: " . $userData['picture']);
                    $userData['picture'] = formatPictureForDisplay($userData['picture'], 'faculty');
                    error_log("Faculty picture processed: " . ($userData['picture'] ? 'Success' : 'Failed'));
                } else {
                    error_log("No picture path in database for faculty");
                }
            } else {
                $stmt->close();
                // Search in staff table
                $stmt = $conn->prepare("SELECT staff_number, name, department, role, picture, registration_status, expiration_date, 'staff' as user_type FROM staff WHERE staff_number = ?");
                $stmt->bind_param("s", $idNumber);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $userData = $result->fetch_assoc();
                    $userType = 'staff';
                    
                    error_log("Found staff: " . $userData['name']);
                    if (!empty($userData['picture'])) {
                        error_log("Staff picture path in DB: " . $userData['picture']);
                        $userData['picture'] = formatPictureForDisplay($userData['picture'], 'staff');
                        error_log("Staff picture processed: " . ($userData['picture'] ? 'Success' : 'Failed'));
                    } else {
                        error_log("No picture path in database for staff");
                    }
                }
            }
        }
        
        if ($userData) {
            echo json_encode([
                'success' => true,
                'userData' => $userData,
                'userType' => $userType
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No record found for that ID number'
            ]);
        }
        
        if (isset($stmt)) {
            $stmt->close();
        }
        $conn->close();
        exit;
        
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Invalid user type'
        ]);
        exit;
}

try {
    if ($userType === 'student') {
        $query = "SELECT student_number, name, department, program, year_level, block, picture, registration_status, expiration_date FROM students WHERE student_number = ?";
    } elseif ($userType === 'faculty') {
        $query = "SELECT faculty_number, name, department, program, picture, registration_status, expiration_date FROM faculty WHERE faculty_number = ?";
    } elseif ($userType === 'staff') {
        $query = "SELECT staff_number, name, department, role, picture, registration_status, expiration_date FROM staff WHERE staff_number = ?";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $idNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $userData = $result->fetch_assoc();
        
        error_log("Found " . $userType . ": " . $userData['name']);
        if (!empty($userData['picture'])) {
            error_log("Picture path in DB: " . $userData['picture']);
            $userData['picture'] = formatPictureForDisplay($userData['picture'], $userType);
            error_log("Picture processed: " . ($userData['picture'] ? 'Success' : 'Failed'));
        } else {
            error_log("No picture path in database for " . $userType);
        }
        
        echo json_encode([
            'success' => true,
            'userData' => $userData,
            'userType' => $userType
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No record found for that ID number'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

if (isset($stmt)) {
    $stmt->close();
}
$conn->close();
?>