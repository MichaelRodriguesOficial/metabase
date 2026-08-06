<?php

/**
 * -------------------------------------------------------------------------
 * Metabase plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Metabase.
 *
 * Metabase is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Metabase is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Metabase. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2018-2023 by Metabase plugin team.
 * @license   GPLv2 https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/pluginsGLPI/metabase
 * -------------------------------------------------------------------------
 */

class PluginMetabaseTwig
{
    private static $instance = null;
    private $twig = null;

    private function __construct()
    {
        // Usar o TwigLoader do GLPI se disponível
        if (class_exists('\Glpi\Toolbox\TwigLoader')) {
            $loader = \Glpi\Toolbox\TwigLoader::getInstance();
        } else {
            $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
        }
        
        $this->twig = new \Twig\Environment($loader, [
            'cache' => GLPI_CACHE_DIR . '/twig',
            'auto_reload' => true,
            'debug' => $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE,
        ]);

        // Adicionar funções globais do GLPI
        $this->addGlobalFunctions();
    }

    private function addGlobalFunctions(): void
    {
        // Funções de tradução
        $this->twig->addFunction(new \Twig\TwigFunction('__', function($string, $domain = '') {
            return __($string, $domain);
        }));

        $this->twig->addFunction(new \Twig\TwigFunction('_x', function($context, $string, $domain = '') {
            return _x($context, $string, $domain);
        }));

        $this->twig->addFunction(new \Twig\TwigFunction('__s', function($string, $domain = '') {
            return __s($string, $domain);
        }));

        // CSRF Token
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', function() {
            return Session::getNewCSRFToken();
        }));

        // Form URL helper
        $this->twig->addFunction(new \Twig\TwigFunction('get_form_url', function($classname) {
            return Toolbox::getItemTypeFormURL($classname);
        }));

        // Dropdown Right helper - simplificado
        $this->twig->addFunction(new \Twig\TwigFunction('dropdown_right', function($params) {
            $name = $params['name'] ?? '';
            $value = $params['value'] ?? 0;
            $nonone = $params['nonone'] ?? 0;
            $noread = $params['noread'] ?? 0;
            $nowrite = $params['nowrite'] ?? 1;
            
            ob_start();
            Dropdown::showFromArray(
                $name,
                [
                    0 => __('None'),
                    READ => __('Read')
                ],
                [
                    'value' => $value,
                    'display' => true,
                ]
            );
            return ob_get_clean();
        }, ['is_safe' => ['html']]));
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function render($template, $data = []): string
    {
        try {
            return $this->twig->render($template, $data);
        } catch (\Twig\Error\Error $e) {
            // Usar o método correto de log do GLPI
            if (method_exists('Toolbox', 'logDebug')) {
                \Toolbox::logDebug('Twig rendering error: ' . $e->getMessage());
            } else {
                // Fallback para error_log
                error_log('Twig rendering error: ' . $e->getMessage());
            }
            
            return '<div class="alert alert-danger">' . 
                   __('Error rendering template. Please check GLPI logs.', 'metabase') . 
                   '</div>';
        }
    }

    /**
     * Render template with error handling
     */
    public function renderSafe($template, $data = []): void
    {
        echo $this->render($template, $data);
    }
}