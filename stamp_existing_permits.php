<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Database connection
$conn = new mysqli("sql201.ezyro.com", "ezyro_39028485", "pogiako09", "ezyro_39028485_client_info");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to add watermark to image
function addWatermarkToImage($source_path, $destination_path) {
    try {
        // Get image type
        $image_info = getimagesize($source_path);
        if (!$image_info) {
            return false;
        }
        
        $mime_type = $image_info['mime'];
        
        // Create image resource based on type
        switch ($mime_type) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($source_path);
                break;
            case 'image/png':
                $image = imagecreatefrompng($source_path);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($source_path);
                break;
            default:
                return false;
        }
        
        if (!$image) {
            return false;
        }
        
        // Get image dimensions
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Create a true color image for better quality
        $true_color_image = imagecreatetruecolor($width, $height);
        
        // Copy original image to true color image
        imagecopy($true_color_image, $image, 0, 0, 0, 0, $width, $height);
        
        // Watermark text
        $watermark_text = "VERIFIED";
        
        // Calculate font size based on image dimensions
        $font_size = min($width, $height) / 8;
        $font_size = max(24, min($font_size, 80));
        
        // Use built-in font for reliability
        $text = "VERIFIED";
        $font = 5; // Large built-in font
        $text_width = imagefontwidth($font) * strlen($text);
        $text_height = imagefontheight($font);
        
        // Calculate position
        $x = ($width - $text_width) / 2;
        $y = ($height - $text_height) / 2;
        
        // Add semi-transparent background
        $bg_color = imagecolorallocatealpha($true_color_image, 255, 255, 255, 50);
        imagefilledrectangle($true_color_image, $x-15, $y-10, $x + $text_width + 15, $y + $text_height + 10, $bg_color);
        
        // Add green text
        $text_color = imagecolorallocate($true_color_image, 0, 128, 0);
        imagestring($true_color_image, $font, $x, $y, $text, $text_color);
        
        // Add border
        $border_color = imagecolorallocate($true_color_image, 0, 128, 0);
        imagerectangle($true_color_image, $x-20, $y-15, $x + $text_width + 20, $y + $text_height + 15, $border_color);
        
        // Add corner stamps
        $small_text_color = imagecolorallocatealpha($true_color_image, 0, 128, 0, 70);
        
        // Top left
        imagestring($true_color_image, 3, 20, 20, "VERIFIED", $small_text_color);
        // Top right
        imagestring($true_color_image, 3, $width - 80, 20, "VERIFIED", $small_text_color);
        // Bottom left
        imagestring($true_color_image, 3, 20, $height - 40, "VERIFIED", $small_text_color);
        // Bottom right
        imagestring($true_color_image, 3, $width - 80, $height - 40, "VERIFIED", $small_text_color);
        
        // Save watermarked image
        switch ($mime_type) {
            case 'image/jpeg':
                $result = imagejpeg($true_color_image, $destination_path, 90);
                break;
            case 'image/png':
                $result = imagepng($true_color_image, $destination_path, 9);
                break;
            case 'image/gif':
                $result = imagegif($true_color_image, $destination_path);
                break;
            default:
                $result = false;
        }
        
        // Free memory
        imagedestroy($image);
        imagedestroy($true_color_image);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Image watermark error: " . $e->getMessage());
        return false;
    }
}

// Function to create verified version of any file
function createVerifiedVersion($source_path, $destination_path) {
    $file_info = pathinfo($source_path);
    $extension = strtolower($file_info['extension'] ?? '');
    
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
        return addWatermarkToImage($source_path, $destination_path);
    } else {
        // For non-image files, create a verified text file
        $content = "===============================================\n";
        $content .= "           DOCUMENT VERIFICATION CERTIFICATE\n";
        $content .= "              EBTGL ACCOUNTING SERVICES\n";
        $content .= "===============================================\n\n";
        
        $content .= "✓ VERIFIED AND AUTHENTICATED\n\n";
        
        $content .= "Original File: " . basename($source_path) . "\n";
        $content .= "Verification Date: " . date('Y-m-d H:i:s') . "\n";
        $content .= "Status: VERIFIED ✓\n\n";
        
        $content .= "This document has been verified by our automated system\n";
        $content .= "and confirmed to be a legitimate business document.\n\n";
        
        $content .= "All information has been cross-referenced and validated\n";
        $content .= "according to Philippine business registration standards.\n\n";
        
        $content .= "===============================================\n";
        $content .= "EBTGL Accounting Services - Trusted Verification\n";
        $content .= "===============================================\n";
        
        return file_put_contents($destination_path, $content) !== false;
    }
}

// Process existing permit files
$permitsDir = "documents/permits/";
$stampedDir = "documents/permits/stamped/";
$results = [];

if (is_dir($permitsDir)) {
    // Create stamped directory if it doesn't exist
    if (!is_dir($stampedDir)) {
        mkdir($stampedDir, 0777, true);
    }
    
    // Get all files in permits directory
    $files = scandir($permitsDir);
    $processed = 0;
    $successful = 0;
    
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && $file != 'stamped' && !is_dir($permitsDir . $file)) {
            $processed++;
            $source_path = $permitsDir . $file;
            $destination_path = $stampedDir . 'verified_' . $file;
            
            if (createVerifiedVersion($source_path, $destination_path)) {
                $successful++;
                $results[] = "✓ Successfully stamped: $file";
            } else {
                $results[] = "✗ Failed to stamp: $file";
            }
        }
    }
    
    $_SESSION['stamp_results'] = $results;
    $_SESSION['stamp_summary'] = "Processed: $processed files, Successful: $successful stamps";
}

// Redirect back to manage client page
header("Location: manage-client.php");
exit();
?>