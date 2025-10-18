<?php
require_once 'db_connection.php';

header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => 'Invalid request'];

function handlePictureUpload() {
    $pictureData = null;
    
    if (isset($_FILES['picture_file']) && $_FILES['picture_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/students/';
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileExtension = strtolower(pathinfo($_FILES['picture_file']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($fileExtension, $allowedExtensions)) {
            $fileName = uniqid('student_') . '.' . $fileExtension;
            $uploadPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['picture_file']['tmp_name'], $uploadPath)) {
                // Return the relative path that can be used in src attribute
                $pictureData = $uploadPath;
            }
        }
    } elseif (isset($_POST['existing_picture']) && !empty($_POST['existing_picture'])) {
        // Handle base64 image data
        $pictureData = $_POST['existing_picture'];
        
        // If it's a base64 image, convert and save as file
        if (strpos($pictureData, 'data:image') === 0) {
            $uploadDir = 'uploads/students/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            list($type, $data) = explode(';', $pictureData);
            list(, $data) = explode(',', $data);
            $data = base64_decode($data);
            
            $extension = 'png';
            if (strpos($type, 'jpeg') !== false) $extension = 'jpg';
            if (strpos($type, 'gif') !== false) $extension = 'gif';
            
            $fileName = uniqid('student_') . '.' . $extension;
            $uploadPath = $uploadDir . $fileName;
            
            if (file_put_contents($uploadPath, $data)) {
                $pictureData = $uploadPath;
            }
        }
    }
    
    return $pictureData;
}

function formatPictureForDisplay($picturePath) {
    if (empty($picturePath)) {
        return null;
    }
    
    // If it's already a base64 data URL, return as is
    if (strpos($picturePath, 'data:image') === 0) {
        return $picturePath;
    }
    
    // If it's a file path, check if file exists and convert to base64 for consistent display
    if (file_exists($picturePath)) {
        $imageData = base64_encode(file_get_contents($picturePath));
        $mimeType = mime_content_type($picturePath);
        return 'data:' . $mimeType . ';base64,' . $imageData;
    }
    
    return null;
}

function isIdUnique($conn, $id, $currentId = null, $currentTable = 'students') {
    if ($currentTable !== 'students') {
        $stmt = $conn->prepare("SELECT student_number FROM students WHERE student_number = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            return ['unique' => false, 'message' => 'Student number already exists in students'];
        }
        $stmt->close();
    } else {
        $sql = "SELECT student_number FROM students WHERE student_number = ?";
        $params = [$id];
        if ($currentId !== null) {
            $sql .= " AND id != ?";
            $params[] = $currentId;
        }
        $stmt = $conn->prepare($sql);
        $types = str_repeat("s", count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            return ['unique' => false, 'message' => 'Student number already exists'];
        }
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT faculty_number FROM faculty WHERE faculty_number = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        return ['unique' => false, 'message' => 'Student number already exists in faculty'];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT staff_number FROM staff WHERE staff_number = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        return ['unique' => false, 'message' => 'Student number already exists in staff'];
    }
    $stmt->close();

    return ['unique' => true];
}

// Handle bulk file upload - CSV ONLY
if (isset($_POST['action']) && $_POST['action'] === 'bulk_upload') {
    $file_type = $_POST['file_type'];
    $file_data = $_POST['file_data'];
    $filename = isset($_POST['filename']) ? $_POST['filename'] : 'student_upload.csv';
    
    try {
        $rows = explode("\n", $file_data);
        $headers = str_getcsv(array_shift($rows));
        
        $required_columns = ['Student Number', 'Name', 'Department', 'Program', 'Year', 'Block'];
        $missing_columns = array_diff($required_columns, $headers);
        
        if (!empty($missing_columns)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required columns: ' . implode(', ', $missing_columns)]);
            exit();
        }
        
        $processed = 0;
        $errors = [];
        
        foreach ($rows as $index => $row) {
            if (empty(trim($row))) continue;
            
            $data = str_getcsv($row);
            if (count($data) !== count($headers)) continue;
            
            $student_data = array_combine($headers, $data);
            
            // Validate required fields
            if (empty($student_data['Student Number']) || empty($student_data['Name']) || 
                empty($student_data['Department']) || empty($student_data['Program']) ||
                empty($student_data['Year']) || empty($student_data['Block'])) {
                $errors[] = "Row " . ($index + 2) . ": Missing required fields";
                continue;
            }
            
            // Check if student number already exists
            $check_sql = "SELECT id FROM students WHERE student_number = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $student_data['Student Number']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $errors[] = "Row " . ($index + 2) . ": Student number already exists";
                $check_stmt->close();
                continue;
            }
            $check_stmt->close();
            
            // Check if student number exists in other tables
            $check_faculty = $conn->prepare("SELECT id FROM faculty WHERE faculty_number = ?");
            $check_faculty->bind_param("s", $student_data['Student Number']);
            $check_faculty->execute();
            if ($check_faculty->get_result()->num_rows > 0) {
                $errors[] = "Row " . ($index + 2) . ": Student number exists in faculty table";
                $check_faculty->close();
                continue;
            }
            $check_faculty->close();
            
            $check_staff = $conn->prepare("SELECT id FROM staff WHERE staff_number = ?");
            $check_staff->bind_param("s", $student_data['Student Number']);
            $check_staff->execute();
            if ($check_staff->get_result()->num_rows > 0) {
                $errors[] = "Row " . ($index + 2) . ": Student number exists in staff table";
                $check_staff->close();
                continue;
            }
            $check_staff->close();
            
            // Generate pincode if not provided
            $pincode = !empty($student_data['Pincode']) ? $student_data['Pincode'] : str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // Insert student record
            $sql = "INSERT INTO students (student_number, name, department, program, year_level, block, pin_code, registration_status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Unregistered')";
            $stmt = $conn->prepare($sql);
            
            $stmt->bind_param("sssssss", 
                $student_data['Student Number'],
                $student_data['Name'],
                $student_data['Department'],
                $student_data['Program'],
                $student_data['Year'],
                $student_data['Block'],
                $pincode
            );
            
            if ($stmt->execute()) {
                $processed++;
            } else {
                $errors[] = "Row " . ($index + 2) . ": " . $stmt->error;
            }
            $stmt->close();
        }
        
        $message = "Processed $processed student records";
        if (!empty($errors)) {
            $message .= ". Errors: " . implode('; ', array_slice($errors, 0, 5));
            if (count($errors) > 5) $message .= "...";
        }
        
        echo json_encode([
            'status' => 'success', 
            'message' => $message,
            'processed' => $processed,
            'errors' => $errors
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error processing CSV file: ' . $e->getMessage()]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    switch ($action) {
        case 'add':
            $studentNumber = $_POST['student_number'];
            $name = $_POST['student_name'];
            $department = $_POST['department'];
            $program = $_POST['program'];
            $year = $_POST['year'];
            $block = $_POST['block'];
            $pinCode = isset($_POST['pin_code']) ? $_POST['pin_code'] : null;
            $registrationStatus = isset($_POST['registration_status']) ? $_POST['registration_status'] : 'Unregistered';
            
            $picture = handlePictureUpload();
            
            $check = isIdUnique($conn, $studentNumber);
            if (!$check['unique']) {
                $response = ['status' => 'error', 'message' => $check['message']];
                break;
            }
            
            if (empty($pinCode)) {
                $pinCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            }
            
            $stmt = $conn->prepare("INSERT INTO students (student_number, name, department, program, year_level, block, picture, pin_code, registration_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssss", $studentNumber, $name, $department, $program, $year, $block, $picture, $pinCode, $registrationStatus);
            
            if ($stmt->execute()) {
                $newId = $conn->insert_id;
                // Fetch the newly created student to return complete data including picture
                $selectStmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
                $selectStmt->bind_param("i", $newId);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                $newStudent = $result->fetch_assoc();
                
                // Format picture for display
                if (!empty($newStudent['picture'])) {
                    $newStudent['picture'] = formatPictureForDisplay($newStudent['picture']);
                }
                
                $selectStmt->close();
                
                $response = ['status' => 'success', 'message' => 'Student added successfully', 'data' => $newStudent];
            } else {
                $response = ['status' => 'error', 'message' => 'Failed to add student: ' . $stmt->error];
            }
            $stmt->close();
            break;
            
        case 'edit':
            $id = $_POST['id'];
            $studentNumber = $_POST['student_number'];
            $name = $_POST['student_name'];
            $department = $_POST['department'];
            $program = $_POST['program'];
            $year = $_POST['year'];
            $block = $_POST['block'];
            $pinCode = isset($_POST['pin_code']) ? $_POST['pin_code'] : null;
            $registrationStatus = isset($_POST['registration_status']) ? $_POST['registration_status'] : 'Unregistered';
            
            $check = isIdUnique($conn, $studentNumber, $id, 'students');
            if (!$check['unique']) {
                $response = ['status' => 'error', 'message' => $check['message']];
                break;
            }
            
            // Get current student data
            $stmt = $conn->prepare("SELECT picture, pin_code FROM students WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->bind_result($currentPicture, $currentPin);
            $stmt->fetch();
            $stmt->close();
            
            // Handle picture scenarios
            $picture = null;
            
            if (isset($_POST['remove_picture']) && $_POST['remove_picture'] == '1') {
                // Remove the picture - delete the file and set picture to null
                if (!empty($currentPicture) && file_exists($currentPicture)) {
                    unlink($currentPicture);
                }
                $picture = null; // This will set the picture to NULL in database
            } elseif (isset($_FILES['picture_file']) && $_FILES['picture_file']['error'] === UPLOAD_ERR_OK) {
                // New picture uploaded
                $picture = handlePictureUpload();
                
                // Delete old picture if it exists
                if (!empty($currentPicture) && file_exists($currentPicture)) {
                    unlink($currentPicture);
                }
            } elseif (isset($_POST['existing_picture']) && !empty($_POST['existing_picture'])) {
                // Keep existing picture
                $picture = $currentPicture;
            } else {
                // No picture provided and not explicitly removed - keep existing
                $picture = $currentPicture;
            }
            
            if (empty($pinCode)) {
                $pinCode = $currentPin;
            }
            
            $stmt = $conn->prepare("UPDATE students SET student_number = ?, name = ?, department = ?, program = ?, year_level = ?, block = ?, picture = ?, pin_code = ?, registration_status = ? WHERE id = ?");
            $stmt->bind_param("sssssssssi", $studentNumber, $name, $department, $program, $year, $block, $picture, $pinCode, $registrationStatus, $id);
        
            if ($stmt->execute()) {
                // Fetch the updated student to return complete data
                $selectStmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
                $selectStmt->bind_param("i", $id);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                $updatedStudent = $result->fetch_assoc();
                
                // Format picture for display
                if (!empty($updatedStudent['picture'])) {
                    $updatedStudent['picture'] = formatPictureForDisplay($updatedStudent['picture']);
                }
                
                $selectStmt->close();
                
                $response = ['status' => 'success', 'message' => 'Student updated successfully', 'data' => $updatedStudent];
            } else {
                $response = ['status' => 'error', 'message' => 'Failed to update student: ' . $stmt->error];
            }
            $stmt->close();
            break;
            
        case 'get_all':
            $result = $conn->query("SELECT * FROM students ORDER BY name");
            
            if ($result) {
                $students = [];
                while ($row = $result->fetch_assoc()) {
                    // Format picture for consistent display
                    if (!empty($row['picture'])) {
                        $row['picture'] = formatPictureForDisplay($row['picture']);
                    }
                    $students[] = $row;
                }
                $response = ['status' => 'success', 'data' => $students];
            } else {
                $response = ['status' => 'error', 'message' => 'Failed to fetch students: ' . $conn->error];
            }
            break;
            
        case 'get_departments':
            $result = $conn->query("SELECT DISTINCT department FROM students ORDER BY department");
            
            if ($result) {
                $departments = [];
                while ($row = $result->fetch_assoc()) {
                    $departments[] = $row['department'];
                }
                $response = ['status' => 'success', 'data' => $departments];
            } else {
                $response = ['status' => 'error', 'message' => 'Failed to fetch departments: ' . $conn->error];
            }
            break;
            
        case 'get_programs':
            $department = isset($_POST['department']) ? $_POST['department'] : '';
            
            if (!empty($department)) {
                $stmt = $conn->prepare("SELECT DISTINCT program FROM students WHERE department = ? ORDER BY program");
                $stmt->bind_param("s", $department);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $conn->query("SELECT DISTINCT program FROM students ORDER BY program");
            }
            
            if ($result) {
                $programs = [];
                while ($row = $result->fetch_assoc()) {
                    $programs[] = $row['program'];
                }
                $response = ['status' => 'success', 'data' => $programs];
            } else {
                $response = ['status' => 'error', 'message' => 'Failed to fetch programs: ' . $conn->error];
            }
            
            if (isset($stmt)) {
                $stmt->close();
            }
            break;

        case 'get_student':
            if (!isset($_POST['id'])) {
                $response = ['status' => 'error', 'message' => 'ID parameter missing'];
                break;
            }
            
            $id = $_POST['id'];
            $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $student = $result->fetch_assoc();
                // Format picture for display
                if (!empty($student['picture'])) {
                    $student['picture'] = formatPictureForDisplay($student['picture']);
                }
                $response = ['status' => 'success', 'data' => $student];
            } else {
                $response = ['status' => 'error', 'message' => 'Student not found'];
            }
            $stmt->close();
            break;
                
        case 'search':
            $searchTerm = isset($_POST['search_term']) ? '%' . $_POST['search_term'] . '%' : '';
            $department = isset($_POST['department']) ? $_POST['department'] : '';
            $program = isset($_POST['program']) ? $_POST['program'] : '';
            $year = isset($_POST['year']) ? $_POST['year'] : '';
            $block = isset($_POST['block']) ? $_POST['block'] : '';
            $registration_status = isset($_POST['registration_status']) ? $_POST['registration_status'] : '';
            
            $sql = "SELECT * FROM students WHERE 1=1";
            $types = "";
            $params = [];
            
            if (!empty($searchTerm)) {
                $sql .= " AND (student_number LIKE ? OR name LIKE ?)";
                $types .= "ss";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (!empty($department)) {
                $sql .= " AND department = ?";
                $types .= "s";
                $params[] = $department;
            }
            
            if (!empty($program)) {
                $sql .= " AND program = ?";
                $types .= "s";
                $params[] = $program;
            }
            
            if (!empty($year)) {
                $sql .= " AND year_level = ?";
                $types .= "s";
                $params[] = $year;
            }
            
            if (!empty($block)) {
                $sql .= " AND block = ?";
                $types .= "s";
                $params[] = $block;
            }

            if (!empty($registration_status)) {
                $sql .= " AND registration_status = ?";
                $types .= "s";
                $params[] = $registration_status;
            }
        
            $sql .= " ORDER BY name";
            
            $stmt = $conn->prepare($sql);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                $students = [];
                while ($row = $result->fetch_assoc()) {
                    // Format picture for consistent display
                    if (!empty($row['picture'])) {
                        $row['picture'] = formatPictureForDisplay($row['picture']);
                    }
                    $students[] = $row;
                }
                $response = ['status' => 'success', 'data' => $students];
            } else {
                $response = ['status' => 'error', 'message' => 'Failed to search students: ' . $stmt->error];
            }
            
            $stmt->close();
            break;
    }
}

echo json_encode($response);
$conn->close();
?>