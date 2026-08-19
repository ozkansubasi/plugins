<?php
/**
 * University Application Helper
 * Handles university student pro subscription applications
 */
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;

class UniversityApplicationHelper
{
    private const UPLOAD_DIR = JPATH_ROOT . '/media/university_applications';
    private const RECIPIENT_EMAIL = 'abonelik@numistr.org';
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    /**
     * Process university application
     */
    public static function processApplication(): array
    {
        // Validate required fields
        $requiredFields = ['name', 'surname', 'email', 'university', 'department'];
        $data = [];

        foreach ($requiredFields as $field) {
            $value = $_POST[$field] ?? '';
            if (empty(trim($value))) {
                return [
                    'success' => false,
                    'error' => 'field_required',
                    'message' => "Field '{$field}' is required"
                ];
            }
            $data[$field] = trim(strip_tags($value));
        }

        // Validate email format
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'invalid_email',
                'message' => 'Invalid email format'
            ];
        }

        // Optional interests
        $data['interests'] = $_POST['interests'] ?? '';

        // Handle ID card upload
        if (!isset($_FILES['id_card']) || $_FILES['id_card']['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'error' => 'id_required',
                'message' => 'University ID card upload is required'
            ];
        }

        $uploadResult = self::handleIdCardUpload($_FILES['id_card'], $data);
        if (!$uploadResult['success']) {
            return $uploadResult;
        }

        $data['id_card_path'] = $uploadResult['path'];
        $data['id_card_filename'] = $uploadResult['filename'];

        // Send email notification
        $emailResult = self::sendNotificationEmail($data);
        if (!$emailResult['success']) {
            return $emailResult;
        }

        // Store application in database
        $dbResult = self::storeApplication($data);

        return [
            'success' => true,
            'message' => 'Application submitted successfully',
            'application_id' => $dbResult['id'] ?? null
        ];
    }

    /**
     * Handle ID card file upload
     */
    private static function handleIdCardUpload(array $file, array $data): array
    {
        // Check file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return [
                'success' => false,
                'error' => 'file_too_large',
                'message' => 'File size exceeds 5MB limit'
            ];
        }

        // Check file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS)) {
            return [
                'success' => false,
                'error' => 'invalid_file_type',
                'message' => 'Only JPG, PNG, GIF and PDF files are allowed'
            ];
        }

        // Create upload directory if not exists
        if (!Folder::exists(self::UPLOAD_DIR)) {
            Folder::create(self::UPLOAD_DIR);
            // Create .htaccess to protect uploads
            file_put_contents(self::UPLOAD_DIR . '/.htaccess', "Deny from all\n");
        }

        // Generate unique filename
        $timestamp = date('Ymd_His');
        $safeName = preg_replace('/[^a-z0-9]/i', '_', $data['surname'] . '_' . $data['name']);
        $filename = $timestamp . '_' . $safeName . '.' . $ext;
        $destPath = self::UPLOAD_DIR . '/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return [
                'success' => false,
                'error' => 'upload_failed',
                'message' => 'Failed to save uploaded file'
            ];
        }

        return [
            'success' => true,
            'path' => $destPath,
            'filename' => $filename
        ];
    }

    /**
     * Send notification email
     */
    private static function sendNotificationEmail(array $data): array
    {
        try {
            $mailer = Factory::getMailer();

            // Set recipient
            $mailer->addRecipient(self::RECIPIENT_EMAIL);

            // Set subject
            $mailer->setSubject('[NumisTR] Yeni Universite Ogrenci Basvurusu - ' . $data['name'] . ' ' . $data['surname']);

            // Build email body
            $body = self::buildEmailBody($data);
            $mailer->setBody($body);
            $mailer->isHtml(true);

            // Attach ID card
            if (file_exists($data['id_card_path'])) {
                $mailer->addAttachment($data['id_card_path'], $data['id_card_filename']);
            }

            // Send
            $sent = $mailer->Send();

            if ($sent !== true) {
                return [
                    'success' => false,
                    'error' => 'email_failed',
                    'message' => 'Failed to send notification email'
                ];
            }

            return ['success' => true];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'email_exception',
                'message' => 'Email error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Build HTML email body
     */
    private static function buildEmailBody(array $data): string
    {
        $interests = !empty($data['interests']) ? $data['interests'] : 'Belirtilmedi';
        $timestamp = date('d.m.Y H:i:s');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #8B6914, #6B5010); color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .field { margin-bottom: 15px; }
        .label { font-weight: bold; color: #666; font-size: 12px; text-transform: uppercase; }
        .value { font-size: 16px; color: #333; margin-top: 4px; }
        .footer { background: #eee; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 8px 8px; }
        .badge { display: inline-block; background: #8B6914; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">Yeni Universite Ogrenci Basvurusu</h2>
            <span class="badge">Pro Abonelik Talebi</span>
        </div>
        <div class="content">
            <div class="field">
                <div class="label">Ad Soyad</div>
                <div class="value">{$data['name']} {$data['surname']}</div>
            </div>
            <div class="field">
                <div class="label">E-posta</div>
                <div class="value"><a href="mailto:{$data['email']}">{$data['email']}</a></div>
            </div>
            <div class="field">
                <div class="label">Universite</div>
                <div class="value">{$data['university']}</div>
            </div>
            <div class="field">
                <div class="label">Bolum</div>
                <div class="value">{$data['department']}</div>
            </div>
            <div class="field">
                <div class="label">Ilgi Alanlari</div>
                <div class="value">{$interests}</div>
            </div>
            <div class="field">
                <div class="label">Ogrenci Kimligi</div>
                <div class="value">Ekte gonderilmistir: {$data['id_card_filename']}</div>
            </div>
        </div>
        <div class="footer">
            Basvuru Tarihi: {$timestamp}<br>
            <a href="https://numistr.org/administrator">Joomla Admin Panel</a>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Store application in database for tracking
     */
    private static function storeApplication(array $data): array
    {
        try {
            $db = Factory::getDbo();

            // Create table if not exists
            self::ensureTableExists($db);

            $record = (object)[
                'name' => $data['name'],
                'surname' => $data['surname'],
                'email' => $data['email'],
                'university' => $data['university'],
                'department' => $data['department'],
                'interests' => $data['interests'],
                'id_card_filename' => $data['id_card_filename'],
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
            ];

            $db->insertObject('#__numistr_university_applications', $record);

            return [
                'success' => true,
                'id' => $db->insertid()
            ];

        } catch (\Throwable $e) {
            // Log error but don't fail the whole application
            Factory::getApplication()->getLogger()->error(
                'Failed to store university application: ' . $e->getMessage()
            );

            return [
                'success' => false,
                'id' => null
            ];
        }
    }

    /**
     * Ensure applications table exists
     */
    private static function ensureTableExists($db): void
    {
        $tableExists = $db->setQuery("SHOW TABLES LIKE '#__numistr_university_applications'")->loadResult();

        if (!$tableExists) {
            $sql = "
                CREATE TABLE IF NOT EXISTS `#__numistr_university_applications` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(100) NOT NULL,
                    `surname` VARCHAR(100) NOT NULL,
                    `email` VARCHAR(255) NOT NULL,
                    `university` VARCHAR(255) NOT NULL,
                    `department` VARCHAR(255) NOT NULL,
                    `interests` TEXT,
                    `id_card_filename` VARCHAR(255) NOT NULL,
                    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                    `notes` TEXT,
                    `approved_by` INT(11) DEFAULT NULL,
                    `approved_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL,
                    `ip_address` VARCHAR(45) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_email` (`email`),
                    KEY `idx_status` (`status`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $db->setQuery($sql)->execute();
        }
    }

    /**
     * Get application status by email
     */
    public static function getApplicationStatus(string $email): ?array
    {
        try {
            $db = Factory::getDbo();

            $query = $db->getQuery(true)
                ->select(['id', 'status', 'created_at', 'approved_at'])
                ->from('#__numistr_university_applications')
                ->where('email = ' . $db->quote($email))
                ->order('created_at DESC');

            $result = $db->setQuery($query, 0, 1)->loadAssoc();

            return $result;

        } catch (\Throwable $e) {
            return null;
        }
    }
}
