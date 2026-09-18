# Froxlor ACME SAN

Generate and renew service certificates for the active customer domains managed by [Froxlor](https://www.froxlor.org).
The script creates one (or more) Subject Alternative Name (SAN) certificate per service definition—for example `mail`, `webmail`, or `dav`—using Froxlor's [acme.sh](https://github.com/acmesh-official/acme.sh) configuration.

It provides:

- Automatic discovery of active primary Froxlor domains
- A separate certificate and post-command for each configured service
- A and AAAA validation before names are sent to acme.sh
- Automatic renewal when a certificate is due or its domain set changes
- Error notifications through Froxlor's own mailer
- Safe dry runs and single-certificate execution
- Optional cron and logrotate installation

## Requirements

- PHP 7.4 or newer with PDO MySQL and DNS support
- Froxlor v2 with a readable `lib/userdata.inc.php`
- Froxlor's `acme.sh` installation and HTTP challenge path
- `root`, or an equivalent trusted administrative account
- Network access for DNS resolution and ACME challenges

The execution account must be able to read Froxlor's configuration, write the certificate and lock paths, and run every configured post-command.

## Quick start

Place the project in `/opt/AcmeSan`, then create the machine-local configuration:

```sh
cd /opt/AcmeSan
cp acme.local.example.php acme.local.php
chmod 600 acme.local.php
```

Review `acme.local.php`, especially the complete `certificates` map and its service reload commands. The local file should remain readable only by its administrator.

Check the command and effective domain sets without creating certificates:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --help
/usr/bin/php /opt/AcmeSan/acme.php --dry-run
```

Optionally verify Froxlor mail delivery:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --dry-run --test-email
```

Run one monitored, non-forced certificate cycle before installing the scheduler:

```sh
/usr/bin/php /opt/AcmeSan/acme.php
```

A current certificate with an unchanged domain set is skipped normally and returns `0`.
Do not add `--force` to routine or scheduled executions.

## Local configuration

The script automatically loads `acme.local.php` beside `acme.php` when it exists. To require another file, use an absolute path:

```sh
/usr/bin/php /opt/AcmeSan/acme.php \
  --dry-run \
  --local-config=/path/to/acme.local.php
```

Configuration is applied in this order, with later sources taking precedence:

1. General defaults in `acme.php`
2. Froxlor ACME and mail settings
3. `acme.local.php`
4. Command-line operational flags

`froxlor_config` is resolved earlier because it is needed to connect to the database. Its precedence is the built-in path, the local value, then `--froxlor-config=PATH`.

See [acme.local.example.php](acme.local.example.php) for every supported field. Unknown fields and invalid values are rejected. Scalar values replace defaults; `acme` and `email` merge by key; lists replace rather than append.

Important: supplying `certificates` replaces the entire built-in certificate map. Include every certificate type that should remain active.

### Certificate definitions

A certificate definition has this shape:

```php
'webmail' => [
    'subdomains'                  => ['webmail'],
    'include_hostname_subdomains' => true,
    'additional_domains'          => [],
    'post_command'                => 'systemctl restart apache2',
],
```

For a server named `froxlor.example.net`, this definition starts with:

```text
froxlor.example.net
webmail.froxlor.example.net
```

The bare server hostname is always the first SAN.
`include_hostname_subdomains` defaults to `false`. Enable it only when each `<prefix>.<server-hostname>` is a real service name with working DNS.

Each prefix is also applied to every active primary customer domain. If Froxlor contains `customer.example`, the definition above adds `webmail.customer.example`.

`additional_domains` contains extra base domains, not complete service names. For example:

```php
'subdomains'         => ['mail'],
'additional_domains' => ['external.example'],
```

adds `mail.external.example`. Supplying `mail.external.example` would incorrectly produce `mail.mail.external.example`.

Duplicate names are removed while preserving order.

## DNS validation

Before calling `acme.sh`, the script retains only names whose public A or AAAA records match an address detected on the server. Create DNS records before enabling a new hostname or customer-domain service name.

A failed name is excluded so valid names can still be processed, but it is also recorded as an operational error. The run therefore returns `1` and may send an error notification.
Use `--skip-dns-validation` only for deliberate troubleshooting, never in the scheduled command.

## Running one certificate

Use `--certificate=TYPE` to inspect or run one definition:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --dry-run --certificate=webmail
/usr/bin/php /opt/AcmeSan/acme.php --certificate=webmail
```

When the requested SAN set differs from the stored certificate, `acme.sh` proceeds even if normal renewal is not yet due. `--force` is therefore unnecessary when adding or removing domains.

Use a forced run only during a monitored service window when you deliberately need a new unchanged certificate:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --force --certificate=webmail
```

## Certificate output and service reloads

Certificates default to `/etc/ssl/acme-sans/<type>/`:

```text
cert.pem
key.pem
fullchain.pem
```

After successful issuance, the directory is set to mode `0700` and the three PEM files to `0600`. The definition's post-command is then eligible to run. Identical post-commands are deduplicated and run once after all certificate attempts.

A certificate definition does not schedule its post-command when its issuance is skipped or fails. Post-commands are trusted root-level shell commands and must never contain untrusted input.

## Notifications

The script sends error reports to Froxlor's configured `panel.adminmail` address using `Froxlor\System\Mailer`. Froxlor remains responsible for the sender, Reply-To address, SMTP settings, authentication, and encryption.

Normal dry runs do not send mail.
`--dry-run --test-email` sends one clearly labelled test message, while `--no-email` disables notifications for one run. Those two options cannot be combined.

## Scheduling and logs

Run the installer only after the project is in its final location. Preview it first:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --install --dry-run
/usr/bin/php /opt/AcmeSan/acme.php --install
```

Preview does not require `root`, installation does.
The installer uses the current `acme.php` location, prefers `/usr/bin/php`.
It does not copy project files, load Froxlor, or run certificate logic.

It safely creates or updates only files bearing its managed marker and refuses symbolic links, unmanaged targets, or differently-cased duplicate filenames.

The generated `/etc/cron.d/acme-san` is:

```cron
# Managed by AcmeSan --install
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
MAILTO=root

# Daily ACME SAN certificate check
17 3 * * * root '/usr/bin/php' '/opt/AcmeSan/acme.php' >> /var/log/acme-san.log 2>&1
```

Daily execution is intentional: unchanged certificates are skipped, while domain changes and transient failures are discovered promptly.
Routine output and failures are redirected to the log.
`MAILTO=root` remains a final fallback if the shell cannot establish that redirection.

The generated `/etc/logrotate.d/acme-san` is:

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

Verify the installation:

```sh
ls -l /etc/cron.d/acme-san /etc/logrotate.d/acme-san
systemctl is-active cron
logrotate --debug /etc/logrotate.d/acme-san
```

Both managed files should be owned by `root:root` with mode `0644`.
A missing `/var/log/acme-san.log` is normal until the first redirected execution.

## Timezone

Before its first timestamped message, the script attempts to detect the operating-system timezone from `/etc/timezone`, the `/etc/localtime` zoneinfo link, then `timedatectl`.
It applies only a timezone identifier recognized by PHP and otherwise retains PHP's configured timezone. Named zones preserve daylight-saving transitions correctly.

## Options and exit statuses

Run `php acme.php --help` for the authoritative option list.

| Status | Meaning |
| --- | --- |
| `0` | Success, including normal renewal skips |
| `1` | Operational failure, including DNS, ACME, post-command, or test-mail failure |
| `2` | Invalid command-line option or local configuration |

`acme.sh` own status `2` means renewal is not currently required. The wrapper classifies that as a normal skip and exits with `0` unless another operational error occurred.

## Troubleshooting

- **Configuration error:** Check that the local file returns an array and correct the exact unknown or invalid field reported.
- **Froxlor or database error:** Verify `userdata.inc.php`, its permissions, database credentials, and the PDO MySQL extension.
- **DNS validation error:** Compare the public `A` and `AAAA` answers with the server addresses printed by the script.
- **Lock error:** Another non-dry-run instance may be active. Inspect the configured lock file and process; never remove it while the process is running.
- **ACME failure:** Review the logged, shell-quoted command and `acme.sh` output, then verify the challenge webroot and CA configuration.
- **Notification failure:** Verify `panel.adminmail`, Froxlor's mail settings, and access to `vendor/autoload.php` and `lib/tables.inc.php`.
- **Permission or reload failure:** Verify that the execution user can write the configured paths and run every post-command.

## Security checklist

- Keep `acme.local.php` mode `0600`.
- Run the script and scheduler as a trusted administrative account.
- Review every post-command as root-level code.
- Start with `--dry-run` after configuration changes.
- Verify certificate SANs, PEM permissions, service state, exit status, and notifications after live issuance.
- Keep each SAN set within the current limits of the configured certificate authority; the script does not split oversized certificates.

## License

BSD 3-Clause. See [LICENSE](LICENSE).
