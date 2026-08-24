<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   (C) 2025 NumisTR
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;

/**
 * Helper class for AI Service communication
 *
 * @since  1.4.0
 */
class AiServiceHelper
{
    /**
     * AI Service base URL
     */
    const AI_SERVICE_URL = 'https://ai.numistr.org';

    /**
     * Request timeout in seconds
     */
    const TIMEOUT = 30;

    /**
     * Maximum file size (10MB)
     */
    const MAX_FILE_SIZE = 10485760; // 10 * 1024 * 1024

    /**
     * Validate uploaded image file
     *
     * @param   array  $file  $_FILES array element
     *
     * @return  bool  Valid
     *
     * @throws  InvalidArgumentException  If validation fails
     */
    public function validateImage($file)
    {
        // Check upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('File upload error: ' . $file['error']);
        }

        // Check file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('File too large. Maximum size: 10MB');
        }

        // Check MIME type
        $allowedTypes = array('image/jpeg', 'image/png');
        if (!in_array($file['type'], $allowedTypes)) {
            throw new InvalidArgumentException('Invalid file type. Allowed: JPEG, PNG');
        }

        // Validate actual image content
        $imageInfo = @getimagesize($file['tmp_name']);

        if (!$imageInfo) {
            throw new InvalidArgumentException('Invalid image file');
        }

        // Additional MIME type check from image data
        $actualMimeType = $imageInfo['mime'];

        if (!in_array($actualMimeType, $allowedTypes)) {
            throw new InvalidArgumentException('Image MIME type mismatch');
        }

        return true;
    }

    /**
     * Calculate SHA256 hash of image file
     *
     * @param   string  $filePath  Path to image file
     *
     * @return  string  SHA256 hash
     */
    public function calculateImageHash($filePath)
    {
        return hash_file('sha256', $filePath);
    }

    /**
     * Send recognition request to AI service
     *
     * @param   string  $imagePath      Path to obverse image
     * @param   string  $reversePath    Path to reverse image (optional)
     *
     * @return  array  Recognition results
     *
     * @throws  RuntimeException  If AI service request fails
     */
    public function recognize($imagePath, $reversePath = null, $attrs = array())
    {
        $startTime = microtime(true);

        try {
            // Prepare multipart form data using cURL
            $ch = curl_init();

            $postFields = array(
                'image' => new CURLFile($imagePath, 'image/jpeg', 'obverse.jpg')
            );

            if ($reversePath && file_exists($reversePath)) {
                $postFields['reverse'] = new CURLFile($reversePath, 'image/jpeg', 'reverse.jpg');
            }

            // Faz B (2026-08-24): kolleksiyoner isteğe bağlı nitelikleri (metal/ağırlık/çap)
            foreach (array('metal', 'weight_g', 'diameter_mm') as $k) {
                if (isset($attrs[$k]) && $attrs[$k] !== '' && $attrs[$k] !== null) {
                    $postFields[$k] = (string) $attrs[$k];
                }
            }

            curl_setopt_array($ch, array(
                CURLOPT_URL => self::AI_SERVICE_URL . '/recognize',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_HTTPHEADER => array('Accept: application/json'),
                CURLOPT_SSL_VERIFYPEER => false,  // Self-signed cert için
                CURLOPT_SSL_VERIFYHOST => false   // Self-signed cert için
            ));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            // Measure processing time
            $processingTime = round((microtime(true) - $startTime) * 1000);

            // Check for cURL errors
            if ($error) {
                Log::add('AI Service cURL error: ' . $error, Log::ERROR, 'com_numistr');
                throw new RuntimeException('AI Service request failed');
            }

            // Check response status
            if ($httpCode !== 200) {
                Log::add('AI Service error: HTTP ' . $httpCode . ' - ' . $response, Log::ERROR, 'com_numistr');
                throw new RuntimeException('AI Service returned error: ' . $httpCode);
            }

            // Parse JSON response
            $result = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid JSON response from AI Service');
            }

            // Add processing time to result
            $result['processing_time_ms'] = $processingTime;

            return $result;

        } catch (Exception $e) {
            Log::add('AI Service request failed: ' . $e->getMessage(), Log::ERROR, 'com_numistr');
            throw new RuntimeException('Recognition service temporarily unavailable', 503);
        }
    }

    /**
     * Check AI Service health
     *
     * @return  array  Health status
     */
    public function checkHealth()
    {
        try {
            $ch = curl_init();

            curl_setopt_array($ch, array(
                CURLOPT_URL => self::AI_SERVICE_URL . '/health',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,  // Self-signed cert için
                CURLOPT_SSL_VERIFYHOST => false   // Self-signed cert için
            ));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return array(
                    'status' => 'unhealthy',
                    'error' => 'HTTP ' . $httpCode
                );
            }

            $health = json_decode($response, true);

            return $health ? $health : array('status' => 'unknown');

        } catch (Exception $e) {
            return array(
                'status' => 'unreachable',
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Format recognition results for API response
     *
     * @param   array  $aiResults  Raw AI service results
     *
     * @return  array  Formatted results with thumbnail URLs
     */
    public function formatResults($aiResults)
    {
        $matches = isset($aiResults['matches']) ? $aiResults['matches'] : array();

        // Add thumbnail URLs to matches
        foreach ($matches as &$match) {
            if (isset($match['image_id'])) {
                $match['thumbnail_url'] = $this->getThumbnailUrl($match['image_id']);
            }
        }

        return array(
            'matches' => $matches,
            'confidence' => isset($aiResults['confidence']) ? $aiResults['confidence'] : 0,
            'method' => isset($aiResults['method']) ? $aiResults['method'] : 'unknown',
            'processing_time_ms' => isset($aiResults['processing_time_ms']) ? $aiResults['processing_time_ms'] : 0,
            // Faz A (2026-08-24): nedenli fallback + kalite metrikleri app'e geçsin
            'no_match' => isset($aiResults['no_match']) ? (bool) $aiResults['no_match'] : empty($matches),
            'no_match_reason' => isset($aiResults['no_match_reason']) ? $aiResults['no_match_reason'] : null,
            'quality' => isset($aiResults['quality']) ? $aiResults['quality'] : null
        );
    }

    /**
     * Get thumbnail URL for image ID
     *
     * @param   int  $imageId  Image ID
     *
     * @return  string  Thumbnail URL
     */
    private function getThumbnailUrl($imageId)
    {
        return 'https://www.numistr.org/index.php?option=com_numistr&view=gorsel&id=' . $imageId . '&wm=0';
    }
}
