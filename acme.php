<?php
/**
 * ACME.sh SAN certificate generator for Froxlor-managed domains.
 *
 * @package    Froxlor
 * @subpackage ACME-SAN
 * @author     Sorin Pohontu <sorin@frontline.ro>
 * @copyright  2026 Sorin Pohontu
 * @license    https://opensource.org/licenses/BSD-3-Clause
 *
 * @version    1.0.0
 * @link       https://github.com/sorinpohontu/Froxlor-ACME-SAN
 *
 * @since      2026.09.14
 */

namespace Frontline;

use PDO;
use PDOException;
use Exception;
use InvalidArgumentException;
use Throwable;

/**
 * ACME SAN Certificate Generator
 */
class AcmeSanCertificateGenerator
{
    /** acme.sh exit status when renewal is not currently required. */
    private const ACME_RENEWAL_SKIPPED = 2;

    /** @var array Database configuration from Froxlor */
    private $_databaseConfig;

    /** @var PDO Database connection instance */
    private $_pdo;

    /** @var boolean Run in dry-run mode */
    private $_dryRun = false;

    /** @var string Path to Froxlor configuration file */
    private $_froxlorConfigPath = '/var/www/html/froxlor/lib/userdata.inc.php';

    /** @var array Local configuration overrides */
    private $_localConfig = [];

    /** @var array Parsed command-line options */
    private $_cliOptions = [];

    /** @var array Collection of errors during execution */
    private $_errors = [];

    /** @var array List of server IP addresses */
    private $_serverIPs = [];

    /** @var boolean Whether to perform DNS validation */
    private $_dnsValidation = true;

    /** @var boolean Force certificate generation even if existing */
    private $_force = false;

    /** @var boolean Send a test notification during a dry run */
    private $_testEmail = false;

    /** @var string Server's fully qualified domain name */
    private $_hostname;

    /** @var string Base directory for certificate storage */
    private $_certRoot = '/etc/ssl/acme-sans';

    /** @var string Path to acme.sh */
    private $_acmeScriptPath = '/root/.acme.sh/acme.sh';

    /** @var string Path to webroot for ACME challenges */
    private $_acmeWebRoot;

    // https://github.com/acmesh-official/acme.sh/wiki/Server
    /** @var string ACME CA Server */
    private $_acmeCA = 'letsencrypt';

    // https://github.com/acmesh-official/acme.sh/wiki/Server
    /** @var integer|string ACME key length accepted by acme.sh */
    private $_acmeKeyLength = 4096;

    /** @var array Email notification configuration */
    private $_emailConfig = [
        'enabled' => true,
        'subject' => '[ACME SAN] Certificate Generation Report',
    ];

    /** @var array Subdomain and post-command configuration */
    private $_subdomainConfig = [
        'mail' => [
            'subdomains'                  => ['mail'],
            'include_hostname_subdomains' => false,
            'additional_domains'          => [],
            'post_command'                => 'systemctl restart postfix dovecot',
        ],
        'webmail' => [
            'subdomains'                  => ['webmail'],
            'include_hostname_subdomains' => false,
            'additional_domains'          => [],
            'post_command'                => 'systemctl restart apache2',
        ],
        'dav' => [
            'subdomains'                  => ['dav'],
            'include_hostname_subdomains' => false,
            'additional_domains'          => [],
            'post_command'                => 'systemctl restart apache2',
        ],
    ];

    /** @var string Path to lock file */
    private $_lockFile = '/var/run/acme-san.pid';

    /** @var resource|null Lock file handle */
    private $_lockHandle = null;

    /** @var integer Rate limit delay between ACME requests in seconds */
    private $_rateLimitDelay = 2;

    /** @var integer Number of DNS lookup retry attempts */
    private $_dnsRetries = 5;

    /** @var integer Delay between DNS retry attempts in seconds */
    private $_dnsRetryDelay = 1;

    /** @var array DNS validation results cached for this execution */
    private $_dnsValidationCache = [];

    /**
     * AcmeSanCertificateGenerator constructor.
     *
     * @param array $options Parsed command-line options
     *
     * @throws Exception On initialization errors
     */
    public function __construct(array $options = [])
    {
        self::configureSystemTimezone();
        $this->_cliOptions = $options;
        $this->loadLocalConfig();

        if (isset($this->_localConfig['froxlor_config'])) {
            $this->_froxlorConfigPath = $this->_localConfig['froxlor_config'];
        }
        if (isset($this->_cliOptions['froxlor_config'])) {
            $this->_froxlorConfigPath = $this->_cliOptions['froxlor_config'];
        }
        $this->assertAbsolutePath($this->_froxlorConfigPath, 'froxlor_config');

        $this->_hostname = trim(shell_exec('hostname -f'));
        if (empty($this->_hostname)) {
            throw new Exception('Unable to determine FQDN hostname');
        }

        $this->loadFroxlorConfig();
        $this->connectDatabase();
        $this->loadAcmeConfig();
        $this->applyLocalConfig();
        $this->applyCliOptions();

        $this->validateConfig();

        if ($this->_dnsValidation) {
            $this->detectServerIPs();
        } else {
            $this->log('DNS validation disabled');
        }

        if (!$this->_dryRun) {
            $this->acquireLock();
        }
    }

    /**
     * Align PHP timestamps with the detected operating-system timezone.
     *
     * @return void
     */
    private static function configureSystemTimezone(): void
    {
        $candidates = [];

        if (is_file('/etc/timezone') && is_readable('/etc/timezone')) {
            $lines = file('/etc/timezone', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines) && isset($lines[0])) {
                $candidates[] = $lines[0];
            }
        }

        $localtimePath = realpath('/etc/localtime');
        if (is_string($localtimePath)
            && preg_match('#/zoneinfo(?:\.default)?/(.+)$#', $localtimePath, $matches)
        ) {
            $candidates[] = $matches[1];
        }

        foreach ($candidates as $candidate) {
            if (self::applyTimezoneCandidate($candidate)) {
                return;
            }
        }

        if (is_executable('/usr/bin/timedatectl')) {
            $candidate = shell_exec('/usr/bin/timedatectl show --property=Timezone --value 2>/dev/null');
            self::applyTimezoneCandidate((string) $candidate);
        }
    }

    /**
     * Validate and apply one operating-system timezone candidate.
     *
     * @param string $candidate Candidate timezone identifier
     *
     * @return boolean True when the candidate was applied
     */
    private static function applyTimezoneCandidate(string $candidate): bool
    {
        $candidate = trim($candidate);
        $candidate = preg_replace('#^(?:posix|right)/#', '', $candidate);
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }

        $identifiers = timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC);
        if (!in_array($candidate, $identifiers, true)) {
            return false;
        }

        return date_default_timezone_set($candidate);
    }

    /**
     * Parse command-line arguments without causing runtime side effects.
     *
     * @param array $arguments Arguments excluding the script name
     *
     * @return array Parsed options
     * @throws InvalidArgumentException If an argument is invalid
     */
    public static function parseArguments(array $arguments): array
    {
        $options = [];
        $flags = [
            '--dry-run'             => 'dry_run',
            '--install'             => 'install',
            '--test-email'          => 'test_email',
            '--no-email'            => 'no_email',
            '--skip-dns-validation' => 'skip_dns_validation',
            '--force'               => 'force',
            '--help'                => 'help',
        ];
        $valueOptions = [
            '--froxlor-config=' => 'froxlor_config',
            '--local-config='   => 'local_config',
            '--certificate='    => 'certificate',
        ];

        foreach ($arguments as $argument) {
            if (isset($flags[$argument])) {
                $options[$flags[$argument]] = true;
                continue;
            }

            $matched = false;
            foreach ($valueOptions as $prefix => $name) {
                if (strpos($argument, $prefix) !== 0) {
                    continue;
                }

                $matched = true;
                $value = substr($argument, strlen($prefix));
                if ($value === '') {
                    throw new InvalidArgumentException('Missing value for option: ' . rtrim($prefix, '='));
                }
                if (isset($options[$name]) && $options[$name] !== $value) {
                    throw new InvalidArgumentException('Conflicting values for option: ' . rtrim($prefix, '='));
                }
                $options[$name] = $value;
                break;
            }

            if (!$matched) {
                if (in_array($argument, ['--froxlor-config', '--local-config', '--certificate'], true)) {
                    throw new InvalidArgumentException('Missing value for option: ' . $argument);
                }
                throw new InvalidArgumentException('Unknown argument: ' . $argument);
            }
        }

        if (!empty($options['install'])) {
            $installOptions = array_diff(array_keys($options), ['install', 'dry_run', 'help']);
            if (!empty($installOptions)) {
                throw new InvalidArgumentException('--install can only be combined with --dry-run');
            }
        }
        if (!empty($options['test_email']) && empty($options['dry_run'])) {
            throw new InvalidArgumentException('--test-email requires --dry-run');
        }
        if (!empty($options['test_email']) && !empty($options['no_email'])) {
            throw new InvalidArgumentException('--test-email cannot be combined with --no-email');
        }
        if (isset($options['certificate']) && !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $options['certificate'])) {
            throw new InvalidArgumentException('--certificate must name a valid configured certificate type');
        }

        return $options;
    }

    /**
     * Install or preview cron and logrotate configuration for this script.
     *
     * @param boolean $dryRun Preview files without writing them
     *
     * @return integer Zero on success
     * @throws Exception If installation cannot be completed safely
     */
    public static function install(bool $dryRun = false): int
    {
        $scriptPath = realpath(__FILE__);
        $phpBinary = '/usr/bin/php';
        if ($scriptPath === false || !is_file($scriptPath) || !is_readable($scriptPath)) {
            throw new Exception('Unable to resolve the installed script path');
        }

        if (!is_file($phpBinary) || !is_executable($phpBinary)) {
            $phpBinary = realpath(PHP_BINARY);
        }
        if (!is_string($phpBinary) || !is_file($phpBinary) || !is_executable($phpBinary)) {
            throw new Exception('Unable to resolve the PHP CLI executable');
        }

        $files = self::buildInstallationFiles($scriptPath, $phpBinary);
        if ($dryRun) {
            echo "INSTALL DRY RUN\n\n";
            foreach ($files as $path => $content) {
                echo 'Would install: ' . $path . "\n";
                echo $content . "\n";
            }
            return 0;
        }

        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new Exception('--install must be run as root');
        }

        foreach (array_keys($files) as $path) {
            self::assertManagedInstallTarget($path);
        }
        foreach ($files as $path => $content) {
            self::writeManagedFile($path, $content);
        }

        echo "Installed /etc/cron.d/acme-san\n";
        echo "Installed /etc/logrotate.d/acme-san\n";
        echo 'Cron command uses: ' . $phpBinary . ' ' . $scriptPath . "\n";

        return 0;
    }

    /**
     * Build the system files managed by --install.
     *
     * @param string $scriptPath Absolute installed script path
     * @param string $phpBinary  Absolute PHP CLI path
     *
     * @return array Path-to-content map
     */
    private static function buildInstallationFiles(string $scriptPath, string $phpBinary): array
    {
        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath);
        $cron  = "# Managed by AcmeSan --install\n";
        $cron .= "SHELL=/bin/sh\n";
        $cron .= "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n";
        $cron .= "MAILTO=root\n\n";
        $cron .= "# Daily ACME SAN certificate check\n";
        $cron .= '17 3 * * * root ' . $command . " >> /var/log/acme-san.log 2>&1\n";

        $logrotate  = "# Managed by AcmeSan --install\n";
        $logrotate .= "/var/log/acme-san.log {\n";
        $logrotate .= "    weekly\n";
        $logrotate .= "    rotate 12\n";
        $logrotate .= "    compress\n";
        $logrotate .= "    delaycompress\n";
        $logrotate .= "    missingok\n";
        $logrotate .= "    notifempty\n";
        $logrotate .= "    create 0640 root adm\n";
        $logrotate .= "}\n";

        return [
            '/etc/cron.d/acme-san' => $cron,
            '/etc/logrotate.d/acme-san' => $logrotate,
        ];
    }

    /**
     * Atomically create or update one --install-managed system file.
     *
     * @param string $path    Destination path
     * @param string $content Complete file content
     *
     * @return void
     * @throws Exception If the destination is unmanaged or cannot be written
     */
    private static function writeManagedFile(string $path, string $content): void
    {
        self::assertManagedInstallTarget($path);

        if (file_exists($path)) {
            $existing = file_get_contents($path);
            if ($existing === $content) {
                self::setManagedFileMetadata($path);
                return;
            }
        }

        $directory = dirname($path);
        $temporaryPath = tempnam($directory, '.acme-san-');
        if ($temporaryPath === false) {
            throw new Exception('Unable to create temporary install file in: ' . $directory);
        }

        try {
            if (file_put_contents($temporaryPath, $content, LOCK_EX) === false) {
                throw new Exception('Unable to write temporary install file: ' . $temporaryPath);
            }
            if (!chmod($temporaryPath, 0644)) {
                throw new Exception('Unable to set install file permissions: ' . $temporaryPath);
            }
            if (!rename($temporaryPath, $path)) {
                throw new Exception('Unable to install file: ' . $path);
            }
            self::setManagedFileMetadata($path);
        } finally {
            if (file_exists($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * Verify that an install destination is safe to create or update.
     *
     * @param string $path Destination path
     *
     * @return void
     * @throws Exception If the destination is unmanaged or not writable
     */
    private static function assertManagedInstallTarget(string $path): void
    {
        $marker = '# Managed by AcmeSan --install';
        $directory = dirname($path);
        $filename = basename($path);
        if (is_dir($directory)) {
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry !== $filename && strcasecmp($entry, $filename) === 0) {
                    throw new Exception('Conflicting differently-cased install file exists: ' . $directory . '/' . $entry);
                }
            }
        }
        if (is_link($path)) {
            throw new Exception('Refusing to replace symbolic link: ' . $path);
        }
        if (file_exists($path)) {
            if (!is_file($path) || !is_readable($path)) {
                throw new Exception('Existing install target is not a readable regular file: ' . $path);
            }
            $existing = file_get_contents($path);
            if ($existing === false || strpos($existing, $marker) !== 0) {
                throw new Exception('Refusing to overwrite unmanaged file: ' . $path);
            }
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new Exception('Install directory is not writable: ' . $directory);
        }
    }

    /**
     * Apply the required owner and mode to an installed system file.
     *
     * @param string $path Installed file path
     *
     * @return void
     * @throws Exception If metadata cannot be applied
     */
    private static function setManagedFileMetadata(string $path): void
    {
        if (!chown($path, 'root') || !chgrp($path, 'root') || !chmod($path, 0644)) {
            throw new Exception('Unable to set root ownership and mode 0644 on: ' . $path);
        }
    }

    /**
     * Load the automatic or explicitly selected local configuration.
     *
     * @return void
     * @throws Exception If a present configuration file is invalid
     */
    private function loadLocalConfig(): void
    {
        $explicit = isset($this->_cliOptions['local_config']);
        $path = $explicit ? $this->_cliOptions['local_config'] : __DIR__ . '/acme.local.php';

        if (!is_string($path) || $path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException('Local config path must be a non-empty path without control characters');
        }

        if (!file_exists($path)) {
            if ($explicit) {
                throw new InvalidArgumentException('Local config file not found: ' . $path);
            }
            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Local config file is not readable: ' . $path);
        }

        try {
            $config = require $path;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Failed to load local config file ' . $path . ': ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($config)) {
            throw new InvalidArgumentException('Local config file must return an array: ' . $path);
        }

        $this->validateLocalConfig($config);
        $this->_localConfig = $config;
        $this->log('Local config loaded from: ' . $path);
    }

    /**
     * Validate the local configuration schema before applying it.
     *
     * @param array $config Local configuration
     *
     * @return void
     * @throws InvalidArgumentException If a key or value is invalid
     */
    private function validateLocalConfig(array $config): void
    {
        $this->assertAllowedKeys($config, [
            'froxlor_config',
            'certificate_root',
            'lock_file',
            'rate_limit_delay',
            'dns_retries',
            'dns_retry_delay',
            'acme',
            'email',
            'certificates',
        ]);

        foreach (['froxlor_config', 'certificate_root', 'lock_file'] as $key) {
            if (array_key_exists($key, $config)) {
                $this->assertAbsolutePath($config[$key], $key);
            }
        }

        foreach (['rate_limit_delay', 'dns_retry_delay'] as $key) {
            if (array_key_exists($key, $config) && (!is_int($config[$key]) || $config[$key] < 0)) {
                throw new InvalidArgumentException($key . ' must be a non-negative integer');
            }
        }
        if (array_key_exists('dns_retries', $config) && (!is_int($config['dns_retries']) || $config['dns_retries'] < 1)) {
            throw new InvalidArgumentException('dns_retries must be an integer greater than zero');
        }

        if (array_key_exists('acme', $config)) {
            if (!is_array($config['acme'])) {
                throw new InvalidArgumentException('acme must be an array');
            }
            $this->assertAllowedKeys($config['acme'], ['script_path', 'server', 'key_length', 'webroot'], 'acme');

            foreach (['script_path', 'webroot'] as $key) {
                if (array_key_exists($key, $config['acme'])) {
                    $this->assertAbsolutePath($config['acme'][$key], 'acme.' . $key);
                }
            }
            if (array_key_exists('server', $config['acme']) && (!is_string($config['acme']['server']) || trim($config['acme']['server']) === '' || preg_match('/[\x00-\x1F\x7F]/', $config['acme']['server']))) {
                throw new InvalidArgumentException('acme.server must be a non-empty string');
            }
            if (array_key_exists('key_length', $config['acme']) && !$this->validateAcmeKeyLength($config['acme']['key_length'])) {
                throw new InvalidArgumentException('acme.key_length must be a positive integer or a supported ec-* value');
            }
        }

        if (array_key_exists('email', $config)) {
            if (!is_array($config['email'])) {
                throw new InvalidArgumentException('email must be an array');
            }
            $this->assertAllowedKeys($config['email'], [
                'enabled',
                'subject',
            ], 'email');

            if (array_key_exists('enabled', $config['email']) && !is_bool($config['email']['enabled'])) {
                throw new InvalidArgumentException('email.enabled must be a boolean');
            }
            if (array_key_exists('subject', $config['email']) && !is_string($config['email']['subject'])) {
                throw new InvalidArgumentException('email.subject must be a string');
            }
        }

        if (array_key_exists('certificates', $config)) {
            if (!is_array($config['certificates']) || empty($config['certificates'])) {
                throw new InvalidArgumentException('certificates must be a non-empty array');
            }

            foreach ($config['certificates'] as $type => $certificate) {
                if (!is_string($type) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $type)) {
                    throw new InvalidArgumentException('Invalid certificate type: ' . (string) $type);
                }
                if (!is_array($certificate)) {
                    throw new InvalidArgumentException('certificates.' . $type . ' must be an array');
                }
                $this->assertAllowedKeys(
                    $certificate,
                    ['subdomains', 'include_hostname_subdomains', 'additional_domains', 'post_command'],
                    'certificates.' . $type
                );
                if (!isset($certificate['subdomains']) || !is_array($certificate['subdomains']) || empty($certificate['subdomains'])) {
                    throw new InvalidArgumentException('certificates.' . $type . '.subdomains must be a non-empty array');
                }
                foreach ($certificate['subdomains'] as $subdomain) {
                    if (!is_string($subdomain) || !$this->validateDnsLabel($subdomain)) {
                        throw new InvalidArgumentException('Invalid subdomain in certificates.' . $type . ': ' . (string) $subdomain);
                    }
                }
                if (array_key_exists('include_hostname_subdomains', $certificate)
                    && !is_bool($certificate['include_hostname_subdomains'])
                ) {
                    throw new InvalidArgumentException(
                        'certificates.' . $type . '.include_hostname_subdomains must be a boolean'
                    );
                }
                if (isset($certificate['additional_domains'])) {
                    if (!is_array($certificate['additional_domains'])) {
                        throw new InvalidArgumentException('certificates.' . $type . '.additional_domains must be an array');
                    }
                    foreach ($certificate['additional_domains'] as $domain) {
                        if (!is_string($domain) || !$this->validateDomainName($domain)) {
                            throw new InvalidArgumentException('Invalid additional domain in certificates.' . $type . ': ' . (string) $domain);
                        }
                    }
                }
                if (isset($certificate['post_command']) && (!is_string($certificate['post_command']) || strpos($certificate['post_command'], "\0") !== false)) {
                    throw new InvalidArgumentException('certificates.' . $type . '.post_command must be a string without NUL bytes');
                }
            }
        }
    }

    /**
     * Reject unknown keys in a configuration section.
     *
     * @param array  $values  Configuration values
     * @param array  $allowed Allowed key names
     * @param string $prefix  Section path
     *
     * @return void
     * @throws InvalidArgumentException If an unknown key is present
     */
    private function assertAllowedKeys(array $values, array $allowed, string $prefix = ''): void
    {
        foreach (array_keys($values) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                throw new InvalidArgumentException('Unknown local config key: ' . $path);
            }
        }
    }

    /**
     * Require a non-empty absolute Unix path.
     *
     * @param string $value Configuration value
     * @param string $key   Configuration key path
     *
     * @return void
     * @throws InvalidArgumentException If the value is not an absolute path
     */
    private function assertAbsolutePath($value, string $key): void
    {
        if (!is_string($value) || $value === '' || $value[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException($key . ' must be a non-empty absolute path');
        }
    }

    /**
     * Apply local values after Froxlor settings have been loaded.
     *
     * @return void
     */
    private function applyLocalConfig(): void
    {
        $propertyMap = [
            'certificate_root' => '_certRoot',
            'lock_file'        => '_lockFile',
            'rate_limit_delay' => '_rateLimitDelay',
            'dns_retries'      => '_dnsRetries',
            'dns_retry_delay'  => '_dnsRetryDelay',
        ];
        foreach ($propertyMap as $key => $property) {
            if (array_key_exists($key, $this->_localConfig)) {
                $this->{$property} = $this->_localConfig[$key];
            }
        }

        $acmeMap = [
            'script_path' => '_acmeScriptPath',
            'server'      => '_acmeCA',
            'key_length'  => '_acmeKeyLength',
            'webroot'     => '_acmeWebRoot',
        ];
        foreach ($acmeMap as $key => $property) {
            if (isset($this->_localConfig['acme']) && array_key_exists($key, $this->_localConfig['acme'])) {
                $this->{$property} = $this->_localConfig['acme'][$key];
            }
        }

        if (isset($this->_localConfig['email'])) {
            foreach ($this->_localConfig['email'] as $key => $value) {
                $this->_emailConfig[$key] = $value;
            }
        }

        if (isset($this->_localConfig['certificates'])) {
            $certificates = [];
            foreach ($this->_localConfig['certificates'] as $type => $certificate) {
                $certificates[$type] = [
                    'subdomains'                  => array_values($certificate['subdomains']),
                    'include_hostname_subdomains' => $certificate['include_hostname_subdomains'] ?? false,
                    'additional_domains'          => array_values($certificate['additional_domains'] ?? []),
                    'post_command'                => $certificate['post_command'] ?? '',
                ];
            }
            $this->_subdomainConfig = $certificates;
        }

        if ($this->_certRoot !== '/') {
            $this->_certRoot = rtrim($this->_certRoot, '/');
        }
        if ($this->_acmeWebRoot !== '/') {
            $this->_acmeWebRoot = rtrim($this->_acmeWebRoot, '/');
        }
    }

    /**
     * Apply command-line operational overrides last.
     *
     * @return void
     */
    private function applyCliOptions(): void
    {
        $this->_dryRun = !empty($this->_cliOptions['dry_run']);
        $this->_force = !empty($this->_cliOptions['force']);
        $this->_testEmail = !empty($this->_cliOptions['test_email']);

        if ($this->_testEmail && !$this->_dryRun) {
            throw new InvalidArgumentException('--test-email requires --dry-run');
        }
        if ($this->_testEmail && !empty($this->_cliOptions['no_email'])) {
            throw new InvalidArgumentException('--test-email cannot be combined with --no-email');
        }

        if (!empty($this->_cliOptions['no_email'])) {
            $this->_emailConfig['enabled'] = false;
        }
        if ($this->_testEmail) {
            $this->_emailConfig['enabled'] = true;
        }
        if (!empty($this->_cliOptions['skip_dns_validation'])) {
            $this->_dnsValidation = false;
        }
        if (isset($this->_cliOptions['certificate'])) {
            $certificateType = $this->_cliOptions['certificate'];
            if (!array_key_exists($certificateType, $this->_subdomainConfig)) {
                throw new InvalidArgumentException(
                    'Unknown certificate type: ' . $certificateType
                    . '. Available types: ' . implode(', ', array_keys($this->_subdomainConfig))
                );
            }
            $this->_subdomainConfig = [
                $certificateType => $this->_subdomainConfig[$certificateType],
            ];
            $this->log('Selected certificate type: ' . $certificateType);
        }

        if ($this->_emailConfig['enabled']) {
            $this->log('Email notifications enabled using Froxlor mail settings');
        }
    }

    /**
     * Load Froxlor database configuration
     *
     * @throws Exception If config file not found or invalid
     * @return void
     */
    private function loadFroxlorConfig()
    {
        if (!is_file($this->_froxlorConfigPath) || !is_readable($this->_froxlorConfigPath)) {
            throw new InvalidArgumentException('Froxlor config file is not readable: ' . $this->_froxlorConfigPath);
        }

        $sql = (static function ($path) {
            $sql = null;
            require $path;
            return $sql;
        })($this->_froxlorConfigPath);

        if (!is_array($sql)) {
            throw new InvalidArgumentException('Database configuration not found in Froxlor config file');
        }

        foreach (['host', 'db', 'user', 'password'] as $key) {
            if (!array_key_exists($key, $sql) || !is_string($sql[$key])) {
                throw new InvalidArgumentException('Invalid Froxlor database configuration key: ' . $key);
            }
        }

        $this->_databaseConfig = $sql;
        $this->log('Froxlor config loaded from: ' . $this->_froxlorConfigPath);
    }

    /**
     * Establish database connection using loaded configuration
     *
     * @throws Exception If connection fails
     * @return void
     */
    private function connectDatabase()
    {
        try {
            $dsn = 'mysql:host=' . $this->_databaseConfig['host'] . ';dbname=' . $this->_databaseConfig['db'] . ';charset=utf8mb4';
            $this->_pdo = new PDO($dsn, $this->_databaseConfig['user'], $this->_databaseConfig['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $this->log('Database connected successfully');
        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Load ACME config from panel settings
     *
     * @throws Exception If ACME config not found
     * @return void
     */
    private function loadAcmeConfig()
    {
        $acmeSetting = $this->getSetting('system', ['acmeshpath', 'letsencryptca', 'letsencryptkeysize', 'letsencryptchallengepath']);

        if (empty($acmeSetting['letsencryptchallengepath'])) {
            throw new Exception('ACME challenge path not found in panel_settings');
        }

        if (!empty($acmeSetting['acmeshpath'])) {
            $this->_acmeScriptPath = $acmeSetting['acmeshpath'];
        }
        if (!empty($acmeSetting['letsencryptca'])) {
            $this->_acmeCA = $acmeSetting['letsencryptca'];
        }
        $this->_acmeWebRoot = rtrim($acmeSetting['letsencryptchallengepath'], '/');
        if (isset($acmeSetting['letsencryptkeysize']) && $acmeSetting['letsencryptkeysize'] !== '') {
            $this->_acmeKeyLength = $acmeSetting['letsencryptkeysize'];
        }
        $this->log('ACME config loaded');
    }

    /**
     * Retrieve data from panel settings
     *
     * @param string            $settinggroup Setting group name
     * @param string|array|null $varname      Optional setting name
     *
     * @return string|array|null Setting value(s) or null if not found
     */
    private function getSetting($settinggroup, $varname = null)
    {
        $sql = 'SELECT varname, value FROM panel_settings WHERE settinggroup = :group';
        $params = [':group' => $settinggroup];

        if ($varname !== null) {
            if (is_array($varname)) {
                $placeholders = [];
                foreach ($varname as $i => $v) {
                    $key = ":var$i";
                    $placeholders[] = $key;
                    $params[$key] = $v;
                }
                $sql .= ' AND varname IN (' . implode(',', $placeholders) . ')';
            } else {
                $sql .= ' AND varname = :var';
                $params[':var'] = $varname;
            }
        }

        $stmt = $this->_pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // returns [varname => value]

        if ($varname === null) {
            return $rows;
        }

        if (is_array($varname)) {
            return $rows;
        }

        return $rows[$varname] ?? null;
    }

    /**
     * Detect server IP addresses (IPv4 and IPv6)
     *
     * @return void
     */
    private function detectServerIPs()
    {
        $this->log('Detecting server IP addresses...');

        $ipv4Output = shell_exec("hostname -I 2>/dev/null || ip addr show | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | cut -d'/' -f1");
        if ($ipv4Output) {
            $ipv4s = preg_split('/\s+/', trim($ipv4Output), -1, PREG_SPLIT_NO_EMPTY);
            $this->_serverIPs = array_merge($this->_serverIPs, $ipv4s);
        }

        $ipv6Output = shell_exec("ip -6 addr show | grep 'inet6' | grep -v '::1' | grep -v 'fe80:' | awk '{print $2}' | cut -d'/' -f1 2>/dev/null");
        if ($ipv6Output) {
            $ipv6s = preg_split('/\s+/', trim($ipv6Output), -1, PREG_SPLIT_NO_EMPTY);
            $this->_serverIPs = array_merge($this->_serverIPs, $ipv6s);
        }

        $this->_serverIPs = array_values(array_unique(array_filter($this->_serverIPs)));

        if (empty($this->_serverIPs)) {
            $this->log('WARNING: Could not detect server IP addresses. DNS validation will be skipped.');
            $this->_dnsValidation = false;
        } else {
            $this->log('Detected server IPs: ' . implode(', ', $this->_serverIPs));
        }
    }

    /**
     * Validate DNS records for domains
     *
     * @param array $domains List of domains to validate
     * @return array List of validated domains
     */
    private function validateDNS(array $domains): array
    {
        if (!$this->_dnsValidation) {
            $this->log('DNS validation disabled, skipping...');
            return $domains;
        }

        $this->log('Validating DNS records for ' . count($domains) . ' domains...');
        $validDomains = [];
        $invalidDomains = [];

        foreach ($domains as $domain) {
            if (!$this->validateDomainName($domain)) {
                $this->log('WARNING: Invalid domain name format: ' . $domain);
                $invalidDomains[] = $domain;
                continue;
            }
            if ($this->isDomainPointingToServer($domain)) {
                $validDomains[] = $domain;
            } else {
                $invalidDomains[] = $domain;
                $this->log('WARNING: Domain ' . $domain . ' does not point to this server');

                $this->_errors[] = [
                    'type'        => 'dns_validation',
                    'cert_type'   => 'validation',
                    'return_code' => 0,
                    'output'      => 'Domain ' . $domain . ' does not resolve to server IPs: ' . implode(', ', $this->_serverIPs),
                    'domain'      => $domain
                ];
            }
        }

        if (!empty($invalidDomains)) {
            $this->log('DNS validation failed for domains: ' . implode(', ', $invalidDomains));
            $this->log('These domains will be excluded from certificate generation');
        }

        $this->log('DNS validation completed. Valid domains: ' . count($validDomains) . '/' . count($domains));

        return $validDomains;
    }

    /**
     * Check if domain resolves to server IP
     *
     * @param string $domain Domain to check
     *
     * @return boolean True if domain points to server
     */
    private function isDomainPointingToServer(string $domain): bool
    {
        $cacheKey = strtolower($domain);
        if (array_key_exists($cacheKey, $this->_dnsValidationCache)) {
            return $this->_dnsValidationCache[$cacheKey];
        }

        $resolved = false;
        $lastError = null;

        // Retry DNS lookups to handle transient failures
        for ($attempt = 1; $attempt <= $this->_dnsRetries; $attempt++) {
            try {
                // Check A record (IPv4)
                $aRecords = @dns_get_record($domain, DNS_A);
                if ($aRecords !== false && is_array($aRecords)) {
                    foreach ($aRecords as $record) {
                        if (isset($record['ip']) && in_array($record['ip'], $this->_serverIPs, true)) {
                            $resolved = true;
                            break 2; // Break out of both foreach and for loop
                        }
                    }
                }

                // Check AAAA record (IPv6) if not resolved yet
                if (!$resolved) {
                    $aaaaRecords = @dns_get_record($domain, DNS_AAAA);
                    if ($aaaaRecords !== false && is_array($aaaaRecords)) {
                        foreach ($aaaaRecords as $record) {
                            if (isset($record['ipv6']) && in_array($record['ipv6'], $this->_serverIPs, true)) {
                                $resolved = true;
                                break 2; // Break out of both foreach and for loop
                            }
                        }
                    }
                }

                // Alternative method using nslookup if dns_get_record fails
                if (!$resolved && (empty($aRecords) && empty($aaaaRecords))) {
                    $nslookupOutput = shell_exec('nslookup ' . escapeshellarg($domain) . " 2>/dev/null | grep 'Address:' | grep -v '#' | awk '{print $2}'");
                    if ($nslookupOutput) {
                        $resolvedIPs = array_filter(array_map('trim', explode("\n", trim($nslookupOutput))));
                        foreach ($resolvedIPs as $ip) {
                            if (in_array($ip, $this->_serverIPs, true)) {
                                $resolved = true;
                                break 2; // Break out of both foreach and for loop
                            }
                        }
                    }
                }

                // If we got valid DNS records (even if they don't point to this server), stop retrying
                if (($aRecords !== false && !empty($aRecords)) || ($aaaaRecords !== false && !empty($aaaaRecords))) {
                    break;
                }

                // If no records found and not the last attempt, wait before retrying
                if ($attempt < $this->_dnsRetries) {
                    $lastError = "DNS lookup attempt $attempt failed for domain: $domain";
                    sleep($this->_dnsRetryDelay);
                }
            } catch (Exception $e) {
                $lastError = "DNS lookup exception on attempt $attempt for $domain: " . $e->getMessage();
                if ($attempt < $this->_dnsRetries) {
                    sleep($this->_dnsRetryDelay);
                }
            }
        }

        // Log if we had to retry
        if ($lastError && $resolved) {
            $this->log("DNS lookup succeeded after retries for: $domain");
        }

        $this->_dnsValidationCache[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * Retrieve domains from panel_domains table
     *
     * @return array List of domains
     */
    private function getDomains(): array
    {
        $stmt = $this->_pdo->prepare('SELECT D.domain
        FROM panel_domains D
        INNER JOIN panel_customers C ON C.customerid = D.customerid
        WHERE D.parentdomainid = 0
            AND C.deactivated = 0
        ORDER BY 1');

        $stmt->execute();
        $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->log('Found ' . count($domains) . ' domains');

        return $domains;
    }

    /**
     * Generate certificates for all domain configurations
     *
     * @return void
     */
    private function generateCertificates()
    {
        $domains = $this->getDomains();
        $postCommands = [];

        $this->log('Using hostname: ' . $this->_hostname);
        echo "\n"; // Add line spacing

        foreach ($this->_subdomainConfig as $certType => $config) {
            $this->log('Processing certificate type: ' . $certType);

            $sanDomains = [];

            $sanDomains[] = $this->_hostname;

            if ($config['include_hostname_subdomains']) {
                foreach ($config['subdomains'] as $subdomain) {
                    $sanDomains[] = "{$subdomain}.{$this->_hostname}";
                }
            }

            foreach ($domains as $domain) {
                foreach ($config['subdomains'] as $subdomain) {
                    $sanDomains[] = "{$subdomain}.{$domain}";
                }
            }

            if (!empty($config['additional_domains']) && is_array($config['additional_domains'])) {
                foreach ($config['additional_domains'] as $additionalDomain) {
                    if (!empty($additionalDomain) && is_string($additionalDomain)) {
                        foreach ($config['subdomains'] as $subdomain) {
                            $fullDomain = "{$subdomain}.{$additionalDomain}";
                            $sanDomains[] = $fullDomain;
                            $this->log('Added additional domain: ' . $fullDomain);
                        }
                    }
                }
            }

            // Avoid duplicate validation and duplicate ACME -d arguments.
            $sanDomains = array_values(array_unique(array_map('strtolower', $sanDomains)));

            $validatedDomains = $this->validateDNS($sanDomains);

            if (empty($validatedDomains)) {
                $this->log('No valid domains found for certificate type: ' . $certType . '. Skipping...');
                $this->_errors[] = [
                    'type'        => 'no_valid_domains',
                    'cert_type'   => $certType,
                    'return_code' => 0,
                    'output'      => 'All domains failed DNS validation for certificate type: ' . $certType,
                    'domains'     => $sanDomains
                ];
                continue;
            }

            if (count($validatedDomains) < count($sanDomains)) {
                $this->log('Some domains failed validation. Proceeding with ' . count($validatedDomains) . ' valid domains.');
            }

            $certHome = rtrim($this->_certRoot, '/') . '/' . $certType;
            $certificateGenerated = $this->issueCertificate($certType, $validatedDomains, $certHome);

            if ($certificateGenerated && !empty($config['post_command']) && !in_array($config['post_command'], $postCommands, true)) {
                $postCommands[] = $config['post_command'];
            }
        }

        $this->executePostCommands($postCommands);
    }

    /**
     * Issue certificate for domain set
     *
     * @param string $certType   Certificate type (mail, web, cpanel)
     * @param array  $sanDomains List of domains for certificate
     * @param string $certHome   Directory to store certificates
     *
     * @return boolean True if certificate was generated successfully
     */
    private function issueCertificate(string $certType, array $sanDomains, string $certHome): bool
    {
        if (!$this->_dryRun && !is_dir($certHome)) {
            if (!@mkdir($certHome, 0755, true)) {
                $error = error_get_last();
                throw new Exception('Failed to create certificate directory: ' . $certHome . ' - ' . ($error['message'] ?? 'Unknown error'));
            }
            $this->log('Created certificate directory: ' . $certHome);
        }

        // https://github.com/acmesh-official/acme.sh/wiki/Options-and-Params
        $cmd = escapeshellarg($this->_acmeScriptPath) . ' --issue';
        if ($this->_force) {
            $cmd .= ' --force';
        }
        $cmd .= ' --server ' . escapeshellarg($this->_acmeCA);
        $cmd .= ' --keylength ' . escapeshellarg((string) $this->_acmeKeyLength);
        $cmd .= ' -d ' . implode(' -d ', array_map('escapeshellarg', $sanDomains));
        $cmd .= ' -w ' . escapeshellarg($this->_acmeWebRoot);
        $cmd .= ' --cert-home ' . escapeshellarg($certHome);
        $cmd .= ' --cert-file ' . escapeshellarg($certHome . '/cert.pem');
        $cmd .= ' --key-file ' . escapeshellarg($certHome . '/key.pem');
        $cmd .= ' --fullchain-file ' . escapeshellarg($certHome . '/fullchain.pem');

        $this->log('Issuing certificate for: ' . $certType);
        $this->log('Command: ' . $cmd);
        $this->log('Certificate will be stored in: ' . $certHome);

        if ($this->_dryRun) {
            $this->log('DRY RUN: Would execute acme.sh command');
            echo "\n"; // Add line spacing
            return true; // Assume success in dry-run mode
        }

        // Rate-limit actual ACME requests, but never delay a dry run.
        static $lastRequest = 0;
        $now = microtime(true);
        $elapsed = $now - $lastRequest;
        if ($elapsed < $this->_rateLimitDelay) {
            usleep((int) (($this->_rateLimitDelay - $elapsed) * 1000000));
        }
        $lastRequest = microtime(true);

        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);

        $certificateGenerated = $this->processAcmeResult(
            $certType,
            $sanDomains,
            $output,
            $returnCode
        );

        if ($certificateGenerated) {
            $certFiles = ['cert.pem', 'key.pem', 'fullchain.pem'];
            foreach ($certFiles as $file) {
                $path = $certHome . '/' . $file;
                if (file_exists($path)) {
                    chmod($path, 0600);
                }
            }
            chmod($certHome, 0700);
        }

        echo "\n"; // Add spacing between certificate types

        return $certificateGenerated;
    }

    /**
     * Classify an acme.sh result and record only genuine failures.
     *
     * @param string  $certType   Certificate type
     * @param array   $sanDomains Certificate domain names
     * @param array   $output     Command output lines
     * @param integer $returnCode Process exit status
     *
     * @return boolean True only when a certificate was issued or renewed
     */
    private function processAcmeResult(string $certType, array $sanDomains, array $output, int $returnCode): bool
    {
        if ($returnCode === 0) {
            $this->log('Certificate generated successfully for: ' . $certType);
            return true;
        }

        if ($returnCode === self::ACME_RENEWAL_SKIPPED) {
            $this->log('Certificate renewal not required for: ' . $certType);
            $this->log('Output: ' . implode("\n", $output));
            return false;
        }

        $this->log('Certificate generation failed for: ' . $certType);
        $this->log('Return code: ' . $returnCode);
        $this->log('Output: ' . implode("\n", $output));

        $this->_errors[] = [
            'type'        => 'certificate_generation',
            'cert_type'   => $certType,
            'return_code' => $returnCode,
            'output'      => implode("\n", $output),
            'domains'     => $sanDomains,
        ];

        return false;
    }

    /**
     * Execute post-generation commands
     *
     * @param array $postCommands Array of unique post-commands to execute
     *
     * @return void
     */
    private function executePostCommands(array $postCommands): void
    {
        if (empty($postCommands)) {
            return;
        }

        if ($this->_dryRun) {
            $this->log('DRY RUN: Would execute post-commands: ' . implode(', ', $postCommands));
            return;
        }

        $this->log('Executing post-generation commands...');

        foreach ($postCommands as $postCommand) {
            $this->log('Executing post-command: ' . $postCommand);
            $postOutput = [];
            $postReturnCode = 0;
            exec($postCommand . ' 2>&1', $postOutput, $postReturnCode);

            if ($postReturnCode === 0) {
                $this->log('Post-command executed successfully');
            } else {
                $this->log('Post-command failed with return code: ' . $postReturnCode);
                $this->log('Post-command output: ' . implode("\n", $postOutput));

                $this->_errors[] = [
                    'type'        => 'post_command',
                    'cert_type'   => 'final',
                    'command'     => $postCommand,
                    'return_code' => $postReturnCode,
                    'output'      => implode("\n", $postOutput),
                ];
            }
        }
    }

    /**
     * Validate subdomain configuration
     *
     * @throws Exception If configuration is invalid
     * @return void
     */
    private function validateConfig(): void
    {
        $this->assertAbsolutePath($this->_froxlorConfigPath, 'froxlor_config');
        $this->assertAbsolutePath($this->_certRoot, 'certificate_root');
        $this->assertAbsolutePath($this->_lockFile, 'lock_file');
        $this->assertAbsolutePath($this->_acmeScriptPath, 'acme.script_path');
        $this->assertAbsolutePath($this->_acmeWebRoot, 'acme.webroot');

        if (!is_string($this->_acmeCA) || trim($this->_acmeCA) === '' || preg_match('/[\x00-\x1F\x7F]/', $this->_acmeCA)) {
            throw new InvalidArgumentException('acme.server must be a non-empty string');
        }
        if (!$this->validateAcmeKeyLength($this->_acmeKeyLength)) {
            throw new InvalidArgumentException('acme.key_length must be a positive integer or a supported ec-* value');
        }
        if (!is_int($this->_rateLimitDelay) || $this->_rateLimitDelay < 0) {
            throw new InvalidArgumentException('rate_limit_delay must be a non-negative integer');
        }
        if (!is_int($this->_dnsRetries) || $this->_dnsRetries < 1) {
            throw new InvalidArgumentException('dns_retries must be an integer greater than zero');
        }
        if (!is_int($this->_dnsRetryDelay) || $this->_dnsRetryDelay < 0) {
            throw new InvalidArgumentException('dns_retry_delay must be a non-negative integer');
        }

        if (!$this->_dryRun) {
            if (!is_file($this->_acmeScriptPath) || !is_executable($this->_acmeScriptPath)) {
                throw new Exception('ACME script is not executable: ' . $this->_acmeScriptPath);
            }
            if (!is_dir($this->_acmeWebRoot)) {
                throw new Exception('ACME webroot directory not found: ' . $this->_acmeWebRoot);
            }
        }

        if (!is_array($this->_subdomainConfig) || empty($this->_subdomainConfig)) {
            throw new Exception('Subdomain configuration is empty or invalid');
        }

        foreach ($this->_subdomainConfig as $type => $config) {
            if (!is_string($type) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $type)) {
                throw new Exception('Invalid certificate type: ' . (string) $type);
            }
            if (!isset($config['subdomains']) || !is_array($config['subdomains']) || empty($config['subdomains'])) {
                throw new Exception("Invalid subdomains for type: $type");
            }

            foreach ($config['subdomains'] as $subdomain) {
                if (!is_string($subdomain) || !$this->validateDnsLabel($subdomain)) {
                    throw new Exception("Invalid subdomain format: $subdomain");
                }
            }

            if (!isset($config['include_hostname_subdomains'])
                || !is_bool($config['include_hostname_subdomains'])
            ) {
                throw new Exception("Invalid include_hostname_subdomains for type: $type");
            }

            if (isset($config['post_command']) && (!is_string($config['post_command']) || strpos($config['post_command'], "\0") !== false)) {
                throw new Exception("Invalid post_command for type: $type");
            }

            if (isset($config['additional_domains'])) {
                if (!is_array($config['additional_domains'])) {
                    throw new Exception("Invalid additional_domains for type: $type - must be an array");
                }

                foreach ($config['additional_domains'] as $domain) {
                    if (!empty($domain) && !$this->validateDomainName($domain)) {
                        throw new Exception("Invalid domain format in additional_domains for type $type: $domain");
                    }
                }
            }
        }

        if (!is_bool($this->_emailConfig['enabled'])) {
            throw new InvalidArgumentException('email.enabled must be a boolean');
        }
        if ($this->_emailConfig['enabled']) {
            if (!is_string($this->_emailConfig['subject']) || trim($this->_emailConfig['subject']) === '') {
                throw new InvalidArgumentException('email.subject must be a non-empty string');
            }
            if (preg_match('/[\r\n]/', $this->_emailConfig['subject'])) {
                throw new InvalidArgumentException('email.subject must not contain line breaks');
            }
        }
    }

    /**
     * Acquire process lock
     *
     * @throws Exception If lock cannot be acquired
     * @return void
     */
    private function acquireLock(): void
    {
        $this->_lockHandle = @fopen($this->_lockFile, 'c+');

        if (!$this->_lockHandle) {
            throw new Exception("Cannot create lock file: {$this->_lockFile}");
        }

        if (!flock($this->_lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->_lockHandle);
            throw new Exception('Another instance is already running');
        }

        ftruncate($this->_lockHandle, 0);
        fwrite($this->_lockHandle, getmypid());
    }

    /**
     * Release process lock
     *
     * @return void
     */
    private function releaseLock(): void
    {
        if ($this->_lockHandle) {
            ftruncate($this->_lockHandle, 0);
            flock($this->_lockHandle, LOCK_UN);
            fclose($this->_lockHandle);
            $this->_lockHandle = null;
        }
    }

    /**
     * Log message with timestamp
     *
     * @param string $message Message to log
     *
     * @return void
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
    }

    /**
     * Send notification email for errors
     *
     * If email notifications are disabled or no errors occurred, this method does nothing.
     * If dry-run mode is enabled, it also does nothing.
     *
     * @return void
     */
    private function sendErrorNotification()
    {
        if (!$this->_emailConfig['enabled'] || empty($this->_errors) || $this->_dryRun) {
            return;
        }

        $subject = $this->_emailConfig['subject'] . ' @ ' . $this->_hostname;

        $body  = "Certificate generation completed with errors on server: {$this->_hostname}\n\n";
        $body .= 'Timestamp: ' . date('Y-m-d H:i:s') . "\n";
        $body .= 'Total errors: ' . count($this->_errors) . "\n\n";

        foreach ($this->_errors as $i => $error) {
            $body .= 'Error ' . ($i + 1) . ":\n";
            $body .= 'Type: ' . str_replace('_', ' ', $error['type']) . "\n";
            $body .= 'Certificate Type: ' . $error['cert_type'] . "\n";

            if ($error['type'] === 'certificate_generation') {
                $body .= 'Domains: ' . implode(', ', $error['domains']) . "\n";
            } elseif ($error['type'] === 'post_command') {
                $body .= 'Command: ' . $error['command'] . "\n";
            }

            $body .= 'Return Code: ' . $error['return_code'] . "\n";
            $body .= "Output:\n" . $error['output'] . "\n";
            $body .= str_repeat('-', 50) . "\n\n";
        }

        $body .= 'Script: ' . __FILE__ . "\n";

        $this->sendEmail($subject, $body);
    }

    /**
     * Send an email through Froxlor's v2 mailer and settings.
     *
     * @param string $subject Email subject
     * @param string $body    Email body
     *
     * @return boolean True when Froxlor accepted the message for delivery
     */
    private function sendEmail(string $subject, string $body): bool
    {
        try {
            $froxlorRoot = dirname(dirname($this->_froxlorConfigPath));
            $defaultConfig = $froxlorRoot . '/lib/userdata.inc.php';
            $resolvedConfig = realpath($this->_froxlorConfigPath);
            if ($resolvedConfig === false || $resolvedConfig !== realpath($defaultConfig)) {
                throw new Exception('Froxlor mail delivery requires its standard lib/userdata.inc.php path');
            }

            $autoloadPath = $froxlorRoot . '/vendor/autoload.php';
            $tablesPath = $froxlorRoot . '/lib/tables.inc.php';
            foreach ([$autoloadPath, $tablesPath] as $path) {
                if (!is_file($path) || !is_readable($path)) {
                    throw new Exception('Required Froxlor mailer file is not readable: ' . $path);
                }
            }

            require_once $autoloadPath;
            require_once $tablesPath;

            $recipient = \Froxlor\Settings::Get('panel.adminmail');
            $recipientName = (string) \Froxlor\Settings::Get('panel.adminmail_defname');
            if (!is_string($recipient) || trim($recipient) === '') {
                throw new Exception('Froxlor panel.adminmail is empty');
            }

            $mail = new \Froxlor\System\Mailer(true);
            $mail->Subject = $subject;
            $mail->AltBody = $body;
            $mail->msgHTML($this->formatEmailBody($body));
            $mail->addAddress($recipient, $recipientName);
            if (!$mail->send()) {
                throw new Exception('Froxlor mailer rejected the message: ' . $mail->ErrorInfo);
            }

            $this->log('Notification email sent using Froxlor mail settings');
            return true;
        } catch (Throwable $e) {
            $this->log('Failed to send notification email through Froxlor: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Convert a plain-text notification to safe HTML while preserving lines.
     *
     * @param string $body Plain-text message
     *
     * @return string Escaped HTML message
     */
    private function formatEmailBody(string $body): string
    {
        return nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Send an explicitly requested dry-run test notification.
     *
     * @return void
     * @throws Exception If Froxlor cannot send the test notification
     */
    private function sendTestNotification(): void
    {
        $subject = '[TEST] ' . $this->_emailConfig['subject'] . ' @ ' . $this->_hostname;
        $body  = "This is a test notification from the ACME SAN certificate generator.\n\n";
        $body .= 'Server: ' . $this->_hostname . "\n";
        $body .= 'Timestamp: ' . date('Y-m-d H:i:s') . "\n";
        $body .= "Mode: dry run with --test-email\n";
        $body .= 'Script: ' . __FILE__ . "\n";

        if (!$this->sendEmail($subject, $body)) {
            // Avoid a second delivery attempt from run()'s error handler.
            $this->_emailConfig['enabled'] = false;
            throw new Exception('Froxlor test email delivery failed');
        }
    }

    /**
     * Execute the certificate generation process
     *
     * @return integer Zero on success, one when an operational error occurred
     */
    public function run(): int
    {
        $exitCode = 0;

        try {
            $this->log('Starting ACME.sh SAN certificate generation');

            if ($this->_dryRun) {
                $this->log('DRY RUN MODE ENABLED');
                echo "\n"; // Add spacing
            }

            $this->generateCertificates();
            $this->log('Certificate generation process completed');

            $this->sendErrorNotification();

            if ($this->_testEmail) {
                $this->sendTestNotification();
            }

            if (!empty($this->_errors)) {
                $exitCode = 1;
            }
        } catch (Throwable $e) {
            $this->log('ERROR: ' . $e->getMessage());

            $this->_errors[] = [
                'type'        => 'critical_error',
                'cert_type'   => 'N/A',
                'return_code' => 1,
                'output'      => $e->getMessage(),
                'trace'       => $e->getTraceAsString()
            ];

            $this->sendErrorNotification();
            $exitCode = 1;
        } finally {
            $this->releaseLock();
        }

        return $exitCode;
    }

    /**
     * Validate a single DNS label.
     *
     * @param string $label DNS label to validate
     *
     * @return boolean True if valid, false otherwise
     */
    private function validateDnsLabel(string $label): bool
    {
        return (
            strlen($label) <= 63
            && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)
        );
    }

    /**
     * Validate an acme.sh RSA or elliptic-curve key-length value.
     *
     * @param integer|string $value Key-length value
     *
     * @return boolean
     */
    private function validateAcmeKeyLength($value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && preg_match('/^(?:[1-9][0-9]*|ec-(?:256|384|521))$/', $value);
    }

    /**
     * Validate a domain name for RFC 1034/1035 compliance.
     *
     * @param string $domain Domain name to validate
     *
     * @return boolean True if valid, false otherwise
     */
    private function validateDomainName(string $domain): bool
    {
        return (
            preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $domain)
            && strlen($domain) <= 255
        );
    }

    /**
     * Print command-line usage information.
     *
     * @param string $scriptName Invoked script name
     *
     * @return void
     */
    public static function printUsage(string $scriptName): void
    {
        echo "ACME.sh SAN Certificate Generator\n\n";
        echo 'Usage: php ' . $scriptName . " [options]\n\n";
        echo "Options:\n";
        echo "  --froxlor-config=PATH       Froxlor credentials file\n";
        echo "                              Default: /var/www/html/froxlor/lib/userdata.inc.php\n";
        echo "  --local-config=PATH         Required explicit local configuration file\n";
        echo '                              Default when present: ' . __DIR__ . "/acme.local.php\n";
        echo "  --certificate=TYPE         Process one configured certificate type\n";
        echo "  --install                  Install cron and logrotate for this script\n";
        echo "  --dry-run                   Show actions without writing certificates or reloading services\n";
        echo "  --test-email                With --dry-run, send one test email through Froxlor\n";
        echo "  --no-email                  Disable email notifications\n";
        echo "  --skip-dns-validation       Skip DNS validation of domains\n";
        echo "  --force                     Force certificate generation even if an existing certificate is current\n";
        echo "  --help                      Show this help message\n\n";
        echo "Configuration:\n";
        echo "  The script automatically loads acme.local.php from its own directory when that file exists.\n";
        echo "  Use --local-config=PATH to require a different local file.\n";
        echo "  Local configuration controls certificates, additional domains, post-commands, paths,\n";
        echo "  retry timing, ACME overrides, and email notifications. See acme.local.example.php.\n";
        echo "  Froxlor controls the notification recipient, sender, reply-to, and mail transport.\n";
        echo "  CLI operational flags take precedence over local and Froxlor settings.\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    if (php_sapi_name() !== 'cli') {
        echo "This script must be run from command line\n";
        exit(1);
    }

    try {
        $options = AcmeSanCertificateGenerator::parseArguments(array_slice($argv ?? [], 1));
        if (!empty($options['help'])) {
            AcmeSanCertificateGenerator::printUsage(basename(__FILE__));
            exit(0);
        }
        if (!empty($options['install'])) {
            exit(AcmeSanCertificateGenerator::install(!empty($options['dry_run'])));
        }

        $generator = new AcmeSanCertificateGenerator($options);
        exit($generator->run());
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . "\n");
        fwrite(STDERR, 'Run php ' . basename(__FILE__) . " --help for usage.\n");
        exit(2);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
