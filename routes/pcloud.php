<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../config/pcloud.php';

// pCloud API Configuration
$accessToken = "kqNT7Z8BpwhA0d4MFZVgju0kZbR12PpsX93VWhpTOL5i4jVefcDdX";
$baseUrl = "https://api.pcloud.com";

class PCloudAPI {
    private $accessToken;
    private $baseUrl;
    
    public function __construct($accessToken, $baseUrl) {
        $this->accessToken = $accessToken;
        $this->baseUrl = $baseUrl;
    }
    
    // Make API request
    private function makeRequest($endpoint, $params = [], $method = 'GET') {
        $params['access_token'] = $this->accessToken;
        $url = $this->baseUrl . $endpoint;
        
        if ($method == 'GET') {
            $url .= '?' . http_build_query($params);
            $response = file_get_contents($url);
        } else {
            $options = [
                'http' => [
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query($params)
                ]
            ];
            $context = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
        }
        
        return json_decode($response, true);
    }
    
    // List folder contents
    public function listFolder($folderId = 0) {
        return $this->makeRequest('/listfolder', [
            'folderid' => $folderId,
            'recursive' => 1
        ]);
    }
    
    // Upload file
    public function uploadFile($fileData, $folderId = 0) {
        $boundary = uniqid();
        $data = '';
        
        // Add file data
        $data .= "--$boundary\r\n";
        $data .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileData['name']}\"\r\n";
        $data .= "Content-Type: {$fileData['type']}\r\n\r\n";
        $data .= $fileData['content'] . "\r\n";
        
        // Add folder ID
        $data .= "--$boundary\r\n";
        $data .= "Content-Disposition: form-data; name=\"folderid\"\r\n\r\n";
        $data .= $folderId . "\r\n";
        
        // Add access token
        $data .= "--$boundary\r\n";
        $data .= "Content-Disposition: form-data; name=\"access_token\"\r\n\r\n";
        $data .= $this->accessToken . "\r\n";
        $data .= "--$boundary--\r\n";
        
        $options = [
            'http' => [
                'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\n",
                'method' => 'POST',
                'content' => $data
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($this->baseUrl . '/uploadfile', false, $context);
        
        return json_decode($response, true);
    }
    
    // Create folder
    public function createFolder($name, $parentFolderId = 0) {
        return $this->makeRequest('/createfolder', [
            'name' => $name,
            'folderid' => $parentFolderId
        ]);
    }
    
    // Delete file
    public function deleteFile($fileId) {
        return $this->makeRequest('/deletefile', [
            'fileid' => $fileId
        ]);
    }
    
    // Get file link
    public function getFileLink($fileId) {
        return $this->makeRequest('/getfilelink', [
            'fileid' => $fileId
        ]);
    }
    
    // Get thumbnail
    public function getThumbnail($fileId, $size = '256x256') {
        return $this->baseUrl . '/getthumb?fileid=' . $fileId . '&access_token=' . $this->accessToken . '&size=' . $size . '&type=auto';
    }
    
    // Get video link
    public function getVideoLink($fileId) {
        return $this->makeRequest('/getvideolink', [
            'fileid' => $fileId,
            'stream' => 1
        ]);
    }
}

// Initialize pCloud API
$pcloud = new PCloudAPI($accessToken, $baseUrl);

// Get request method and endpoint
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

// API endpoint routing
$endpoint = isset($pathParts[3]) ? $pathParts[3] : '';

try {
    switch ($endpoint) {
        case 'list':
            $folderId = isset($_GET['folderid']) ? intval($_GET['folderid']) : 0;
            $result = $pcloud->listFolder($folderId);
            
            if ($result['result'] === 0) {
                // Flatten files recursively
                $files = [];
                flattenFiles($result['metadata']['contents'], $files);
                
                // Categorize files
                $categorized = [
                    'images' => [],
                    'videos' => [],
                    'others' => []
                ];
                
                foreach ($files as $file) {
                    if (!$file['isfolder']) {
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        
                        if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'])) {
                            $categorized['images'][] = $file;
                        } elseif (in_array($ext, ['mp4','mov','avi','webm','mkv','wmv','flv','m4v'])) {
                            $categorized['videos'][] = $file;
                        } else {
                            $categorized['others'][] = $file;
                        }
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $categorized
                ]);
            } else {
                throw new Exception($result['error'] ?? 'Failed to list files');
            }
            break;
            
        case 'upload':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            if (!isset($_FILES['file'])) {
                throw new Exception('No file uploaded');
            }
            
            $folderId = isset($_POST['folderid']) ? intval($_POST['folderid']) : 0;
            $file = $_FILES['file'];
            
            // Read file content
            $fileContent = file_get_contents($file['tmp_name']);
            
            $fileData = [
                'name' => $file['name'],
                'type' => $file['type'],
                'content' => $fileContent
            ];
            
            $result = $pcloud->uploadFile($fileData, $folderId);
            
            if ($result['result'] === 0) {
                echo json_encode([
                    'success' => true,
                    'data' => $result['metadata']
                ]);
            } else {
                throw new Exception($result['error'] ?? 'Upload failed');
            }
            break;
            
        case 'create-folder':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            $parentFolderId = $input['parentFolderId'] ?? 0;
            
            if (empty($name)) {
                throw new Exception('Folder name is required');
            }
            
            $result = $pcloud->createFolder($name, $parentFolderId);
            
            if ($result['result'] === 0) {
                echo json_encode([
                    'success' => true,
                    'data' => $result['metadata']
                ]);
            } else {
                throw new Exception($result['error'] ?? 'Failed to create folder');
            }
            break;
            
        case 'delete':
            if ($method !== 'DELETE') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $fileId = $input['fileId'] ?? 0;
            
            if (!$fileId) {
                throw new Exception('File ID is required');
            }
            
            $result = $pcloud->deleteFile($fileId);
            
            if ($result['result'] === 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'File deleted successfully'
                ]);
            } else {
                throw new Exception($result['error'] ?? 'Failed to delete file');
            }
            break;
            
        case 'download':
            $fileId = isset($_GET['fileid']) ? intval($_GET['fileid']) : 0;
            $fileName = isset($_GET['name']) ? $_GET['name'] : 'download';
            
            if (!$fileId) {
                throw new Exception('File ID is required');
            }
            
            $result = $pcloud->getFileLink($fileId);
            
            if ($result['result'] === 0) {
                $downloadUrl = $result['metadata']['link'];
                header('Location: ' . $downloadUrl);
                exit;
            } else {
                throw new Exception($result['error'] ?? 'Failed to get download link');
            }
            break;
            
        case 'thumbnail':
            $fileId = isset($_GET['fileid']) ? intval($_GET['fileid']) : 0;
            $size = isset($_GET['size']) ? $_GET['size'] : '256x256';
            
            if (!$fileId) {
                throw new Exception('File ID is required');
            }
            
            $thumbnailUrl = $pcloud->getThumbnail($fileId, $size);
            header('Location: ' . $thumbnailUrl);
            exit;
            break;
            
        case 'video':
            $fileId = isset($_GET['fileid']) ? intval($_GET['fileid']) : 0;
            
            if (!$fileId) {
                throw new Exception('File ID is required');
            }
            
            $result = $pcloud->getVideoLink($fileId);
            
            if ($result['result'] === 0) {
                $videoUrl = $result['metadata']['link'];
                header('Location: ' . $videoUrl);
                exit;
            } else {
                throw new Exception($result['error'] ?? 'Failed to get video link');
            }
            break;
            
        default:
            throw new Exception('Endpoint not found');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Helper function to flatten files recursively
function flattenFiles($items, &$output) {
    foreach ($items as $item) {
        if (!$item['isfolder']) {
            $output[] = $item;
        } elseif (isset($item['contents'])) {
            flattenFiles($item['contents'], $output);
        }
    }
}

?>
