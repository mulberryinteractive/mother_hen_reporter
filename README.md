# Mother Hen Reporter

A small Drupal module that reports a site's installed Drupal core, module, theme and Composer package versions to a [Mother Hen](#about-mother-hen) security dashboard. The dashboard matches those versions against Drupal security advisories.

The module only pushes outbound. It adds no routes, and nothing can query the site for its versions. Each report is signed with a private key that stays on the site.

## What is sent

Each report is a JSON document containing:

| Field | Contents |
|---|---|
| `site`, `environment` | The site ID assigned by the dashboard and the environment name |
| `drupal.core_version`, `php_version` | Drupal core and PHP versions |
| `packages` | Every installed Composer package: name, version, source reference (commit), type, whether it is a dev requirement. The root project is excluded. |
| `extensions` | Every available module and theme: machine name, drupal.org project, version, whether it is installed |
| `patches` | The contents of `patches.lock.json` (composer-patches 2.x), if present |
| `captured_at`, `reporter_version`, `schema` | Report metadata |

The module never sends content, users, configuration, credentials or file paths. To see exactly what would be sent, run `drush mother-hen:payload`.

## When reports are sent

- During cron, if the inventory has changed since the last accepted report, or if an hour has passed since then.
- Only when the module is configured (see below).
- From any environment that has the settings. Each report names its environment:
  - on Upsun or Platform.sh, the environment's branch name (`PLATFORM_BRANCH`);
  - elsewhere, the `environment` setting, which defaults to `production`.

  Each environment is stored separately. The dashboard keeps reports only from the environments monitored for that site (its production environment plus any others listed, wildcards allowed). It lists other environments that report, so you can add them.
- Only over HTTPS.

If a report fails, the next cron run retries it. The **Status report** page (`/admin/reports/status`) shows the last result.

## Requirements

- Drupal 10.3 or later, or Drupal 11.
- PHP 8.1 or later with the `sodium` extension, which is bundled with PHP by default.
- Composer 2.
- Cron running at least daily. The dashboard flags a site as stale once its last report is older than that site's threshold, which defaults to 26 hours.
- Outbound HTTPS from the site to the dashboard.

## Installation

The module is not on drupal.org. Add its Git repository to the site's `composer.json`:

```sh
composer config repositories.mother_hen_reporter vcs https://github.com/mulberryinteractive/mother_hen_reporter
composer require mulberryinteractive/mother_hen_reporter
drush pm:install mother_hen_reporter
```

## Setup

### 1. Add the site to the dashboard

On the dashboard, open **Sites → Add site**, then save. Copy the **site ID** from the sites list.

### 2. Generate a key pair

Run this anywhere the module is installed, for example a local copy of the site:

```sh
drush mother-hen:keygen
```

It prints a **public key** and a **private key**.

- Paste the public key into the site's **Reporter public key** field on the dashboard.
- Store the private key as a secret on the production environment only, as in the next step. Never commit it and never put it in configuration.

Each site should have its own key pair.

### 3. Store the secrets on production

**Platform.sh / Upsun.** Create environment-level variables on the production environment, here `main`, and make the private key sensitive. Whether they are inherited decides which environments can report:

- `--inheritable true`: child environments, such as staging and previews, inherit them and report too. The dashboard keeps only the environments you monitor.
- `--inheritable false`: only production reports.

```sh
upsun variable:create -l environment -e main --prefix env: --name MOTHER_HEN_SITE_ID \
  --value 'SITE-ID-FROM-DASHBOARD' --inheritable true
upsun variable:create -l environment -e main --prefix env: --name MOTHER_HEN_PRIVATE_KEY \
  --value 'PRIVATE-KEY-FROM-KEYGEN' --sensitive true --inheritable true
```

Every environment that receives the private key can sign reports for this site. Only let it reach environments built from branches your team controls.

Older Platform.sh projects use the `platform` CLI instead of `upsun`. Check `platform variable:create --help` for its flags.

**AWS and other hosts.** Provide `MOTHER_HEN_SITE_ID` and `MOTHER_HEN_PRIVATE_KEY` to PHP as environment variables on each environment that should report, using whatever secret mechanism the host provides. Set `environment` in `settings.php` for each environment, for example from another environment variable.

### 4. Configure `settings.php`

```php
$settings['mother_hen_reporter'] = [
  'endpoint' => 'https://YOUR-DASHBOARD/api/mother-hen/inventory',
  'site_id' => getenv('MOTHER_HEN_SITE_ID') ?: NULL,
  'private_key' => getenv('MOTHER_HEN_PRIVATE_KEY') ?: NULL,
  // Optional. Defaults to the Upsun/Platform.sh branch name, otherwise
  // 'production'. Must be the site's production environment, or another
  // environment monitored for the site, on the dashboard.
  // 'environment' => getenv('APP_ENV') ?: 'production',
];
```

When the variables are absent, as on local development sites, the module does nothing.

### 5. Check it works

On production:

```sh
drush mother-hen:report     # Sends a report now and prints the result.
```

Then check **Status report → Mother Hen reporter** on the site, and the site's **Last report** time on the dashboard.

## Drush commands

| Command | Purpose |
|---|---|
| `mother-hen:keygen` | Generate a key pair and print the settings to add |
| `mother-hen:payload` | Print the JSON payload that would be sent |
| `mother-hen:report` | Send a report now, ignoring the schedule |

## Rotating a key

1. Run `drush mother-hen:keygen`.
2. Paste the new public key into the dashboard.
3. Replace the `MOTHER_HEN_PRIVATE_KEY` secret on production.

Reports signed with the old key are refused once the dashboard has the new public key, so update both sides together.

## Security notes

- **The signature binds the request.** Each request carries the site ID, a timestamp and a random nonce, signed with Ed25519 together with the SHA-256 of the body. The dashboard rejects:
  - bad signatures;
  - timestamps more than 5 minutes off;
  - reused nonces;
  - bodies over 2 MB.

  Valid reports from environments the site does not monitor are accepted but discarded.
- **The dashboard stores only public keys.** A leak of the dashboard's database cannot be used to forge reports.
- **Reports are as accurate as installed versions.** A module patched without changing its version still reports the original version. composer-patches 2.x users get `patches.lock.json` included, so patched packages are visible.

## Request format

```
POST /api/mother-hen/inventory
Content-Type: application/json
X-Mother-Hen-Site: <site UUID>
X-Mother-Hen-Timestamp: <unix seconds>
X-Mother-Hen-Nonce: <32 lowercase hex characters>
X-Mother-Hen-Signature: base64(Ed25519-sign(message))

message = "mother-hen-v1\n{site}\n{timestamp}\n{nonce}\n{sha256_hex(body)}"
```

The dashboard answers `202` when it accepts a report. Other responses:

- `400`: the body is not JSON.
- `401`: the signature, timestamp or nonce check failed.
- `413`: the body is too large.
- `422`: the payload is invalid, for example the environment doesn't match.
- `429`: too many failed attempts.

The unit test `tests/src/Unit/SignerTest.php` contains a fixed test vector. The dashboard's tests check the same vector, so any change to the scheme breaks one side's tests.

## Development

This repository uses [DDEV](https://ddev.com) with the [ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib) add-on, which builds a Drupal site around the module in `web/` (git-ignored):

```sh
ddev start
ddev poser             # Install Drupal core and dev dependencies.
ddev symlink-project   # Link the module into web/modules/custom.
ddev phpunit           # Run the tests.
ddev phpcs             # Check coding standards.
```

To test against a local dashboard, install a site with `ddev drush site:install minimal -y`, enable the module, and configure it in `web/sites/default/settings.php`. The dashboard's DDEV web container is reachable at `http://ddev-mother-hen-web`, so plain HTTP must be allowed explicitly:

```php
$settings['mother_hen_reporter'] = [
  'endpoint' => 'http://ddev-mother-hen-web/api/mother-hen/inventory',
  'site_id' => '…',
  'private_key' => '…',
  // Development only.
  'allow_insecure_endpoint' => TRUE,
];
```

Test with Drupal 10 by running `ddev core-version '~10.3.0'`, then `ddev poser`.

## About Mother Hen

Mother Hen is a Drupal-based dashboard that collects Drupal security advisories and shows which managed sites are affected and what needs attention first.

## License

GPL-2.0-or-later.
