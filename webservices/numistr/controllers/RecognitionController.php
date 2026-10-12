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

            $imageFile   = isset($_FILES['image']) ? $_FILES['image'] : null;
            $reverseFile = isset($_FILES['reverse']) ? $_FILES['reverse'] : null;
            $attrs       = array(
                'metal'       => isset($_POST['metal']) ? substr(trim((string) $_POST['metal']), 0, 20) : null,
                'weight_g'    => isset($_POST['weight_g']) ? substr(trim((string) $_POST['weight_g']), 0, 10) : null,
                'diameter_mm' => isset($_POST['diameter_mm']) ? substr(trim((string) $_POST['diameter_mm']), 0, 10) : null,
            );

            $hasAuthHeader = !empty($_SERVER['HTTP_AUTHORIZATION']);
            $authHelper    = new NumisTRAuthHelper($config);
            $user          = $hasAuthHeader ? $authHelper->authenticateUser() : null;

            if ($user && !$user->guest && $user->id > 0) {
                $result = self::runForUser($user, $imageFile, $reverseFile, $attrs, $config);
            } else {
                // M17: belirtec HIC gelmediyse ve kurulum kimligi varsa deneme yolu. Belirtec
                // gelmis ama gecersizse 401 kalir — istemcinin belirtec yenileme akisi 401'e
                // dayanir; suresi dolmus oturum sessizce denemeye dusmemeli.
                $deviceHash = ($hasAuthHeader || !class_exists('AnonTrialHelper'))
                    ? null
                    : AnonTrialHelper::deviceHash(isset($_SERVER['HTTP_X_DEVICE_ID']) ? $_SERVER['HTTP_X_DEVICE_ID'] : '');

                if ($deviceHash === null) {
                    // 1.19.0: e-postası doğrulanmamış hesap ayrı kodla (uygulama doğrulama panelini açar).
                    $failure = $hasAuthHeader ? $authHelper->getLastFailure() : '';
                    self::sendError('Authentication required', 401, $failure !== '' ? $failure : 'ERROR');
                    return;
                }

                $result = self::runAnonymous($deviceHash, $imageFile, $reverseFile, $attrs, $config);
            }

            if (empty($result['ok'])) {
                if (isset($result['error']['code']) && $result['error']['code'] === 'QUOTA_EXCEEDED') {
                    self::sendQuotaExceeded($result['quota']);
                    return;
                }

                $errorCode = isset($result['error']['code']) ? (string) $result['error']['code'] : 'ERROR';
                $extra     = array();

                if ($errorCode === 'RATE_LIMITED' && isset($result['rate'])) {
                    $extra['rate'] = array(
                        'scope'       => $result['rate']['scope'],
                        'limit'       => $result['rate']['limit'],
                        'retry_after' => $result['rate']['retry_after'],
                    );
                }

                if (($errorCode === 'TRIAL_EXHAUSTED' || $errorCode === 'TRIAL_UNAVAILABLE') && isset($result['quota'])) {
                    $extra['quota'] = $result['quota'];
                }

                self::sendError($result['error']['message'], (int) $result['status'], $errorCode, $extra);
                return;
            }

            $response = array(
                'success' => true,
                'data' => $result['data'],
                'quota' => $result['quota'],
                'request_id' => $result['request_id'],
                'total_time_ms' => round((microtime(true) - $startTime) * 1000)
            );

            header('Content-Type: application/json');
            header('Cache-Control: no-store'); // A21: kullaniciya / kuruluma ozel yanit
            echo json_encode($response);
            jexit();

        } catch (Exception $e) {
            self::sendError('Internal server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Paylasilan tanima cekirdegi: kota -> dogrulama -> AI servis -> kota tuketimi
     * -> bicimlendirme -> loglama. YANIT YAZMAZ, dizi dondurur.
     *
     * Iki cagiran vardir: mobil/REST ucu (self::recognize) ve AI asistan
     * (AssistantController::recognize). Mantik TEK yerde durur — ayni isin iki
     * kopyasi zamanla ayrisiyor (2026-08-28 dersi: asistan keyword_map guncellendi
     * ama cekirdek KB bayat kaldi, kullaniciya yanlis bilgi gitti).
     *
     * @param   object      $user         Giris yapmis Joomla kullanicisi
     * @param   array|null  $imageFile    $_FILES girdisi (on yuz)
     * @param   array|null  $reverseFile  $_FILES girdisi (arka yuz, istege bagli)
     * @param   array       $attrs        metal / weight_g / diameter_mm
     * @param   array|null  $config       constants.php (verilmezse yuklenir)
     *
     * @return  array  ok=true  => data, quota, request_id, raw
     *                 ok=false => status, error{code,message}, quota (kota hatasinda)
     */
    public static function runForUser($user, $imageFile, $reverseFile, array $attrs = array(), $config = null)
    {
        if ($config === null) {
            $configPath = __DIR__ . '/../config/constants.php';
            $config = file_exists($configPath) ? include $configPath : [];
        }

        // 1. Kota
        $quotaHelper = new QuotaHelper(null, $config);
        $quotaStatus = $quotaHelper->canScan($user);

        if (!$quotaStatus['allowed']) {
            return array(
                'ok' => false,
                'status' => 429,
                'error' => array(
                    'code' => 'QUOTA_EXCEEDED',
                    'message' => 'Monthly scan limit reached. Upgrade to Pro for high-capacity recognition.'
                ),
                'quota' => array(
                    'used' => $quotaStatus['used'],
                    'limit' => $quotaStatus['limit'],
                    'tier' => $quotaStatus['tier'],
                    'reset_date' => $quotaStatus['reset_date']
                )
            );
        }

        // 1b. Adil kullanim tavani (dakika/saat/gun). Aylik kotadan AYRI:
        // Pro'da aylik kota fiilen sinirsiz oldugu icin asil koruma burasi.
        $rate = $quotaHelper->checkRateLimits($user);

        if (empty($rate['allowed'])) {
            return array(
                'ok' => false,
                'status' => 429,
                'error' => array(
                    'code' => 'RATE_LIMITED',
                    'message' => 'Too many recognition requests. Please slow down and try again later.'
                ),
                'rate' => $rate,
                'quota' => array(
                    'used' => $quotaStatus['used'],
                    'limit' => $quotaStatus['limit'],
                    'tier' => $quotaStatus['tier'],
                    'reset_date' => $quotaStatus['reset_date']
                )
            );
        }

        // 2-3. Gorsel dogrulama + AI servis
        $core = self::validateAndRecognize($user->id, $imageFile, $reverseFile, $attrs);

        if (empty($core['ok'])) {
            return $core;
        }

        // 4. Kota tuketimi (yalnizca basarili tanimadan sonra)
        try {
            $quotaHelper->consumeScan($user);
        } catch (RuntimeException $e) {
            return array('ok' => false, 'status' => 500, 'error' => array('code' => 'ERROR', 'message' => 'Quota consumption failed'));
        }

        $updatedQuotaStatus = $quotaHelper->canScan($user);
        $formattedResults   = $core['helper']->formatResults($core['ai']);

        // 5. Loglama
        self::logSuccess($user->id, $core);

        return array(
            'ok' => true,
            'status' => 200,
            'data' => $formattedResults,
            'quota' => array(
                'used' => $updatedQuotaStatus['used'],
                'remaining' => $updatedQuotaStatus['remaining'],
                'limit' => $updatedQuotaStatus['limit'],
                'tier' => $updatedQuotaStatus['tier'],
                'reset_date' => $updatedQuotaStatus['reset_date']
            ),
            'request_id' => 'req_' . substr($core['hash'], 0, 16),
            // Korumanin gercekten OLCULDUGU gorunur olsun (sessizce devre disi
            // kalmasin): measured=false ise sayim yapilamamis demektir.
            'rate' => array(
                'measured' => !empty($rate['measured']),
                'counts'   => isset($rate['counts']) ? $rate['counts'] : array(),
                // Istemci "bugun kalan tanima" yazabilsin diye tavanlar da bildirilir.
                // ADR-005 K2 sonrasi Pro'da "sinirsiz" denmiyor; gercek sinir bu.
                'limits'   => $quotaHelper->rateLimits(),
            ),
            'raw' => $core['ai']
        );
    }

    /**
     * M17 — oturumsuz deneme: kurulum hakki + IP/genel tavan -> ayni cekirdek -> hak tuketimi.
     * Istek gunlugune user_id = 0 ile yazilir (IP ve genel tavan bu satirlardan sayilir).
     *
     * @param   string      $deviceHash   AnonTrialHelper::deviceHash() ciktisi
     * @param   array|null  $imageFile    $_FILES girdisi (on yuz)
     * @param   array|null  $reverseFile  $_FILES girdisi (arka yuz, istege bagli)
     * @param   array       $attrs        metal / weight_g / diameter_mm
     * @param   array|null  $config       constants.php (verilmezse yuklenir)
     *
     * @return  array  runForUser ile ayni bicim; hata kodlari TRIAL_EXHAUSTED / TRIAL_UNAVAILABLE
     */
    public static function runAnonymous($deviceHash, $imageFile, $reverseFile, array $attrs = array(), $config = null)
    {
        if ($config === null) {
            $configPath = __DIR__ . '/../config/constants.php';
            $config = file_exists($configPath) ? include $configPath : [];
        }

        $trial    = new AnonTrialHelper(null, $config);
        $used     = $trial->used($deviceHash);
        $decision = AnonTrialHelper::decide($trial->getSettings(), $used, $trial->counts(AnonTrialHelper::clientIp()));

        if (!$decision['allowed']) {
            return array(
                'ok' => false,
                'status' => 429,
                'error' => array(
                    'code' => $decision['code'],
                    'message' => $decision['code'] === 'TRIAL_EXHAUSTED'
                        ? 'Free trial scans used up. Sign up for a free account to keep scanning.'
                        : 'Free trial is not available right now. Sign in to keep scanning.',
                ),
                'quota' => $trial->quota($used),
            );
        }

        $core = self::validateAndRecognize(0, $imageFile, $reverseFile, $attrs);

        if (empty($core['ok'])) {
            return $core;
        }

        try {
            $trial->consume($deviceHash);
        } catch (RuntimeException $e) {
            return array('ok' => false, 'status' => 500, 'error' => array('code' => 'ERROR', 'message' => 'Quota consumption failed'));
        }

        $formattedResults = $core['helper']->formatResults($core['ai']);

        self::logSuccess(0, $core);

        return array(
            'ok' => true,
            'status' => 200,
            'data' => $formattedResults,
            'quota' => $trial->quota($used + 1),
            'request_id' => 'req_' . substr($core['hash'], 0, 16),
            'raw' => $core['ai']
        );
    }

    /**
     * Ortak cekirdek: gorsel dogrulama -> AI servis. Kota ve oturum bilmez; AI hatasi burada
     * loglanir (kota/hak TUKETILMEZ).
     *
     * @return  array  ok=false => status, error ; ok=true => ai, hash, helper
     */
    private static function validateAndRecognize($logUserId, $imageFile, $reverseFile, array $attrs)
    {
        if (empty($imageFile)) {
            return array('ok' => false, 'status' => 400, 'error' => array('code' => 'ERROR', 'message' => 'No image file uploaded'));
        }

        $aiHelper = new AiServiceHelper();

        try {
            $aiHelper->validateImage($imageFile);
        } catch (InvalidArgumentException $e) {
            return array('ok' => false, 'status' => 400, 'error' => array('code' => 'ERROR', 'message' => $e->getMessage()));
        }

        if ($reverseFile && $reverseFile['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $aiHelper->validateImage($reverseFile);
            } catch (InvalidArgumentException $e) {
                return array('ok' => false, 'status' => 400, 'error' => array('code' => 'ERROR', 'message' => 'Reverse image: ' . $e->getMessage()));
            }
        }

        $imageHash = $aiHelper->calculateImageHash($imageFile['tmp_name']);

        try {
            $reversePath = ($reverseFile && $reverseFile['error'] === UPLOAD_ERR_OK) ? $reverseFile['tmp_name'] : null;
            $aiResults = $aiHelper->recognize($imageFile['tmp_name'], $reversePath, $attrs);
        } catch (RuntimeException $e) {
            self::logRecognitionRequest($logUserId, $imageHash, null, null, null, $e->getMessage());

            return array('ok' => false, 'status' => 503, 'error' => array('code' => 'AI_SERVICE', 'message' => $e->getMessage()));
        }

        return array('ok' => true, 'ai' => $aiResults, 'hash' => $imageHash, 'helper' => $aiHelper);
    }

    private static function logSuccess($userId, array $core)
    {
        $aiResults = $core['ai'];
        $topMatch  = isset($aiResults['matches'][0]) ? $aiResults['matches'][0] : null;

        self::logRecognitionRequest(
            $userId,
            $core['hash'],
            isset($aiResults['processing_time_ms']) ? $aiResults['processing_time_ms'] : 0,
            $topMatch ? $topMatch['article_id'] : null,
            $topMatch ? $topMatch['confidence'] : null,
            null,
            $aiResults
        );
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
                'message' => 'Monthly scan limit reached. Upgrade to Pro for high-capacity recognition.',
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
        header('Cache-Control: no-store'); // A21: kullaniciya / kuruluma ozel yanit
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
    private static function sendError($message, $code = 400, $errorCode = 'ERROR', array $extra = array())
    {
        $response = array(
            'success' => false,
            'error' => array_merge(
                array(
                    'code' => $errorCode,
                    'message' => $message
                ),
                $extra
            )
        );

        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store'); // A21: kullaniciya / kuruluma ozel yanit
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
