<?php
/**
 * Example local configuration for the ACME SAN generator.
 *
 * Copy this file to acme.local.php and adjust it for the server. The local
 * file is ignored by Git and must be readable only by its administrator.
 *
 * @package    Froxlor_ACME_SAN
 *
 * @subpackage Configuration
 *
 * @author     Sorin Pohontu <sorin@frontline.ro>
 * @copyright  2026 Sorin Pohontu
 * @license    https://opensource.org/licenses/BSD-3-Clause
 *
 */

return [
    'froxlor_config'   => '/var/www/html/froxlor/lib/userdata.inc.php',
    'certificate_root' => '/etc/ssl/acme-sans',
    'lock_file'        => '/var/run/acme-san.pid',
    'rate_limit_delay' => 2,
    'dns_retries'      => 5,
    'dns_retry_delay'  => 1,

    // These values override Froxlor panel settings. Omit values that Froxlor
    // should continue to manage.
    'acme' => [
        // 'script_path' => '/root/.acme.sh/acme.sh',
        // 'server' => 'letsencrypt',
        // 'key_length' => 4096,
        // 'webroot' => '/var/www/html/.well-known/acme-challenge',
    ],

    // Froxlor supplies the recipient and mail transport. Only notification
    // behavior belongs in local configuration.
    'email' => [
        'enabled' => true,
        'subject' => '[ACME SAN] Certificate Generation Report',
    ],

    // Supplying certificates replaces the complete built-in certificate map.
    'certificates' => [
        'mail' => [
            'subdomains' => ['mail'],
            'additional_domains' => [],
            'post_command' => 'systemctl restart postfix dovecot',
        ],
        'webmail' => [
            'subdomains' => ['webmail'],
            'additional_domains' => [],
            'post_command' => 'systemctl restart apache2',
        ],
    ],
];
