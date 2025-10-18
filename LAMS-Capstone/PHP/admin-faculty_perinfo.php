<?php
require_once 'db_connection.php';

header('Content-Type: application/json');

function sanitize_input($data) {
    global $conn;
    return $conn->real_escape_string(trim($data));
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

function validate_faculty_data($faculty_number, $name, $department, $program, $pincode = null) {
    $errors = [];
    
    if (empty($faculty_number)) {
        $errors[] = "Faculty number is required";
    }
    
    if (empty($name)) {
        $errors[] = "Name is required";
    }
    
    if (empty($department)) {
        $errors[] = "Department is required";
    }
    
    if (empty($program)) {
        $errors[] = "Program is required";
    }
    
    if (!empty($pincode) && (!preg_match('/^\d{6}$/', $pincode))) {
        $errors[] = "Pincode must be exactly 6 digits";
    }
    
    return $errors;
}

function identifier_exists_in_other_tables($conn, $identifier) {
    $student_sql = "SELECT id FROM students WHERE student_number = ?";
    $student_stmt = $conn->prepare($student_sql);
    $student_stmt->bind_param("s", $identifier);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();
    if ($student_result->num_rows > 0) {
        $student_stmt->close();
        return "students";
    }
    $student_stmt->close();
    
    $staff_sql = "SELECT id FROM staff WHERE staff_number = ?";
    $staff_stmt = $conn->prepare($staff_sql);
    $staff_stmt->bind_param("s", $identifier);
    $staff_stmt->execute();
    $staff_result = $staff_stmt->get_result();
    if ($staff_result->num_rows > 0) {
        $staff_stmt->close();
        return "staff";
    }
    $staff_stmt->close();
    
    return false;
}

// Handle bulk file upload - CSV ONLY
if (isset($_POST['action']) && $_POST['action'] === 'bulk_upload') {
    $file_type = sanitize_input($_POST['file_type']);
    $file_data = $_POST['file_data'];
    $filename = isset($_POST['filename']) ? sanitize_input($_POST['filename']) : 'faculty_upload.csv';
    
    try {
        $rows = explode("\n", $file_data);
        $headers = str_getcsv(array_shift($rows));
        
        $required_columns = ['Faculty Number', 'Name', 'Department', 'Program'];
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
            
            $faculty_data = array_combine($headers, $data);
            
            if (empty($faculty_data['Faculty Number']) || empty($faculty_data['Name']) || 
                empty($faculty_data['Department']) || empty($faculty_data['Program'])) {
                $errors[] = "Row " . ($index + 2) . ": Missing required fields";
                continue;
            }
            
            $check_sql = "SELECT id FROM faculty WHERE faculty_number = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $faculty_data['Faculty Number']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $errors[] = "Row " . ($index + 2) . ": Faculty number already exists";
                $check_stmt->close();
                continue;
            }
            $check_stmt->close();
            
            $existing_table = identifier_exists_in_other_tables($conn, $faculty_data['Faculty Number']);
            if ($existing_table) {
                $errors[] = "Row " . ($index + 2) . ": Faculty number exists in $existing_table table";
                continue;
            }
            
            $sql = "INSERT INTO faculty (faculty_number, name, department, program, pincode, registration_status) 
                    VALUES (?, ?, ?, ?, ?, 'Unregistered')";
            $stmt = $conn->prepare($sql);
            
            $pincode = !empty($faculty_data['Pincode']) ? $faculty_data['Pincode'] : null;
            $stmt->bind_param("sssss", 
                $faculty_data['Faculty Number'],
                $faculty_data['Name'],
                $faculty_data['Department'],
                $faculty_data['Program'],
                $pincode
            );
            
            if ($stmt->execute()) {
                $processed++;
            } else {
                $errors[] = "Row " . ($index + 2) . ": " . $stmt->error;
            }
            $stmt->close();
        }
        
        $message = "Processed $processed faculty records";
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
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_faculty') {
        $sql = "SELECT * FROM faculty ORDER BY name ASC";
        $result = $conn->query($sql);
        
        $faculty_list = [];
        while ($row = $result->fetch_assoc()) {
            // Format picture for consistent display
            if (!empty($row['picture'])) {
                $row['picture'] = formatPictureForDisplay($row['picture']);
            }
            $faculty_list[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'data' => $faculty_list]);
    }
    elseif ($action === 'get_single_faculty') {
        $faculty_id = sanitize_input($_POST['faculty_id']);
        $sql = "SELECT * FROM faculty WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $faculty_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $faculty = $result->fetch_assoc();
            // Format picture for display
            if (!empty($faculty['picture'])) {
                $faculty['picture'] = formatPictureForDisplay($faculty['picture']);
            }
            echo json_encode(['status' => 'success', 'data' => $faculty]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Faculty not found']);
        }
        $stmt->close();
    }
    elseif ($action === 'add_faculty') {
        $faculty_number = sanitize_input($_POST['faculty_number']);
        $name = sanitize_input($_POST['name']);
        $department = sanitize_input($_POST['department']);
        $program = sanitize_input($_POST['program']);
        $pincode = isset($_POST['pincode']) ? sanitize_input($_POST['pincode']) : null;
        
        $validation_errors = validate_faculty_data($faculty_number, $name, $department, $program, $pincode);
        
        if (!empty($validation_errors)) {
            echo json_encode(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validation_errors]);
            exit();
        }
        
        $existing_table = identifier_exists_in_other_tables($conn, $faculty_number);
        if ($existing_table) {
            echo json_encode([
                'status' => 'error', 
                'message' => "Faculty number already exists in the $existing_table table"
            ]);
            exit();
        }
        
        $check_sql = "SELECT id FROM faculty WHERE faculty_number = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $faculty_number);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Faculty number already exists']);
            $check_stmt->close();
            exit();
        }
        $check_stmt->close();
        
        $picture_path = null;
        if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/faculty/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $file_extension;
            $picture_path = $upload_dir . $filename;
            
            if (!move_uploaded_file($_FILES['picture']['tmp_name'], $picture_path)) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to upload picture']);
                exit();
            }
        }
        
        $sql = "INSERT INTO faculty (faculty_number, name, department, program, picture, pincode, registration_status) 
                VALUES (?, ?, ?, ?, ?, ?, 'Unregistered')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssss", $faculty_number, $name, $department, $program, $picture_path, $pincode);
        
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $sql = "SELECT * FROM faculty WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $new_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $new_faculty = $result->fetch_assoc();
            
            // Format picture for display
            if (!empty($new_faculty['picture'])) {
                $new_faculty['picture'] = formatPictureForDisplay($new_faculty['picture']);
            }
            
            echo json_encode(['status' => 'success', 'message' => 'Faculty added successfully', 'data' => $new_faculty]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add faculty: ' . $stmt->error]);
        }
        $stmt->close();
    }
    elseif ($action === 'update_faculty') {
        $faculty_id = sanitize_input($_POST['faculty_id']);
        $faculty_number = sanitize_input($_POST['faculty_number']);
        $name = sanitize_input($_POST['name']);
        $department = sanitize_input($_POST['department']);
        $program = sanitize_input($_POST['program']);
        $pincode = isset($_POST['pincode']) ? sanitize_input($_POST['pincode']) : null;
        
        $check_sql = "SELECT * FROM faculty WHERE id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $faculty_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Faculty not found']);
            $check_stmt->close();
            exit();
        }
        
        $current_faculty = $check_result->fetch_assoc();
        $check_stmt->close();
        
        $validation_errors = validate_faculty_data($faculty_number, $name, $department, $program, $pincode);
        
        if (!empty($validation_errors)) {
            echo json_encode(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validation_errors]);
            exit();
        }
        
        if ($faculty_number !== $current_faculty['faculty_number']) {
            $existing_table = identifier_exists_in_other_tables($conn, $faculty_number);
            if ($existing_table) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => "Faculty number already exists in the $existing_table table"
                ]);
                exit();
            }
            
            $check_sql = "SELECT id FROM faculty WHERE faculty_number = ? AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("si", $faculty_number, $faculty_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Faculty number already exists']);
                $check_stmt->close();
                exit();
            }
            $check_stmt->close();
        }
        
        // Handle picture scenarios
        $picture_path = $current_faculty['picture'];
        
        // Check if picture should be removed
        if (isset($_POST['remove_picture']) && $_POST['remove_picture'] == '1') {
            // Remove the picture - delete the file and set picture to null
            if (!empty($picture_path) && file_exists($picture_path)) {
                unlink($picture_path);
            }
            $picture_path = null; // This will set the picture to NULL in database
        } elseif (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
            // New picture uploaded
            if ($picture_path && file_exists($picture_path)) {
                unlink($picture_path);
            }
            
            $upload_dir = 'uploads/faculty/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $file_extension;
            $picture_path = $upload_dir . $filename;
            
            if (!move_uploaded_file($_FILES['picture']['tmp_name'], $picture_path)) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to upload picture']);
                exit();
            }
        } else {
            // Keep existing picture - no changes needed
            $picture_path = $current_faculty['picture'];
        }
        
        $sql = "UPDATE faculty SET faculty_number = ?, name = ?, department = ?, program = ?, picture = ?, pincode = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssi", $faculty_number, $name, $department, $program, $picture_path, $pincode, $faculty_id);
        
        if ($stmt->execute()) {
            $sql = "SELECT * FROM faculty WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $faculty_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $updated_faculty = $result->fetch_assoc();
            
            // Format picture for display
            if (!empty($updated_faculty['picture'])) {
                $updated_faculty['picture'] = formatPictureForDisplay($updated_faculty['picture']);
            }
            
            echo json_encode(['status' => 'success', 'message' => 'Faculty updated successfully', 'data' => $updated_faculty]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update faculty: ' . $stmt->error]);
        }
        $stmt->close();
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}

$conn->close();
?>