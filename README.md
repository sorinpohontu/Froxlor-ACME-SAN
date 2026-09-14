# Froxlor ACME SAN

`acme.php` generates SAN certificates with [acme.sh](https://github.com/acmesh-official/acme.sh) for active primary domains managed by Froxlor. It builds separate certificates for configurable service prefixes such as `mail`, `webmail`, and `dav`, validates DNS, installs certificate files, and runs each successful post-generation command once.

## Requirements

- PHP 7.4 or newer with PDO MySQL and DNS support (matching Froxlor v2)
- A working Froxlor installation and readable `userdata.inc.php`
- `acme.sh` configured by Froxlor
- Permission to read Froxlor settings, write the certificate and lock paths, and run configured service commands
- Network access for DNS resolution and ACME challenges

The script is intended to run from the command line under a trusted administrative account. Local configuration and post-generation commands must be treated as root-level configuration.

Before its first timestamped message, the script detects the operating-system timezone from `/etc/timezone`, the `/etc/localtime` zoneinfo link, or `timedatectl`. A detected identifier is validated against PHP's timezone database before it is applied; if detection fails, PHP's configured timezone remains in effect. Daylight-saving changes are handled by the selected timezone identifier.

## Installation

Install the project in `/opt/AcmeSan`. Copy the example configuration and restrict access to the resulting local file:

```sh
cd /opt/AcmeSan
cp acme.local.example.php acme.local.php
chmod 600 acme.local.php
```

Review every local value before the first run. `acme.local.php` is ignored by Git.

## Configuration sources and precedence

The script combines four sources in this order:

1. General defaults in `acme.php`
2. ACME settings and the mail system provided by Froxlor
3. Overrides returned by `acme.local.php`
4. Command-line operational flags

The Froxlor credentials path is resolved before connecting to the database: the built-in default can be replaced by `froxlor_config` locally and then by `--froxlor-config=PATH` on the command line.

The script automatically loads `acme.local.php` from its own directory when it exists. Use `--local-config=PATH` to select a different file; an explicitly selected file is required and invalid or unknown configuration keys are rejected.

Local configuration must return an array. See [`acme.local.example.php`](acme.local.example.php) for every supported section. Scalar fields replace defaults, `acme` and notification preferences merge by named key, and a local `certificates` section replaces the complete built-in certificate map. Lists such as `subdomains` and `additional_domains` are replacements, not additions.

Froxlor manages the notification recipient and mail transport. Local configuration can enable or disable error notifications and change their subject, but cannot replace Froxlor's recipient or transport settings. ACME settings can still be overridden locally when required.

## Command-line options

```text
--froxlor-config=PATH       Use a different Froxlor userdata.inc.php
--local-config=PATH         Require a different local configuration file
--certificate=TYPE         Process one configured certificate type
--install                  Install cron and logrotate for this script
--dry-run                   Show actions without certificate or service changes
--test-email                With --dry-run, send one test email through Froxlor
--no-email                  Disable error notifications
--skip-dns-validation       Skip DNS validation
--force                     Force certificate generation
--help                      Show built-in help
```

Unknown options, positional arguments, and the removed `--config-path` option are rejected. Invocation and configuration errors exit with status `2`; operational failures exit with status `1`.

Start with help and a dry run:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --help
/usr/bin/php /opt/AcmeSan/acme.php --dry-run
/usr/bin/php /opt/AcmeSan/acme.php --dry-run --test-email
```

Before a scoped forced renewal, verify the selected certificate with a dry run:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --dry-run --certificate=mail
/usr/bin/php /opt/AcmeSan/acme.php --force --certificate=mail
```

The selected type must exist in the effective `certificates` map after local configuration is applied. A forced live run requests a certificate from the configured CA, replaces its output files on success, and runs that type's configured post-command. Run it only during a monitored service window.

An explicit configuration can be checked with:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --dry-run --local-config=/opt/AcmeSan/acme.local.php
```

Dry runs still read Froxlor and perform DNS lookups, but do not create the process lock, certificate directories, certificates, or service reloads. They do not send email unless `--test-email` is explicitly supplied. `--test-email` requires `--dry-run` and cannot be combined with `--no-email`.

## Certificates and DNS

For every configured certificate type, the server hostname is the primary name. Configured prefixes are prepended to each active primary Froxlor domain and each `additional_domains` entry. Duplicate names are removed while preserving order.

Use `--certificate=TYPE` to restrict processing to one configured certificate. Without it, every configured certificate type is processed.

DNS validation retains names whose A or AAAA records match an address detected on the server. Failed names are excluded and reported. `--skip-dns-validation` is intended for deliberate troubleshooting and should not normally be used by cron.

Certificates default to `/etc/ssl/acme-sans/<type>/`. The script writes `cert.pem`, `key.pem`, and `fullchain.pem`, then restricts successful output files to mode `0600` and their certificate directory to `0700`.

Certificate authorities impose limits on names per certificate. Keep each generated SAN set within the current limits of the configured CA; this script does not split a certificate automatically.

## Notifications and post-commands

Froxlor's administrator email, sender, reply-to address, and transport configuration are authoritative. The script loads Froxlor v2's own `Froxlor\System\Mailer`, so `mail_use_smtp` and all related SMTP behavior are interpreted by Froxlor rather than duplicated here. Local configuration controls only notification enablement and subject, while `--no-email` disables notification for one run. Normal notifications are sent only for errors and are disabled during dry runs; `--test-email` is the explicit exception for testing delivery.

Post-generation commands run only for certificate types that completed successfully. Identical commands are deduplicated and run once after all certificate attempts. They are trusted shell commands: never accept their values from an untrusted source.

acme.sh exit status `2` means renewal was skipped because it is not currently required. The script logs that result as normal, does not send an error notification, and does not reload services for the unchanged certificate.

## Cron

After placing the project in its final location, preview and install the scheduler configuration:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --install --dry-run
/usr/bin/php /opt/AcmeSan/acme.php --install
```

The installer resolves the current `acme.php` path and prefers the stable `/usr/bin/php` launcher so the cron entry follows the system's configured PHP version. If that launcher is unavailable, it falls back to the currently running PHP CLI executable. It does not assume `/opt/AcmeSan`, copy project files, load Froxlor, or run certificate logic. Preview does not require root, while installation does. It creates or updates only files carrying its managed marker and refuses to overwrite unmanaged files.

The generated `/etc/cron.d/acme-san` contains:

```cron
# Managed by AcmeSan --install
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
MAILTO=root

# Daily ACME SAN certificate check
17 3 * * * root /usr/bin/php /opt/AcmeSan/acme.php >> /var/log/acme-san.log 2>&1
```

Unlike a personal crontab, an `/etc/cron.d` entry requires the `root` user field between the schedule and command. Keep the filename lowercase and end the file with a newline. Set its ownership and mode, then confirm cron is running:

```sh
chown root:root /etc/cron.d/acme-san
chmod 0644 /etc/cron.d/acme-san
systemctl is-active cron
```

The log redirection is not required for certificate generation. It is recommended because configuration, PHP, or database failures that occur before Froxlor's mailer is available can otherwise be lost. Without redirection, cron normally emails all stdout and stderr, including routine renewal-skip output.

The installer also creates `/etc/logrotate.d/acme-san`:

```text
# Managed by AcmeSan --install
/var/log/acme-san.log {
    weekly
    rotate 12
    compress
    delaycompress
    missingok
    notifempty
    create 0640 root adm
}
```

When upgrading from the old CLI, deploy the new script and replace `--config-path` with `--froxlor-config` in the cron entry as one controlled change. There is intentionally no compatibility alias.

## Troubleshooting

- **Configuration error:** run `php acme.php --help`, check that local files return arrays, and correct the exact unknown or invalid key shown.
- **Froxlor/database error:** verify the `userdata.inc.php` path, permissions, credentials, PDO MySQL extension, and database availability.
- **DNS exclusions:** compare public A and AAAA answers with the server addresses logged by the script.
- **Lock error:** another non-dry-run instance may be active. Inspect the configured lock file and process; do not delete it while a process is running.
- **ACME failure:** review the logged, shell-quoted command and acme.sh output, then verify the challenge webroot and CA configuration.
- **Notification failure:** verify Froxlor's administrator address and mail settings, plus the readability of Froxlor's `vendor/autoload.php` and `lib/tables.inc.php`. Transport failures follow Froxlor's own mailer behavior; the script does not add a separate fallback.
- **Permission failure:** verify the execution user can write the certificate and lock locations and run every configured post-command.

## Deployment checklist

1. Review `acme.local.php`, ownership, and mode.
2. Run PHP syntax and coding-standard checks.
3. Run `php acme.php --help`.
4. Run `php acme.php --dry-run` using the production command and user.
5. Review certificate types, SANs, paths, and post-commands in the output.
6. Run one monitored, non-forced certificate cycle.
7. Verify exit status, certificate SANs and permissions, services, notifications, and the next scheduled cron run.

## License

BSD 3-Clause. See [`LICENSE`](LICENSE).
