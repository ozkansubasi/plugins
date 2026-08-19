<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   Copyright (C) 2024 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\WebServices\Numistr\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Exception;

/**
 * Recognition Helper - Handles coin recognition requests and quota management
 *
 * @since  1.2.0
 */
class RecognitionHelper
{
    /**
     * AI service base URL (configured in plugin params)
     *
     * @var    string
     * @since  1.2.0
     */
    protected static $aiServiceUrl = 'http://localhost:8000';

    /**
     * Free tier scan limit per month
     *
     * @var    integer
     * @since  1.2.0
     */
    const FREE_MONTHLY_LIMIT = 10;

    /**
     * Recognize coin by sending image to AI service
     *
     * @param   string  $obversePath   Path to obverse image
     * @param   string  $reversePath   Path to reverse image (optional)
     * @param   int     $userId        User ID for logging
     *
     * @return  array   Recognition results
     *
     * @since   1.2.0
     * @throws  Exception
     */
    public static function recognizeCoin($obversePath, $reversePath = null, $userId = null)
    {
        // Get AI service URL from plugin config
        $plugin = \JPluginHelper::getPlugin('webservices', 'numistr');
        $params = new \Joomla\Registry\Registry($plugin->params);
        $aiServiceUrl = $params->get('ai_service_url', self::$aiServiceUrl);

        // Prepare multipart/form-data request
        $boundary = '----WebKitFormBoundary' . md5(time());
        $postData = '';

        // Add obverse image
        $postData .= self::buildMultipartField('image', basename($obversePath), file_get_contents($obversePath), mime_content_type($obversePath), $boundary);

        // Add reverse image if provided
        if ($reversePath && file_exists($reversePath)) {
            $postData .= self::buildMultipartField('reverse', basename($reversePath), file_get_contents($reversePath), mime_content_type($reversePath), $boundary);
        }

        $postData .= "--{$boundary}--\r\n";

        // Send request to AI service
        $ch = curl_init($aiServiceUrl . '/recognize');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                "Content-Type: multipart/form-data; boundary={$boundary}",
                "Content-Length: " . strlen($postData)
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("AI service connection error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new Exception("AI service returned error: HTTP {$httpCode}");
        }

        $result = json_decode($response, true);

        if (!$result || !isset($result['matches'])) {
            throw new Exception("Invalid response from AI service");
        }

        // Log recognition request
        if ($userId) {
            self::logRecognition($userId, $result);
        }

        return $result;
    }

    /**
     * Build multipart/form-data field
     *
     * @param   string  $name      Field name
     * @param   string  $filename  File name
     * @param   string  $content   File content
     * @param   string  $mimeType  MIME type
     * @param   string  $boundary  Boundary string
     *
     * @return  string  Multipart field
     *
     * @since   1.2.0
     */
    protected static function buildMultipartField($name, $filename, $content, $mimeType, $boundary)
    {
        $data = "--{$boundary}\r\n";
        $data .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
        $data .= "Content-Type: {$mimeType}\r\n\r\n";
        $data .= $content . "\r\n";

        return $data;
    }

    /**
     * Check user's remaining scan quota
     *
     * @param   int  $userId  User ID
     *
     * @return  int  Number of scans remaining
     *
     * @since   1.2.0
     */
    public static function checkScanQuota($userId)
    {
        $db = Factory::getDbo();

        // Check if user is Pro (unlimited scans)
        if (self::isProUser($userId)) {
            return PHP_INT_MAX; // Unlimited
        }

        // Get current month's usage for free users
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__numistr_recognition_log')
            ->where('user_id = ' . (int) $userId)
            ->where('YEAR(created_at) = YEAR(CURDATE())')
            ->where('MONTH(created_at) = MONTH(CURDATE())');

        $db->setQuery($query);
        $usedScans = (int) $db->loadResult();

        $remaining = max(0, self::FREE_MONTHLY_LIMIT - $usedScans);

        return $remaining;
    }

    /**
     * Consume one scan from user's quota
     *
     * @param   int  $userId  User ID
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public static function consumeScanQuota($userId)
    {
        // Quota consumption is automatic via logRecognition()
        // This method exists for explicit quota management if needed
    }

    /**
     * Get detailed quota information for user
     *
     * @param   int  $userId  User ID
     *
     * @return  array  Quota information
     *
     * @since   1.2.0
     */
    public static function getQuotaInfo($userId)
    {
        $isPro = self::isProUser($userId);

        if ($isPro) {
            return [
                'tier' => 'pro',
                'scans_remaining' => -1, // Unlimited
                'monthly_limit' => -1,
                'used_this_month' => 0,
                'reset_date' => null
            ];
        }

        $db = Factory::getDbo();

        // Get usage for current month
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__numistr_recognition_log')
            ->where('user_id = ' . (int) $userId)
            ->where('YEAR(created_at) = YEAR(CURDATE())')
            ->where('MONTH(created_at) = MONTH(CURDATE())');

        $db->setQuery($query);
        $usedScans = (int) $db->loadResult();

        // Calculate reset date (first day of next month)
        $resetDate = date('Y-m-01', strtotime('first day of next month'));

        return [
            'tier' => 'free',
            'scans_remaining' => max(0, self::FREE_MONTHLY_LIMIT - $usedScans),
            'monthly_limit' => self::FREE_MONTHLY_LIMIT,
            'used_this_month' => $usedScans,
            'reset_date' => $resetDate
        ];
    }

    /**
     * Check if user has Pro subscription
     *
     * @param   int  $userId  User ID
     *
     * @return  bool  True if Pro user
     *
     * @since   1.2.0
     */
    protected static function isProUser($userId)
    {
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

        // Check if user is in Pro group (group ID 9)
        return in_array(9, $user->getAuthorisedGroups());
    }

    /**
     * Log recognition request to database
     *
     * @param   int    $userId  User ID
     * @param   array  $result  Recognition result
     *
     * @return  void
     *
     * @since   1.2.0
     */
    protected static function logRecognition($userId, $result)
    {
        $db = Factory::getDbo();

        $log = (object) [
            'user_id' => $userId,
            'confidence' => $result['confidence'] ?? 0,
            'method' => $result['method'] ?? 'primary',
            'processing_time_ms' => $result['processing_time_ms'] ?? 0,
            'top_match_article_id' => $result['matches'][0]['article_id'] ?? null,
            'result_json' => json_encode($result),
            'created_at' => Factory::getDate()->toSql()
        ];

        try {
            $db->insertObject('#__numistr_recognition_log', $log);
        } catch (Exception $e) {
            // Log error but don't fail the recognition
            Factory::getApplication()->enqueueMessage('Failed to log recognition: ' . $e->getMessage(), 'warning');
        }
    }
}
