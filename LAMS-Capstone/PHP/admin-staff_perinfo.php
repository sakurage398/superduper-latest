<?php
// Include database connection
require_once 'db_connection.php';

// Set headers for JSON response
header('Content-Type: application/json');

// Function to sanitize input data
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

function validate_staff_data($staff_number, $name, $department, $role, $pincode = null) {
    $errors = [];
    
    if (empty($staff_number)) {
        $errors[] = "Staff number is required";
    }
    
    if (empty($name)) {
        $errors[] = "Name is required";
    }
    
    if (empty($department)) {
        $errors[] = "Department is required";
    }
    
    if (empty($role)) {
        $errors[] = "Role is required";
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
    
    $faculty_sql = "SELECT id FROM faculty WHERE faculty_number = ?";
    $faculty_stmt = $conn->prepare($faculty_sql);
    $faculty_stmt->bind_param("s", $identifier);
    $faculty_stmt->execute();
    $faculty_result = $faculty_stmt->get_result();
    if ($faculty_result->num_rows > 0) {
        $faculty_stmt->close();
        return "faculty";
    }
    $faculty_stmt->close();
    
    return false;
}

function handlePictureUpload($pictureData, $staff_number) {
    if (empty($pictureData)) {
        return null;
    }
    
    // If it's a base64 image, save it as a file
    if (strpos($pictureData, 'data:image') === 0) {
        $upload_dir = 'uploads/staff/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        list($type, $data) = explode(';', $pictureData);
        list(, $data) = explode(',', $data);
        $data = base64_decode($data);
        
        $extension = 'png';
        if (strpos($type, 'jpeg') !== false) $extension = 'jpg';
        if (strpos($type, 'gif') !== false) $extension = 'gif';
        
        $filename = 'staff_' . $staff_number . '_' . uniqid() . '.' . $extension;
        $upload_path = $upload_dir . $filename;
        
        if (file_put_contents($upload_path, $data)) {
            return $upload_path;
        }
    }
    
    return $pictureData;
}

// Handle bulk file upload - CSV ONLY
if (isset($_POST['action']) && $_POST['action'] === 'bulk_upload') {
    $file_type = sanitize_input($_POST['file_type']);
    $file_data = $_POST['file_data'];
    $filename = isset($_POST['filename']) ? sanitize_input($_POST['filename']) : 'staff_upload.csv';
    
    try {
        $rows = explode("\n", $file_data);
        $headers = str_getcsv(array_shift($rows));
        
        $required_columns = ['Staff Number', 'Name', 'Department', 'Role'];
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
            
            $staff_data = array_combine($headers, $data);
            
            if (empty($staff_data['Staff Number']) || empty($staff_data['Name']) || 
                empty($staff_data['Department']) || empty($staff_data['Role'])) {
                $errors[] = "Row " . ($index + 2) . ": Missing required fields";
                continue;
            }
            
            $check_sql = "SELECT id FROM staff WHERE staff_number = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $staff_data['Staff Number']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $errors[] = "Row " . ($index + 2) . ": Staff number already exists";
                $check_stmt->close();
                continue;
            }
            $check_stmt->close();
            
            $existing_table = identifier_exists_in_other_tables($conn, $staff_data['Staff Number']);
            if ($existing_table) {
                $errors[] = "Row " . ($index + 2) . ": Staff number exists in $existing_table table";
                continue;
            }
            
            $sql = "INSERT INTO staff (staff_number, name, department, role, pincode, registration_status) 
                    VALUES (?, ?, ?, ?, ?, 'Unregistered')";
            $stmt = $conn->prepare($sql);
            
            $pincode = !empty($staff_data['Pincode']) ? $staff_data['Pincode'] : null;
            $stmt->bind_param("sssss", 
                $staff_data['Staff Number'],
                $staff_data['Name'],
                $staff_data['Department'],
                $staff_data['Role'],
                $pincode
            );
            
            if ($stmt->execute()) {
                $processed++;
            } else {
                $errors[] = "Row " . ($index + 2) . ": " . $stmt->error;
            }
            $stmt->close();
        }
        
        $message = "Processed $processed staff records";
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

// Handle GET request to retrieve all staff or specific staff by ID
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $id = sanitize_input($_GET['id']);
        $sql = "SELECT * FROM staff WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $staff = $result->fetch_assoc();
            // Format picture for display
            if (!empty($staff['picture'])) {
                $staff['picture'] = formatPictureForDisplay($staff['picture']);
            }
            echo json_encode(['status' => 'success', 'data' => $staff]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Staff not found']);
        }
        $stmt->close();
    } else {
        $where_conditions = [];
        $params = [];
        $types = "";
        
        if (isset($_GET['department']) && !empty($_GET['department'])) {
            $department = sanitize_input($_GET['department']);
            $where_conditions[] = "department = ?";
            $params[] = $department;
            $types .= "s";
        }
        
        if (isset($_GET['role']) && !empty($_GET['role'])) {
            $role = sanitize_input($_GET['role']);
            $where_conditions[] = "role = ?";
            $params[] = $role;
            $types .= "s";
        }
        
        if (isset($_GET['registration_status']) && !empty($_GET['registration_status'])) {
            $registration_status = sanitize_input($_GET['registration_status']);
            $where_conditions[] = "registration_status = ?";
            $params[] = $registration_status;
            $types .= "s";
        }
        
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $search = sanitize_input($_GET['search']);
            $where_conditions[] = "(staff_number LIKE ? OR name LIKE ? OR department LIKE ? OR role LIKE ?)";
            $search_param = "%$search%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= "ssss";
        }
        
        $sql = "SELECT * FROM staff";
        if (!empty($where_conditions)) {
            $sql .= " WHERE " . implode(" AND ", $where_conditions);
        }
        $sql .= " ORDER BY name ASC";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $staff_list = [];
        while ($row = $result->fetch_assoc()) {
            // Format picture for consistent display
            if (!empty($row['picture'])) {
                $row['picture'] = formatPictureForDisplay($row['picture']);
            }
            $staff_list[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'data' => $staff_list]);
        $stmt->close();
    }
}

// Handle POST request to add new staff
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);
    } else {
        $data = $_POST;
    }
    
    $staff_number = isset($data['staff_number']) ? sanitize_input($data['staff_number']) : '';
    $name = isset($data['name']) ? sanitize_input($data['name']) : '';
    $department = isset($data['department']) ? sanitize_input($data['department']) : '';
    $role = isset($data['role']) ? sanitize_input($data['role']) : '';
    $registration_status = isset($data['registration_status']) ? sanitize_input($data['registration_status']) : 'Unregistered';
    $picture = isset($data['picture']) ? handlePictureUpload($data['picture'], $staff_number) : null;
    $pincode = isset($data['pincode']) ? sanitize_input($data['pincode']) : null;
    
    $validation_errors = validate_staff_data($staff_number, $name, $department, $role, $pincode);
    
    if (!empty($validation_errors)) {
        echo json_encode(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validation_errors]);
        exit();
    }
    
    $existing_table = identifier_exists_in_other_tables($conn, $staff_number);
    if ($existing_table) {
        echo json_encode([
            'status' => 'error', 
            'message' => "Staff number already exists in the $existing_table table"
        ]);
        exit();
    }
    
    $check_sql = "SELECT id FROM staff WHERE staff_number = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $staff_number);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Staff number already exists in staff records']);
        $check_stmt->close();
        exit();
    }
    $check_stmt->close();
    
    $sql = "INSERT INTO staff (staff_number, name, department, role, picture, pincode, registration_status) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssss", $staff_number, $name, $department, $role, $picture, $pincode, $registration_status);
        
    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        
        $sql = "SELECT * FROM staff WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $new_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $new_staff = $result->fetch_assoc();
        
        // Format picture for display
        if (!empty($new_staff['picture'])) {
            $new_staff['picture'] = formatPictureForDisplay($new_staff['picture']);
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Staff added successfully', 'data' => $new_staff]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to add staff: ' . $stmt->error]);
    }
    $stmt->close();
}

// Handle PUT or PATCH request to update staff
elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (!isset($data['id']) || empty($data['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Staff ID is required']);
        exit();
    }
    
    $id = sanitize_input($data['id']);
    
    $check_sql = "SELECT * FROM staff WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Staff not found']);
        $check_stmt->close();
        exit();
    }
    
    $current_staff = $check_result->fetch_assoc();
    $check_stmt->close();
    
    $staff_number = isset($data['staff_number']) ? sanitize_input($data['staff_number']) : $current_staff['staff_number'];
    $name = isset($data['name']) ? sanitize_input($data['name']) : $current_staff['name'];
    $department = isset($data['department']) ? sanitize_input($data['department']) : $current_staff['department'];
    $role = isset($data['role']) ? sanitize_input($data['role']) : $current_staff['role'];
    $registration_status = isset($data['registration_status']) ? sanitize_input($data['registration_status']) : $current_staff['registration_status'];
    $pincode = isset($data['pincode']) ? sanitize_input($data['pincode']) : $current_staff['pincode'];

    // Handle picture scenarios
    $picture = $current_staff['picture'];
    
    // Check if picture should be removed
    if (isset($data['remove_picture']) && $data['remove_picture'] == '1') {
        // Remove the picture - delete the file and set picture to null
        if (!empty($picture) && file_exists($picture)) {
            unlink($picture);
        }
        $picture = null; // This will set the picture to NULL in database
    } 
    // Check if new picture is provided
    elseif (isset($data['picture']) && !empty($data['picture'])) {
        // Delete old picture if it exists
        if (!empty($current_staff['picture']) && file_exists($current_staff['picture'])) {
            unlink($current_staff['picture']);
        }
        
        // Handle new picture upload
        $picture = handlePictureUpload($data['picture'], $staff_number);
    }
    // If no picture action and no new picture, keep existing picture
    else {
        $picture = $current_staff['picture'];
    }

    $validation_errors = validate_staff_data($staff_number, $name, $department, $role, $pincode);
    
    if (!empty($validation_errors)) {
        echo json_encode(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validation_errors]);
        exit();
    }
    
    if ($staff_number !== $current_staff['staff_number']) {
        $existing_table = identifier_exists_in_other_tables($conn, $staff_number);
        if ($existing_table) {
            echo json_encode([
                'status' => 'error', 
                'message' => "Staff number already exists in the $existing_table table"
            ]);
            exit();
        }
        
        $check_sql = "SELECT id FROM staff WHERE staff_number = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $staff_number, $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Staff number already exists in staff records']);
            $check_stmt->close();
            exit();
        }
        $check_stmt->close();
    }
    
    $sql = "UPDATE staff SET staff_number = ?, name = ?, department = ?, role = ?, picture = ?, pincode = ?, registration_status = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssi", $staff_number, $name, $department, $role, $picture, $pincode, $registration_status, $id);
    
    if ($stmt->execute()) {
        $sql = "SELECT * FROM staff WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $updated_staff = $result->fetch_assoc();
        
        // Format picture for display
        if (!empty($updated_staff['picture'])) {
            $updated_staff['picture'] = formatPictureForDisplay($updated_staff['picture']);
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Staff updated successfully', 'data' => $updated_staff]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to update staff: ' . $stmt->error]);
    }
    $stmt->close();
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Unsupported request method']);
}

$conn->close();
?>