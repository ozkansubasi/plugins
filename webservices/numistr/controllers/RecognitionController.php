<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   (C) 2025 NumisTR
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Recognition Controller for coin identification
 *
 * @since  1.4.0
 */
class RecognitionController
{
    /**
     * Execute recognition request
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public static function recognize()
    {
        $startTime = microtime(true);

        try {
            // 1. Authenticate user using AuthHelper (supports Auth0 JWT + Joomla tokens)
            $configPath = __DIR__ . '/../config/constants.php';
            $config = file_exists($configPath) ? include $configPath : [];

            $authHelper = new NumisTRAuthHelper($config);
            $user = $authHelper->authenticateUser();

            if (!$user || $user->guest || $user->id <= 0) {
                self::sendError('Authentication required', 401);
                return;
            }

            // 2. Check quota (aynı config → AuthHelper ile aynı PRO_GROUP_ID)
            $quotaHelper = new QuotaHelper(null, $config);
            $quotaStatus = $quotaHelper->canScan($user);

            if (!$quotaStatus['allowed']) {
                self::sendQuotaExceeded($quotaStatus);
                return;
            }

            // 3. Validate image upload
            if (empty($_FILES['image'])) {
                self::sendError('No image file uploaded', 400);
                return;
            }

            $imageFile = $_FILES['image'];
            $reverseFile = isset($_FILES['reverse']) ? $_FILES['reverse'] : null;

            $aiHelper = new AiServiceHelper();

            // Validate obverse image
            try {
                $aiHelper->validateImage($imageFile);
            } catch (InvalidArgumentException $e) {
                self::sendError($e->getMessage(), 400);
                return;
            }

            // Validate reverse image if provided
            if ($reverseFile && $reverseFile['error'] !== UPLOAD_ERR_NO_FILE) {
                try {
                    $aiHelper->validateImage($reverseFile);
                } catch (InvalidArgumentException $e) {
                    self::sendError('Reverse image: ' . $e->getMessage(), 400);
                    return;
                }
            }

            // 4. Calculate image hash (for logging/caching)
            $imageHash = $aiHelper->calculateImageHash($imageFile['tmp_name']);

            // 5. Send to AI service
            try {
                $reversePath = ($reverseFile && $reverseFile['error'] === UPLOAD_ERR_OK)
                    ? $reverseFile['tmp_name']
                    : null;

                // Faz B: isteğe bağlı nitelikler (metal/ağırlık/çap) — AI servise iletilir
                $attrs = array(
                    'metal'       => isset($_POST['metal']) ? substr(trim((string) $_POST['metal']), 0, 20) : null,
                    'weight_g'    => isset($_POST['weight_g']) ? substr(trim((string) $_POST['weight_g']), 0, 10) : null,
                    'diameter_mm' => isset($_POST['diameter_mm']) ? substr(trim((string) $_POST['diameter_mm']), 0, 10) : null,
                );

                $aiResults = $aiHelper->recognize($imageFile['tmp_name'], $reversePath, $attrs);

            } catch (RuntimeException $e) {
                // AI service error - don't consume quota
                self::sendError($e->getMessage(), 503);

                // Log the failed request
                self::logRecognitionRequest(
                    $user->id,
                    $imageHash,
                    null,
                    null,
                    null,
                    $e->getMessage()
                );

                return;
            }

            // 6. Consume quota (only after successful recognition)
            try {
                $quotaHelper->consumeScan($user);
            } catch (RuntimeException $e) {
                // This shouldn't happen (we already checked), but handle it
                self::sendError('Quota consumption failed', 500);
                return;
            }

            // 7. Get updated quota status
            $updatedQuotaStatus = $quotaHelper->canScan($user);

            // 8. Format results
            $formattedResults = $aiHelper->formatResults($aiResults);

            // 9. Log the request
            $topMatch = isset($aiResults['matches'][0]) ? $aiResults['matches'][0] : null;
            $topMatchId = $topMatch ? $topMatch['article_id'] : null;
            $topMatchConfidence = $topMatch ? $topMatch['confidence'] : null;
            $processingTime = isset($aiResults['processing_time_ms']) ? $aiResults['processing_time_ms'] : 0;

            self::logRecognitionRequest(
                $user->id,
                $imageHash,
                $processingTime,
                $topMatchId,
                $topMatchConfidence,
                null,
                $aiResults
            );

            // 10. Generate request ID for tracking
            $requestId = 'req_' . substr($imageHash, 0, 16);

            // 11. Calculate total processing time
            $totalTime = round((microtime(true) - $startTime) * 1000);

            // 12. Send success response
            $response = array(
                'success' => true,
                'data' => $formattedResults,
                'quota' => array(
                    'used' => $updatedQuotaStatus['used'],
                    'remaining' => $updatedQuotaStatus['remaining'],
                    'limit' => $updatedQuotaStatus['limit'],
                    'tier' => $updatedQuotaStatus['tier'],
                    'reset_date' => $updatedQuotaStatus['reset_date']
                ),
                'request_id' => $requestId,
                'total_time_ms' => $totalTime
            );

            header('Content-Type: application/json');
            echo json_encode($response);
            jexit();

        } catch (Exception $e) {
            self::sendError('Internal server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Send quota exceeded error response
     *
     * @param   array  $quotaStatus  Quota status
     *
     * @return  void
     */
    private static function sendQuotaExceeded($quotaStatus)
    {
        $response = array(
            'success' => false,
            'error' => array(
                'code' => 'QUOTA_EXCEEDED',
                'message' => 'Monthly scan limit reached. Upgrade to Pro for unlimited scans.',
                'quota' => array(
                    'used' => $quotaStatus['used'],
                    'limit' => $quotaStatus['limit'],
                    'tier' => $quotaStatus['tier'],
                    'reset_date' => $quotaStatus['reset_date']
                )
            )
        );

        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode($response);
        jexit();
    }

    /**
     * Send error response
     *
     * @param   string  $message  Error message
     * @param   int     $code     HTTP status code
     *
     * @return  void
     */
    private static function sendError($message, $code = 400)
    {
        $response = array(
            'success' => false,
            'error' => array(
                'code' => 'ERROR',
                'message' => $message
            )
        );

        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($response);
        jexit();
    }

    /**
     * Log recognition request to database
     *
     * @param   int     $userId              User ID
     * @param   string  $imageHash           Image SHA256 hash
     * @param   int     $processingTime      Processing time in ms
     * @param   int     $topMatchId          Top match article ID
     * @param   float   $topMatchConfidence  Top match confidence
     * @param   string  $errorMessage        Error message if failed
     * @param   array   $resultJson          Full results JSON
     *
     * @return  void
     */
    private static function logRecognitionRequest(
        $userId,
        $imageHash,
        $processingTime = null,
        $topMatchId = null,
        $topMatchConfidence = null,
        $errorMessage = null,
        $resultJson = null
    ) {
        try {
            $db = Factory::getDbo();
            $query = $db->getQuery(true);

            $now = Factory::getDate()->toSql();
            $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
            $resultJsonString = $resultJson ? json_encode($resultJson) : null;

            $query->insert($db->quoteName('numistr_recognition_requests'))
                ->columns(array(
                    $db->quoteName('user_id'),
                    $db->quoteName('image_hash'),
                    $db->quoteName('request_timestamp'),
                    $db->quoteName('processing_time_ms'),
                    $db->quoteName('top_match_id'),
                    $db->quoteName('top_match_confidence'),
                    $db->quoteName('result_json'),
                    $db->quoteName('ip_address'),
                    $db->quoteName('user_agent'),
                    $db->quoteName('error_message')
                ))
                ->values(
                    (int) $userId . ', ' .
                    $db->quote($imageHash) . ', ' .
                    $db->quote($now) . ', ' .
                    ($processingTime ? (int) $processingTime : 'NULL') . ', ' .
                    ($topMatchId ? (int) $topMatchId : 'NULL') . ', ' .
                    ($topMatchConfidence ? (float) $topMatchConfidence : 'NULL') . ', ' .
                    ($resultJsonString ? $db->quote($resultJsonString) : 'NULL') . ', ' .
                    ($ipAddress ? $db->quote($ipAddress) : 'NULL') . ', ' .
                    ($userAgent ? $db->quote($userAgent) : 'NULL') . ', ' .
                    ($errorMessage ? $db->quote($errorMessage) : 'NULL')
                );

            $db->setQuery($query);
            $db->execute();

        } catch (Exception $e) {
            // Log error but don't fail the request
            JLog::add('Failed to log recognition request: ' . $e->getMessage(), JLog::WARNING, 'com_numistr');
        }
    }
}
