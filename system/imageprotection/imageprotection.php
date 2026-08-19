<?php
/**
 * @package     Image Protection Plugin
 * @subpackage  plg_system_imageprotection
 * @copyright   Copyright (C) 2025 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;

/**
 * Image Protection System Plugin
 * Prevents right-click save on images
 */
class PlgSystemImageprotection extends CMSPlugin
{
    /**
     * Application object
     *
     * @var    \Joomla\CMS\Application\CMSApplication
     */
    protected $app;

    /**
     * Load the plugin only on frontend
     *
     * @return  void
     */
    public function onBeforeCompileHead()
    {
        // Only run on site (not admin)
        if (!$this->app->isClient('site')) {
            return;
        }

        $doc = Factory::getDocument();

        // Add inline script for immediate execution
        $script = <<<'JS'
(function() {
    'use strict';

    // Disable right-click on images
    document.addEventListener('contextmenu', function(e) {
        if (e.target.tagName === 'IMG' ||
            e.target.closest('.coin-image') ||
            e.target.closest('.uk-lightbox') ||
            e.target.closest('[class*="sikke"]')) {
            e.preventDefault();
            return false;
        }
    }, false);

    // Disable drag on images
    document.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG') {
            e.preventDefault();
            return false;
        }
    }, false);

    // Add CSS to prevent selection
    const style = document.createElement('style');
    style.textContent = `
        img {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-user-drag: none;
            -webkit-touch-callout: none;
            pointer-events: auto;
        }
        .coin-image img,
        .uk-lightbox img,
        [class*="sikke"] img {
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
            -webkit-user-drag: none !important;
            -webkit-touch-callout: none !important;
        }
    `;
    document.head.appendChild(style);

    // Disable keyboard shortcuts for saving images
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            if (document.activeElement.tagName === 'IMG') {
                e.preventDefault();
                return false;
            }
        }
    }, false);

})();
JS;

        $doc->addScriptDeclaration($script);
    }
}
