# Froxlor ACME SAN

Give your mail, webmail, and DAV services proper certificates for every customer domain on your [Froxlor](https://www.froxlor.org) server, without managing them by hand.

Froxlor already issues certificates for websites. This script covers the service names around them: `mail.customer.example`, `webmail.customer.example`, and so on. For each service you define, it builds one Subject Alternative Name (SAN) certificate that covers all of your active customer domains, issues it with Froxlor's own [acme.sh](https://github.com/acmesh-official/acme.sh) setup, and reloads the service when the certificate changes.

What you get:

- Active primary domains are picked up from Froxlor automatically
- One certificate and one reload command per service
- DNS checks (A and AAAA) before anything is sent to the certificate authority
- Renewal when a certificate is due, or as soon as its domain list changes
- Error reports by email, through Froxlor's own mail settings
- Dry runs and single-certificate runs, so you can look before you leap
- Optional cron and logrotate setup

## Before you start

You'll need:

- PHP 7.4 or newer, with PDO MySQL and DNS support
- Froxlor v2, with a readable `lib/userdata.inc.php`
- Froxlor's `acme.sh` installation and HTTP challenge path
- `root`, or an equally trusted administrative account
- Network access for DNS lookups and ACME challenges

The account that runs the script must be able to read Froxlor's configuration, write the certificate and lock paths, and run every reload command you configure.

## Quick start

Put the project in `/opt/AcmeSan` and create your local configuration from the example:

```sh
cd /opt/AcmeSan
cp acme.local.example.php acme.local.php
chmod 600 acme.local.php
```

Open `acme.local.php` and review it, paying particular attention to the `certificates` section and the reload commands. Keep the file readable only by its administrator.

Next, see what the script would do. A dry run doesn't create any certificates:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --help
/usr/bin/php /opt/AcmeSan/acme.php --dry-run
```

If you'd like to confirm that email notifications arrive, send a test message:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --dry-run --test-email
```

When the output looks right, run it once for real and watch the result:

```sh
/usr/bin/php /opt/AcmeSan/acme.php
```

Once that works, [schedule it](#scheduling-and-logs) and you're done.

> **Tip:** if a certificate is still current and its domains haven't changed, the script simply skips it and exits with `0`. There's no need for `--force` in routine or scheduled runs.

## Configuration

### Where settings come from

The script loads `acme.local.php` from its own directory if the file exists. To use a different file, pass an absolute path:

```sh
/usr/bin/php /opt/AcmeSan/acme.php \
  --dry-run \
  --local-config=/path/to/acme.local.php
```

Settings are applied in this order, and later ones win:

1. Built-in defaults in `acme.php`
2. Froxlor's ACME and mail settings
3. Your `acme.local.php`
4. Command-line flags

The one exception is `froxlor_config`, which is needed to reach the database and so is resolved first: the built-in path, then your local value, then `--froxlor-config=PATH`.

[acme.local.example.php](acme.local.example.php) lists every supported setting. A few things to keep in mind:

- Unknown settings and invalid values are rejected, so typos won't go unnoticed.
- Single values replace the defaults. `acme` and `email` are merged key by key, and lists replace the defaults rather than adding to them.
- **If you define `certificates`, you replace the whole built-in list.** Include every certificate you want to keep.

### Defining a certificate

Each entry under `certificates` describes one service certificate:

```php
'webmail' => [
    'subdomains'                  => ['webmail'],
    'include_hostname_subdomains' => true,
    'additional_domains'          => [],
    'post_command'                => 'systemctl restart apache2',
],
```

Here's what ends up in the certificate on a server named `froxlor.example.net`:

```text
froxlor.example.net             ← the server hostname, always first
webmail.froxlor.example.net     ← because include_hostname_subdomains is true
webmail.customer.example        ← one for each active customer domain
```

- **`subdomains`** are the prefixes added to each domain.
- **`include_hostname_subdomains`** (default `false`) also adds each prefix to the server hostname. Only turn it on if names like `webmail.froxlor.example.net` really exist and have working DNS.
- **`additional_domains`** adds extra *base* domains that aren't in Froxlor. With `'subdomains' => ['mail']` and `'additional_domains' => ['external.example']`, you get `mail.external.example`. Don't include the prefix yourself: `mail.external.example` would turn into `mail.mail.external.example`.
- **`post_command`** runs after the certificate is renewed, usually to reload the service.
- **`group`** (optional) lets a non-root service read the certificate. See [Letting a service read its certificate](#letting-a-service-read-its-certificate).

Duplicate names are removed automatically, and the original order is kept.

## DNS checks

Before contacting the certificate authority, the script checks that each name's public A or AAAA record points at this server. That way a single missing DNS record can't make the whole certificate fail.

Names that don't pass are left out so the rest can still be issued. They're still reported as errors, though, so the run exits with `1` and you'll get an email. Set up the DNS records before you add a new service name or customer domain.

`--skip-dns-validation` is there for troubleshooting only. Don't use it in the scheduled command.

## Working with one certificate

Use `--certificate=TYPE` to look at or run a single definition:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --dry-run --certificate=webmail
/usr/bin/php /opt/AcmeSan/acme.php --certificate=webmail
```

You don't need `--force` when you add or remove domains. acme.sh notices that the domain list changed and issues a new certificate on its own.

Use `--force` only when you really do want a fresh copy of an unchanged certificate, and preferably during a maintenance window:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --force --certificate=webmail
```

## Certificates and reloads

By default, certificates are stored in `/etc/ssl/acme-sans/<type>/`:

```text
cert.pem
key.pem
fullchain.pem
```

After every real run, including runs where renewal wasn't needed, the directory is set to `0700` and the files to `0600`, so only root can read them.

When a certificate is issued or renewed, its `post_command` runs once all certificates have been processed. If several certificates share the same command, it runs only once. When a certificate is skipped or fails, its command isn't run.

Reload commands run as root in a shell, so never put untrusted input in them.

### Letting a service read its certificate

Some services don't run as root and need to read their certificate and key themselves. For those, add a `group`:

```php
'webmail' => [
    'subdomains'   => ['webmail'],
    'post_command' => 'systemctl restart apache2',
    'group'        => 'www-cp-local',
],
```

For that certificate only, the directory and files are given to the group, with modes `0750` and `0640`. Every other certificate stays root-only.

Good to know:

- The group must already exist. If it doesn't, the script stops before doing any certificate work.
- Adding or removing `group` takes effect on the next run. You don't have to wait for a renewal.
- If the permissions can't be set, it's reported as an error, just like any other failure.

## Email notifications

When something goes wrong, the script emails Froxlor's admin address (`panel.adminmail`) using Froxlor's own mailer. Froxlor's settings determine the sender, Reply-To address, SMTP server, authentication, and encryption, so there's nothing extra to configure.

- A normal dry run never sends mail.
- `--dry-run --test-email` sends one clearly labelled test message.
- `--no-email` turns notifications off for one run. It can't be combined with `--test-email`.

## Scheduling and logs

Once the project is in its final location, let the script set up its own cron job and log rotation. Preview first, then install:

```sh
/usr/bin/php /opt/AcmeSan/acme.php --install --dry-run
/usr/bin/php /opt/AcmeSan/acme.php --install
```

The preview works for any user. The install itself needs `root`.

The installer uses the current location of `acme.php` and prefers `/usr/bin/php`. It doesn't copy files, load Froxlor, or touch any certificates.

It's careful about what it overwrites. It only updates files that carry its own "managed" marker, and refuses symbolic links, files it didn't create, and look-alike filenames that differ only in case.

Here's the `/etc/cron.d/acme-san` it creates:

```cron
# Managed by AcmeSan --install
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
MAILTO=root

# Daily ACME SAN certificate check
17 3 * * * root '/usr/bin/php' '/opt/AcmeSan/acme.php' >> /var/log/acme-san.log 2>&1
```

Running daily is deliberate. Unchanged certificates are skipped quickly, while new domains and temporary failures are picked up within a day.

Output goes to the log file. `MAILTO=root` is only a fallback in case the shell can't write to the log.

And here's `/etc/logrotate.d/acme-san`:

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

To check the installation:

```sh
ls -l /etc/cron.d/acme-san /etc/logrotate.d/acme-san
systemctl is-active cron
logrotate --debug /etc/logrotate.d/acme-san
```

Both files should be owned by `root:root` with mode `0644`. Don't worry if `/var/log/acme-san.log` doesn't exist yet. It's created on the first scheduled run.

## Timezone

Log timestamps use the server's timezone. The script looks for it in `/etc/timezone`, then the `/etc/localtime` link, then `timedatectl`. It only applies a timezone that PHP recognises and otherwise keeps PHP's own setting. Named zones handle daylight saving time correctly.

## Options and exit codes

Run `php acme.php --help` for the full list of options.

| Exit code | Meaning |
| --- | --- |
| `0` | Success, including certificates that didn't need renewing |
| `1` | Something failed: DNS, ACME, permissions, a reload command, or the test email |
| `2` | Invalid command-line option or local configuration |

When acme.sh says renewal isn't needed (its own exit code `2`), the script treats that as a normal skip, not an error.

## Troubleshooting

- **Configuration error:** make sure the local file returns an array, then fix the setting named in the message.
- **Froxlor or database error:** check `userdata.inc.php`, its permissions, the database credentials, and that the PDO MySQL extension is installed.
- **DNS check failed:** compare the domain's public `A` and `AAAA` records with the server addresses shown in the output.
- **Lock error:** another run may still be in progress. Check the lock file and the process, and never delete the lock while the process is running.
- **ACME failure:** the log shows the exact acme.sh command and its output. Check the challenge webroot and the CA setting.
- **No email received:** check `panel.adminmail`, Froxlor's mail settings, and that `vendor/autoload.php` and `lib/tables.inc.php` are readable.
- **Permission or reload failure:** make sure the account running the script can write the configured paths and run every reload command, and that any configured `group` exists.

## Security checklist

- Keep `acme.local.php` at mode `0600`.
- Run the script and the scheduler as a trusted administrative account.
- Treat every `post_command` as root-level code.
- Do a `--dry-run` after every configuration change.
- Set `group` only on certificates a non-root service has to read, and use a dedicated group.
- After a real run, check the certificate's names, file permissions, service status, exit code, and notifications.
- Keep each certificate within your certificate authority's name limit. The script doesn't split large certificates.

## License

BSD 3-Clause. See [LICENSE](LICENSE).
