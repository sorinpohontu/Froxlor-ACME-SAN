<?php
/**
 * Example local configuration for the ACME SAN generator.
 *
 * Copy this file to acme.local.php and change what this server needs. Leave
 * out any setting you're happy with: the built-in default is used instead.
 * Git ignores acme.local.php, and it should be readable only by its
 * administrator (chmod 600).
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
    // Froxlor's database credentials file.
    'froxlor_config'   => '/var/www/html/froxlor/lib/userdata.inc.php',
    // Each certificate is stored in its own folder here: <certificate_root>/<type>/.
    'certificate_root' => '/etc/ssl/acme-sans',
    // Stops two runs from overlapping.
    'lock_file'        => '/var/run/acme-san.pid',
    // Seconds to wait between certificate requests.
    'rate_limit_delay' => 2,
    // How many times to look up a name in DNS before giving up, and the
    // seconds to wait between attempts.
    'dns_retries'      => 5,
    'dns_retry_delay'  => 1,

    // By default, these come from Froxlor's own Let's Encrypt settings.
    // Uncomment a line only to use a different value for this script.
    'acme' => [
        // 'script_path' => '/root/.acme.sh/acme.sh',
        // 'server' => 'letsencrypt',
        // 'key_length' => 4096,
        // 'webroot' => '/var/www/html/.well-known/acme-challenge',
    ],

    // Error reports go to Froxlor's admin address, using Froxlor's mail
    // settings. Here you only choose whether to send them and the subject.
    'email' => [
        'enabled' => true,
        'subject' => '[ACME SAN] Certificate Generation Report',
    ],

    // One entry per service certificate. This list replaces the built-in one,
    // so keep every certificate you still want.
    //
    // While a certificate would contain only the server hostname (no customer
    // or additional domains yet), the Froxlor panel certificate is copied in
    // instead of requesting a new one. See "New servers reuse the panel
    // certificate" in README.md.
    'certificates' => [
        'mail' => [
            // Prefixes added to every customer domain: mail.customer.example.
            'subdomains'                  => ['mail'],
            // Also add mail.<server-hostname>. Turn on only if that name
            // really exists and has working DNS.
            'include_hostname_subdomains' => false,
            // Extra domains that aren't in Froxlor. Give the base domain only
            // ('external.example'); the prefix is added for you.
            'additional_domains'          => [],
            // Runs as root when the certificate changes, usually to reload
            // the service.
            'post_command'                => 'systemctl restart postfix dovecot',
        ],
        'webmail' => [
            'subdomains'                  => ['webmail'],
            'include_hostname_subdomains' => false,
            'additional_domains'          => [],
            'post_command'                => 'systemctl restart apache2',
            // Optional: let this existing group read the certificate and key,
            // for services that don't run as root. Others stay root-only.
            // 'group'                    => 'www-data',
        ],
        'dav' => [
            'subdomains'                  => ['dav'],
            'include_hostname_subdomains' => false,
            'additional_domains'          => [],
            'post_command'                => 'systemctl restart apache2',
        ],
    ],
];
