<?php
// Handle AJAX request for file content FIRST - before any other code
if (isset($_GET['get_file_content']) && isset($_GET['file']) && isset($_GET['dir'])) {
    session_start();
    
    // Check if user is authenticated
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        http_response_code(403);
        exit('Unauthorized');
    }
    
    $filename = $_GET['file'];
    $dir = $_GET['dir'];
    $filepath = realpath($dir . '/' . $filename);
    
    // Security check - ensure file exists and is readable
    if ($filepath && file_exists($filepath) && is_readable($filepath) && !is_dir($filepath)) {
        // Check if file is editable
        $allowed_extensions = array('txt', 'html', 'css', 'js', 'php', 'json', 'xml', 'sql', 'log', 'md', 'yml', 'yaml', 'htaccess', 'conf', 'ini', 'cfg');
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($extension, $allowed_extensions) || empty($extension)) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Read and output file content
            $content = file_get_contents($filepath);
            echo $content;
        } else {
            http_response_code(400);
            echo 'File type not supported for editing';
        }
    } else {
        http_response_code(404);
        echo 'File not found or not accessible';
    }
    exit;
}

session_start();

// Configuration
$password = 'stpadmin'; // Change this password
$root_path = $_SERVER['DOCUMENT_ROOT']; // Root directory
$allowed_extensions = array('txt', 'html', 'css', 'js', 'php', 'json', 'xml', 'sql', 'log', 'md', 'yml', 'yaml', 'htaccess', 'conf', 'ini', 'cfg');
$max_file_size = 50 * 1024 * 1024; // 50MB

// Authentication
if (!isset($_SESSION['authenticated'])) {
    if (isset($_POST['password']) && $_POST['password'] === $password) {
        $_SESSION['authenticated'] = true;
    } else {
        showLoginForm();
        exit;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get current directory
$current_dir = isset($_GET['dir']) ? $_GET['dir'] : $root_path;
$current_dir = realpath($current_dir) ?: $root_path;

// Security check
if (strpos($current_dir, $root_path) !== 0) {
    $current_dir = $root_path;
}

// Handle file operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleFileOperations();
}

// Handle downloads
if (isset($_GET['download'])) {
    downloadFile($_GET['download']);
}

function showLoginForm() {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>STP File Manager - Login</title>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { font-family: Arial; background: #f4f4f4; margin: 0; padding: 50px; }
            .login-box { max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
            .login-box h2 { text-align: center; color: #333; margin-bottom: 30px; }
            .login-box input { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
            .login-box button { width: 100%; padding: 12px; background: #007cba; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
            .login-box button:hover { background: #005a8b; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>🔐 STP File Manager</h2>
            <form method="post">
                <input type="password" name="password" placeholder="Enter Password" required>
                <button type="submit">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

function handleFileOperations() {
    global $current_dir, $allowed_extensions, $max_file_size;
    
    // Create new folder
    if (isset($_POST['create_folder'])) {
        $folder_name = sanitizeFileName($_POST['folder_name']);
        if ($folder_name) {
            mkdir($current_dir . '/' . $folder_name, 0755, true);
        }
    }
    
    // Upload file
    if (isset($_FILES['upload_file'])) {
        $file = $_FILES['upload_file'];
        if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= $max_file_size) {
            $filename = sanitizeFileName($file['name']);
            move_uploaded_file($file['tmp_name'], $current_dir . '/' . $filename);
        }
    }
    
    // Save edited file
    if (isset($_POST['save_file'])) {
        $filename = $_POST['filename'];
        $content = $_POST['file_content'];
        $filepath = $current_dir . '/' . $filename;
        
        // Security check
        if (strpos(realpath(dirname($filepath)), $current_dir) === 0) {
            file_put_contents($filepath, $content);
            echo "<script>alert('File saved successfully!');</script>";
        }
    }
    
    // Rename file/folder
    if (isset($_POST['rename_file'])) {
        $old_name = $_POST['old_name'];
        $new_name = sanitizeFileName($_POST['new_name']);
        if ($new_name) {
            rename($current_dir . '/' . $old_name, $current_dir . '/' . $new_name);
        }
    }
    
    // Delete file/folder
    if (isset($_POST['delete_file'])) {
        $filename = $_POST['delete_filename'];
        $filepath = $current_dir . '/' . $filename;
        if (is_dir($filepath)) {
            removeDirectory($filepath);
        } else {
            unlink($filepath);
        }
    }
    
    // Copy to clipboard (single file/folder)
    if (isset($_POST['copy_file'])) {
        $filename = $_POST['copy_filename'];
        $_SESSION['clipboard'] = ['action' => 'copy', 'source' => realpath($current_dir . '/' . $filename)];
    }
    
    // Cut to clipboard (single file/folder)
    if (isset($_POST['cut_file'])) {
        $filename = $_POST['cut_filename'];
        $_SESSION['clipboard'] = ['action' => 'move', 'source' => realpath($current_dir . '/' . $filename)];
    }
    
    // NEW: Copy entire directory
    if (isset($_POST['copy_directory'])) {
        $_SESSION['clipboard'] = ['action' => 'copy_directory', 'source' => realpath($current_dir)];
        echo "<script>alert('Directory copied to clipboard!');</script>";
    }
    
    // NEW: Copy selected files
    if (isset($_POST['copy_selected'])) {
        if (isset($_POST['selected_files']) && !empty($_POST['selected_files'])) {
            $selected_files = array();
            foreach ($_POST['selected_files'] as $file) {
                $selected_files[] = realpath($current_dir . '/' . $file);
            }
            $_SESSION['clipboard'] = ['action' => 'copy_selected', 'sources' => $selected_files];
            echo "<script>alert('" . count($selected_files) . " files copied to clipboard!');</script>";
        }
    }
    
    // NEW: Cut selected files
    if (isset($_POST['cut_selected'])) {
        if (isset($_POST['selected_files']) && !empty($_POST['selected_files'])) {
            $selected_files = array();
            foreach ($_POST['selected_files'] as $file) {
                $selected_files[] = realpath($current_dir . '/' . $file);
            }
            $_SESSION['clipboard'] = ['action' => 'move_selected', 'sources' => $selected_files];
            echo "<script>alert('" . count($selected_files) . " files cut to clipboard!');</script>";
        }
    }
    
    // NEW: Delete selected files
    if (isset($_POST['delete_selected'])) {
        if (isset($_POST['selected_files']) && !empty($_POST['selected_files'])) {
            $deleted_count = 0;
            foreach ($_POST['selected_files'] as $file) {
                $filepath = $current_dir . '/' . $file;
                if (file_exists($filepath)) {
                    if (is_dir($filepath)) {
                        removeDirectory($filepath);
                    } else {
                        unlink($filepath);
                    }
                    $deleted_count++;
                }
            }
            echo "<script>alert('$deleted_count files/folders deleted!');</script>";
        }
    }
    
    // Enhanced paste operation
    if (isset($_POST['paste'])) {
        if (isset($_SESSION['clipboard'])) {
            $clip = $_SESSION['clipboard'];
            
            if ($clip['action'] === 'copy') {
                // Single file/folder copy
                $dest = $current_dir . '/' . basename($clip['source']);
                if (file_exists($dest)) {
                    echo "<script>alert('Destination already exists!');</script>";
                } else {
                    if (is_dir($clip['source'])) {
                        recursiveCopy($clip['source'], $dest);
                    } else {
                        copy($clip['source'], $dest);
                    }
                }
            } elseif ($clip['action'] === 'move') {
                // Single file/folder move
                $dest = $current_dir . '/' . basename($clip['source']);
                if (file_exists($dest)) {
                    echo "<script>alert('Destination already exists!');</script>";
                } else {
                    rename($clip['source'], $dest);
                    unset($_SESSION['clipboard']);
                }
            } elseif ($clip['action'] === 'copy_directory') {
                // Directory copy
                $dest = $current_dir . '/' . basename($clip['source']) . '_copy';
                $counter = 1;
                while (file_exists($dest)) {
                    $dest = $current_dir . '/' . basename($clip['source']) . '_copy_' . $counter;
                    $counter++;
                }
                recursiveCopy($clip['source'], $dest);
                echo "<script>alert('Directory copied successfully!');</script>";
            } elseif ($clip['action'] === 'copy_selected') {
                // Multiple files copy
                $copied = 0;
                $conflicts = 0;
                foreach ($clip['sources'] as $source) {
                    $dest = $current_dir . '/' . basename($source);
                    if (file_exists($dest)) {
                        $conflicts++;
                    } else {
                        if (is_dir($source)) {
                            recursiveCopy($source, $dest);
                        } else {
                            copy($source, $dest);
                        }
                        $copied++;
                    }
                }
                $message = "$copied files copied successfully!";
                if ($conflicts > 0) {
                    $message .= " $conflicts files skipped (already exist).";
                }
                echo "<script>alert('$message');</script>";
            } elseif ($clip['action'] === 'move_selected') {
                // Multiple files move
                $moved = 0;
                $conflicts = 0;
                foreach ($clip['sources'] as $source) {
                    $dest = $current_dir . '/' . basename($source);
                    if (file_exists($dest)) {
                        $conflicts++;
                    } else {
                        rename($source, $dest);
                        $moved++;
                    }
                }
                $message = "$moved files moved successfully!";
                if ($conflicts > 0) {
                    $message .= " $conflicts files skipped (already exist).";
                }
                echo "<script>alert('$message');</script>";
                unset($_SESSION['clipboard']);
            }
        }
    }
    
    // Clear clipboard
    if (isset($_POST['clear_clipboard'])) {
        unset($_SESSION['clipboard']);
    }
}

function sanitizeFileName($filename) {
    return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
}

function removeDirectory($dir) {
    if (is_dir($dir)) {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $filepath = $dir . '/' . $file;
            is_dir($filepath) ? removeDirectory($filepath) : unlink($filepath);
        }
        rmdir($dir);
    }
}

function recursiveCopy($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst, 0755);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                recursiveCopy($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

function downloadFile($filename) {
    global $current_dir;
    $filepath = $current_dir . '/' . $filename;
    if (file_exists($filepath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function isEditableFile($filename) {
    global $allowed_extensions;
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowed_extensions) || empty($extension);
}

// Get directory contents
$files = scandir($current_dir);
$folders = array();
$regular_files = array();

foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        $filepath = $current_dir . '/' . $file;
        if (is_dir($filepath)) {
            $folders[] = $file;
        } else {
            $regular_files[] = $file;
        }
    }
}

// Filter based on search
$search_term = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
if ($search_term !== '') {
    $folders = array_filter($folders, function($folder) use ($search_term) {
        return strpos(strtolower($folder), $search_term) !== false;
    });
    $regular_files = array_filter($regular_files, function($file) use ($search_term) {
        return strpos(strtolower($file), $search_term) !== false;
    });
}

sort($folders);
sort($regular_files);
?>

<!DOCTYPE html>
<html>
<head>
    <title>STP File Manager</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- CodeMirror CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8f9fa; }
        
        .header { background: #2c3e50; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 24px; }
        .header .logout { background: #e74c3c; padding: 8px 15px; border-radius: 5px; text-decoration: none; color: white; }
        
        .toolbar { background: white; padding: 15px 20px; border-bottom: 1px solid #ddd; display: flex; flex-wrap: wrap; gap: 10px; }
        .toolbar input, .toolbar button { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
        .toolbar button { background: #3498db; color: white; cursor: pointer; }
        .toolbar button:hover { background: #2980b9; }
        .toolbar button:disabled { background: #95a5a6; cursor: not-allowed; }
        
        .bulk-actions { background: #ecf0f1; padding: 10px 20px; border-bottom: 1px solid #ddd; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .bulk-actions button { padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; }
        .bulk-copy { background: #16a085; color: white; }
        .bulk-cut { background: #8e44ad; color: white; }
        .bulk-delete { background: #e74c3c; color: white; }
        .bulk-copy-dir { background: #27ae60; color: white; }
        .select-all { background: #34495e; color: white; }
        
        .breadcrumb { background: #ecf0f1; padding: 10px 20px; font-size: 14px; }
        .breadcrumb a { color: #2980b9; text-decoration: none; }
        
        .container { display: flex; height: calc(100vh - 200px); }
        
        .main-content { flex: 1; padding: 20px; overflow-y: auto; }
        
        .file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
        .file-item { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 15px; text-align: center; transition: all 0.3s; position: relative; }
        .file-item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .file-item.selected { border-color: #3498db; background: #ebf3fd; }
        
        .file-checkbox { position: absolute; top: 10px; left: 10px; width: 18px; height: 18px; }
        
        .file-icon { font-size: 48px; margin-bottom: 10px; }
        .folder-icon { color: #f39c12; }
        .file-icon-default { color: #95a5a6; }
        .image-icon { color: #e67e22; }
        .text-icon { color: #27ae60; }
        
        .file-name { font-weight: bold; margin-bottom: 8px; word-break: break-word; }
        .file-info { font-size: 12px; color: #7f8c8d; margin-bottom: 10px; }
        
        .file-actions { display: flex; gap: 5px; justify-content: center; flex-wrap: wrap; }
        .file-actions button { padding: 4px 8px; font-size: 12px; border: none; border-radius: 3px; cursor: pointer; }
        .btn-download { background: #27ae60; color: white; }
        .btn-edit { background: #3498db; color: white; }
        .btn-rename { background: #f39c12; color: white; }
        .btn-delete { background: #e74c3c; color: white; }
        .btn-copy { background: #16a085; color: white; }
        .btn-move { background: #8e44ad; color: white; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; max-width: 90%; max-height: 90%; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-close { background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; }
        
        .CodeMirror { border: 1px solid #ddd; height: 400px; resize: vertical; }
        
        .loading { text-align: center; padding: 20px; color: #666; }
        
        .selection-info { font-weight: bold; color: #2c3e50; }
        
        @media (max-width: 768px) {
            .container { flex-direction: column; height: auto; }
            .file-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
            .toolbar { flex-direction: column; align-items: stretch; }
            .bulk-actions { flex-direction: column; align-items: stretch; }
        }
    </style>
    <!-- CodeMirror JS and Modes -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/clike/clike.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/sql/sql.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/markdown/markdown.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/yaml/yaml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/properties/properties.min.js"></script>
</head>
<body>
    <div class="header">
        <h1>📁 STP File Manager</h1>
        <a href="?logout=1" class="logout">Logout</a>
    </div>
    
    <div class="toolbar">
        <form method="post" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <input type="text" name="folder_name" placeholder="Folder Name" required>
            <button type="submit" name="create_folder">Create Folder</button>
        </form>
        
        <form method="post" enctype="multipart/form-data" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <input type="file" name="upload_file" required>
            <button type="submit">Upload File</button>
        </form>

        <form method="get" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($current_dir); ?>">
            <input type="text" name="search" placeholder="Search files and folders" value="<?php echo htmlspecialchars($search_term); ?>">
            <button type="submit">Search</button>
        </form>
        
        <form method="post" style="display: inline;">
            <button type="submit" name="paste" <?php if (!isset($_SESSION['clipboard'])) echo 'disabled'; ?>>Paste</button>
        </form>
        
        <?php if (isset($_SESSION['clipboard'])): ?>
            <form method="post" style="display: inline;">
                <button type="submit" name="clear_clipboard">Clear Clipboard</button>
            </form>
        <?php endif; ?>
    </div>
    
    <!-- NEW: Bulk Actions Toolbar -->
    <div class="bulk-actions">
        <span class="selection-info">Selection Tools:</span>
        <button type="button" class="select-all" onclick="selectAll()">Select All</button>
        <button type="button" onclick="selectNone()">Select None</button>
        
        <form method="post" style="display: inline;" id="bulkForm">
            <button type="submit" name="copy_selected" class="bulk-copy" onclick="return validateSelection('copy')">Copy Selected</button>
            <button type="submit" name="cut_selected" class="bulk-cut" onclick="return validateSelection('cut')">Cut Selected</button>
            <button type="submit" name="delete_selected" class="bulk-delete" onclick="return validateSelection('delete') && confirm('Delete selected files?')">Delete Selected</button>
        </form>
        
        <form method="post" style="display: inline;">
            <button type="submit" name="copy_directory" class="bulk-copy-dir">Copy Entire Directory</button>
        </form>
        
        <span id="selectedCount" class="selection-info">0 selected</span>
    </div>
    
    <div class="breadcrumb">
        📍 Current Path: <?php echo str_replace($root_path, 'Root', $current_dir); ?>
        <?php if ($current_dir !== $root_path): ?>
            <a href="?dir=<?php echo urlencode(dirname($current_dir)); ?>">⬆️ Parent Directory</a>
        <?php endif; ?>
        <?php if ($search_term !== ''): ?>
            | Searching for: "<?php echo htmlspecialchars($search_term); ?>" <a href="?dir=<?php echo urlencode($current_dir); ?>">Clear Search</a>
        <?php endif; ?>
        <?php if (isset($_SESSION['clipboard'])): ?>
            | Clipboard: <?php 
                if (isset($_SESSION['clipboard']['action'])) {
                    if ($_SESSION['clipboard']['action'] === 'copy_directory') {
                        echo 'copy directory ' . basename($_SESSION['clipboard']['source']);
                    } elseif ($_SESSION['clipboard']['action'] === 'copy_selected') {
                        echo 'copy ' . count($_SESSION['clipboard']['sources']) . ' files';
                    } elseif ($_SESSION['clipboard']['action'] === 'move_selected') {
                        echo 'move ' . count($_SESSION['clipboard']['sources']) . ' files';
                    } else {
                        echo $_SESSION['clipboard']['action'] . ' ' . basename($_SESSION['clipboard']['source']);
                    }
                }
            ?>
        <?php endif; ?>
    </div>
    
    <div class="container">
        <div class="main-content">
            <div class="file-grid">
                <?php foreach ($folders as $folder): ?>
                    <div class="file-item">
                        <input type="checkbox" class="file-checkbox" name="selected_files[]" value="<?php echo htmlspecialchars($folder); ?>" onchange="updateSelection()">
                        <div class="file-icon folder-icon">📁</div>
                        <div class="file-name"><?php echo htmlspecialchars($folder); ?></div>
                        <div class="file-info">Folder</div>
                        <div class="file-actions">
                            <a href="?dir=<?php echo urlencode($current_dir . '/' . $folder); ?>">
                                <button class="btn-download">Open</button>
                            </a>
                            <button class="btn-rename" onclick="renameItem('<?php echo htmlspecialchars($folder); ?>')">Rename</button>
                            <button class="btn-delete" onclick="deleteItem('<?php echo htmlspecialchars($folder); ?>')">Delete</button>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="copy_filename" value="<?php echo htmlspecialchars($folder); ?>">
                                <button type="submit" name="copy_file" class="btn-copy">Copy</button>
                            </form>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="cut_filename" value="<?php echo htmlspecialchars($folder); ?>">
                                <button type="submit" name="cut_file" class="btn-move">Move</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php foreach ($regular_files as $file): ?>
                    <?php
                    $filepath = $current_dir . '/' . $file;
                    $filesize = filesize($filepath);
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $icon = '📄';
                    $icon_class = 'file-icon-default';
                    
                    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg'])) {
                        $icon = '🖼️';
                        $icon_class = 'image-icon';
                    } elseif (in_array($extension, ['txt', 'php', 'html', 'css', 'js'])) {
                        $icon = '📝';
                        $icon_class = 'text-icon';
                    }
                    ?>
                    <div class="file-item">
                        <input type="checkbox" class="file-checkbox" name="selected_files[]" value="<?php echo htmlspecialchars($file); ?>" onchange="updateSelection()">
                        <div class="file-icon <?php echo $icon_class; ?>"><?php echo $icon; ?></div>
                        <div class="file-name"><?php echo htmlspecialchars($file); ?></div>
                        <div class="file-info"><?php echo formatFileSize($filesize); ?></div>
                        <div class="file-actions">
                            <a href="?download=<?php echo urlencode($file); ?>&dir=<?php echo urlencode($current_dir); ?>">
                                <button class="btn-download">Download</button>
                            </a>
                            <?php if (isEditableFile($file)): ?>
                                <button class="btn-edit" onclick="editFile('<?php echo htmlspecialchars($file); ?>')">Edit</button>
                            <?php endif; ?>
                            <button class="btn-rename" onclick="renameItem('<?php echo htmlspecialchars($file); ?>')">Rename</button>
                            <button class="btn-delete" onclick="deleteItem('<?php echo htmlspecialchars($file); ?>')">Delete</button>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="copy_filename" value="<?php echo htmlspecialchars($file); ?>">
                                <button type="submit" name="copy_file" class="btn-copy">Copy</button>
                            </form>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="cut_filename" value="<?php echo htmlspecialchars($file); ?>">
                                <button type="submit" name="cut_file" class="btn-move">Move</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content" style="width: 90%; height: 80%;">
            <div class="modal-header">
                <h3>Edit File: <span id="editFileName"></span></h3>
                <button class="modal-close" onclick="closeModal('editModal')">✕</button>
            </div>
            <div id="loadingMessage" class="loading" style="display: none;">Loading file content...</div>
            <form method="post" id="editForm">
                <input type="hidden" name="filename" id="editFileNameInput">
                <textarea name="file_content" id="fileContent" placeholder="Loading..."></textarea>
                <div style="margin-top: 10px;">
                    <button type="submit" name="save_file" style="background: #27ae60; color: white; padding: 10px 20px; border: none; border-radius: 5px;">💾 Save Changes</button>
                    <button type="button" onclick="closeModal('editModal')" style="background: #95a5a6; color: white; padding: 10px 20px; border: none; border-radius: 5px; margin-left: 10px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Rename Modal -->
    <div id="renameModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Rename Item</h3>
                <button class="modal-close" onclick="closeModal('renameModal')">✕</button>
            </div>
            <form method="post">
                <input type="hidden" name="old_name" id="oldName">
                <input type="text" name="new_name" id="newName" placeholder="New Name" required style="width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px;">
                <button type="submit" name="rename_file" style="background: #f39c12; color: white; padding: 10px 20px; border: none; border-radius: 5px;">Rename</button>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">✕</button>
            </div>
            <p>Are you sure you want to delete this item?</p>
            <form method="post">
                <input type="hidden" name="delete_filename" id="deleteFileName">
                <button type="submit" name="delete_file" style="background: #e74c3c; color: white; padding: 10px 20px; border: none; border-radius: 5px; margin-right: 10px;">Yes, Delete</button>
                <button type="button" onclick="closeModal('deleteModal')" style="background: #95a5a6; color: white; padding: 10px 20px; border: none; border-radius: 5px;">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        let codeEditor = null;

        function getCodeMirrorMode(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            switch (ext) {
                case 'js':
                case 'json':
                    return 'javascript';
                case 'css':
                    return 'css';
                case 'html':
                    return 'htmlmixed';
                case 'php':
                    return 'php';
                case 'xml':
                    return 'xml';
                case 'sql':
                    return 'sql';
                case 'md':
                    return 'markdown';
                case 'yml':
                case 'yaml':
                    return 'yaml';
                case 'ini':
                case 'cfg':
                case 'conf':
                case 'htaccess':
                    return 'properties';
                default:
                    return null; // Plain text
            }
        }

        function editFile(filename) {
            document.getElementById('editFileName').textContent = filename;
            document.getElementById('editFileNameInput').value = filename;
            
            // Show modal first
            showModal('editModal');
            
            // Show loading message
            document.getElementById('loadingMessage').style.display = 'block';
            document.getElementById('fileContent').value = 'Loading...';
            
            // Create URL for AJAX request
            const url = window.location.pathname + '?get_file_content=1&file=' + encodeURIComponent(filename) + '&dir=' + encodeURIComponent('<?php echo $current_dir; ?>');
            
            // Load file content via AJAX
            fetch(url, {
                method: 'GET',
                cache: 'no-cache'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                }
                return response.text();
            })
            .then(content => {
                document.getElementById('loadingMessage').style.display = 'none';
                document.getElementById('fileContent').value = content;
                
                // Initialize CodeMirror
                const mode = getCodeMirrorMode(filename);
                codeEditor = CodeMirror.fromTextArea(document.getElementById('fileContent'), {
                    lineNumbers: true,
                    mode: mode,
                    theme: 'default',
                    indentUnit: 4,
                    indentWithTabs: true,
                    matchBrackets: true,
                    autoCloseBrackets: true
                });
                
                // Focus on editor
                codeEditor.focus();
            })
            .catch(error => {
                document.getElementById('loadingMessage').style.display = 'none';
                document.getElementById('fileContent').value = 'Error loading file: ' + error.message;
                console.error('Error loading file:', error);
            });
        }
        
        function renameItem(name) {
            document.getElementById('oldName').value = name;
            document.getElementById('newName').value = name;
            showModal('renameModal');
            document.getElementById('newName').focus();
        }
        
        function deleteItem(name) {
            document.getElementById('deleteFileName').value = name;
            showModal('deleteModal');
        }
        
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            // Clean up CodeMirror instance if closing edit modal
            if (modalId === 'editModal' && codeEditor) {
                codeEditor.toTextArea();
                codeEditor = null;
            }
        }
        
        // NEW: Selection functions
        function selectAll() {
            const checkboxes = document.querySelectorAll('.file-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = true;
                cb.closest('.file-item').classList.add('selected');
            });
            updateSelection();
        }
        
        function selectNone() {
            const checkboxes = document.querySelectorAll('.file-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = false;
                cb.closest('.file-item').classList.remove('selected');
            });
            updateSelection();
        }
        
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.file-checkbox');
            const selectedCount = document.querySelectorAll('.file-checkbox:checked').length;
            
            // Update visual selection
            checkboxes.forEach(cb => {
                if (cb.checked) {
                    cb.closest('.file-item').classList.add('selected');
                } else {
                    cb.closest('.file-item').classList.remove('selected');
                }
            });
            
            // Update counter
            document.getElementById('selectedCount').textContent = selectedCount + ' selected';
            
            // Add selected items to bulk form
            const bulkForm = document.getElementById('bulkForm');
            
            // Remove existing hidden inputs
            const existingInputs = bulkForm.querySelectorAll('input[name="selected_files[]"]');
            existingInputs.forEach(input => input.remove());
            
            // Add selected items as hidden inputs
            const selectedCheckboxes = document.querySelectorAll('.file-checkbox:checked');
            selectedCheckboxes.forEach(cb => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'selected_files[]';
                hiddenInput.value = cb.value;
                bulkForm.appendChild(hiddenInput);
            });
        }
        
        function validateSelection(action) {
            const selectedCount = document.querySelectorAll('.file-checkbox:checked').length;
            if (selectedCount === 0) {
                alert('Please select at least one file or folder to ' + action + '.');
                return false;
            }
            return true;
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            }
        }
        
        // Handle form submit to save CodeMirror content back to textarea
        const editForm = document.getElementById('editForm');
        editForm.addEventListener('submit', function(e) {
            if (codeEditor) {
                codeEditor.save();
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modals
            if (e.key === 'Escape') {
                const modals = document.getElementsByClassName('modal');
                for (let modal of modals) {
                    if (modal.style.display === 'block') {
                        closeModal(modal.id);
                    }
                }
            }
            
            // Ctrl+A to select all files
            if (e.ctrlKey && e.key === 'a' && !document.querySelector('.modal[style*="block"]')) {
                e.preventDefault();
                selectAll();
            }
            
            // Ctrl+S to save file in editor
            if (e.ctrlKey && e.key === 's') {
                const editModal = document.getElementById('editModal');
                if (editModal.style.display === 'block') {
                    e.preventDefault();
                    if (codeEditor) {
                        codeEditor.save();
                    }
                    editForm.submit();
                }
            }
        });
        
        // Initialize selection on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelection();
        });
    </script>
</body>
</html>