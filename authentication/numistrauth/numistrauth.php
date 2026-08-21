<?php
/**
 * @package     NumisTR Web Auth — SSO authentication plugin
 * @subpackage  plg_authentication_numistrauth
 * @version     1.0.0
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Yalnızca plg_system_numistrauth'un Auth0 doğrulamasından sonra ürettiği tek kullanımlık
 * oturum nonce'u ile giriş kabul eder. Şifre kontrolü yapmaz; normal form girişlerine
 * (nonce yok) hiç cevap vermez, diğer authentication plugin'lerine bırakır.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;

class PlgAuthenticationNumistrauth extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    public function onUserAuthenticate($credentials, $options, &$response)
    {
        $response->type = 'NumisTRAuth';

        $nonce = isset($credentials['numistrauth_nonce']) ? (string) $credentials['numistrauth_nonce'] : '';

        if ($nonce === '') {
            // Not our flow — stay silent so other plugins can answer.
            $response->status        = Authentication::STATUS_FAILURE;
            $response->error_message = '';

            return;
        }

        $session  = Factory::getApplication()->getSession();
        $expected = (string) $session->get('numistrauth.sso_nonce', '');
        $userId   = (int) $session->get('numistrauth.sso_user', 0);
        $time     = (int) $session->get('numistrauth.sso_time', 0);

        if ($expected === '' || !hash_equals($expected, $nonce) || $userId <= 0 || (time() - $time) > 120) {
            $response->status        = Authentication::STATUS_FAILURE;
            $response->error_message = 'NumisTR SSO nonce invalid';

            return;
        }

        $user = Factory::getUser($userId);

        if ($user->id <= 0 || (int) $user->block === 1 || $user->username !== (string) ($credentials['username'] ?? '')) {
            $response->status        = Authentication::STATUS_FAILURE;
            $response->error_message = 'NumisTR SSO user mismatch';

            return;
        }

        $response->status        = Authentication::STATUS_SUCCESS;
        $response->error_message = '';
        $response->username      = $user->username;
        $response->email         = $user->email;
        $response->fullname      = $user->name;
        $response->language      = $user->getParam('language', '');
    }
}
